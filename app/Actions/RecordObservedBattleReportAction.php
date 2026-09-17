<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiCampaignConsultationTrigger;
use Modules\AI\Enums\AiCampaignState;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiCampaignConsultationSignal;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use OGame\Models\BattleReport;

/**
 * Reduces one committed battle report into observations for the AIs that took part.
 *
 * The report is the legal visibility boundary: it is the same stored row every
 * participant can already read in game, and it names the planet owner and the attacking
 * player. Nothing else about the battle is read, and a player who was not one of those
 * two is never given an observation.
 */
class RecordObservedBattleReportAction
{
    /**
     * A campaign consults after this many faction fleet losses: the second defeat is the
     * moment an experienced player stops repeating the same defence and reconsiders.
     */
    private const REPEATED_SETBACK_LOSSES = 2;

    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(int $battleReportId): int
    {
        /** @var BattleReport|null $battleReport */
        $battleReport = BattleReport::query()->find($battleReportId);

        if ($battleReport === null) {
            return 0;
        }

        $defenderPlayerId = $this->playerId($battleReport->planet_user_id);
        $attackerPlayerId = $this->playerId($this->attackerSide($battleReport)['player_id'] ?? null);

        // A report naming one side only, or the same player twice, is a shape this
        // reducer does not understand; it declines instead of guessing who was harmed.
        if ($defenderPlayerId === null || $attackerPlayerId === null || $defenderPlayerId === $attackerPlayerId) {
            return 0;
        }

        return DB::transaction(fn (): int => $this->recordObservations(
            $battleReport,
            $defenderPlayerId,
            $attackerPlayerId,
        ));
    }

    private function recordObservations(BattleReport $battleReport, int $defenderPlayerId, int $attackerPlayerId): int
    {
        $this->signalCampaignEvents($battleReport, $defenderPlayerId, $attackerPlayerId);

        /** @var array<int, int> $counterparties Each participant maps to the player it faced. */
        $counterparties = [
            $defenderPlayerId => $attackerPlayerId,
            $attackerPlayerId => $defenderPlayerId,
        ];

        $participants = AiProfile::query()
            ->where('enabled', true)
            ->whereIn('player_id', array_keys($counterparties))
            ->pluck('player_id')
            ->map(fn (mixed $playerId): int => (int) $playerId)
            ->all();

        $recorded = 0;

        foreach ($participants as $playerId) {
            // The unique source identity makes retrying an after-commit callback safe.
            $observation = AiObservation::query()->firstOrCreate([
                'player_id' => $playerId,
                'source_type' => AiObservationSource::BattleReport,
                'source_id' => $battleReport->id,
            ], [
                'kind' => AiObservationKind::BattleReportObserved,
                'subject_player_id' => $counterparties[$playerId],
                'source_time' => $battleReport->created_at ?? $this->clock->now(),
                'observed_at' => $this->clock->now(),
            ]);

            if (!$observation->wasRecentlyCreated) {
                continue;
            }

            app(AppraiseObservedBattleReportAction::class)->handle($observation->id);

            $recorded++;
        }

        return $recorded;
    }

    /**
     * Turns a committed battle into its campaign signals: coalition infighting when both sides
     * are coalition, and a faction fleet loss — cascading to a repeated setback — when the one
     * faction side was wiped out. Both stay campaign-scoped and are consumed by the next faction
     * session decision, never here.
     */
    private function signalCampaignEvents(BattleReport $battleReport, int $defenderPlayerId, int $attackerPlayerId): void
    {
        $factionIds = AiProfile::query()
            ->where('enabled', true)
            ->whereIn('player_id', [$defenderPlayerId, $attackerPlayerId])
            ->pluck('player_id')
            ->map(fn (mixed $playerId): int => (int) $playerId)
            ->all();

        $attackerIsFaction = in_array($attackerPlayerId, $factionIds, true);
        $defenderIsFaction = in_array($defenderPlayerId, $factionIds, true);
        $at = CarbonImmutable::instance($battleReport->created_at ?? $this->clock->now());

        if (!$attackerIsFaction && !$defenderIsFaction) {
            $this->signalActiveCampaigns(AiCampaignConsultationTrigger::CoalitionConflict, $at);

            return;
        }

        // A faction-faction battle is ordinary play, never a campaign fleet loss.
        if ($attackerIsFaction === $defenderIsFaction) {
            return;
        }

        if (!$this->sideWiped($battleReport, $attackerIsFaction ? 'attacker' : 'defender')) {
            return;
        }

        $this->signalFleetLoss($at);
    }

    private function signalFleetLoss(CarbonImmutable $at): void
    {
        foreach (AiCampaign::query()->where('state', AiCampaignState::Active)->get(['id']) as $campaign) {
            app(RecordCampaignConsultationSignalAction::class)->handle(
                $campaign->id,
                AiCampaignConsultationTrigger::FleetLoss,
                $at,
            );

            // ponytail: the counter reads FleetLoss signals, so losses that pile up before a
            // session consumes the open signal collapse into one row and the count can read
            // low. OGame defeats are minutes apart at the fastest and a session follows each,
            // so the undercount has no practical ceiling worth a dedicated counter table.
            $losses = AiCampaignConsultationSignal::query()
                ->where('campaign_id', $campaign->id)
                ->where('trigger', AiCampaignConsultationTrigger::FleetLoss->value)
                ->count();

            if ($losses >= self::REPEATED_SETBACK_LOSSES) {
                app(RecordCampaignConsultationSignalAction::class)->handle(
                    $campaign->id,
                    AiCampaignConsultationTrigger::RepeatedSetback,
                    $at,
                );
            }
        }
    }

    private function signalActiveCampaigns(AiCampaignConsultationTrigger $trigger, CarbonImmutable $at): void
    {
        foreach (AiCampaign::query()->where('state', AiCampaignState::Active)->pluck('id') as $campaignId) {
            app(RecordCampaignConsultationSignalAction::class)->handle($campaignId, $trigger, $at);
        }
    }

    /**
     * The faction side was wiped out when the last round leaves it zero ships and the opposing
     * side survivors. An empty round list is an uncontested arrival, not a fleet loss.
     */
    private function sideWiped(BattleReport $battleReport, string $side): bool
    {
        $rounds = is_array($battleReport->rounds) ? $battleReport->rounds : [];

        if ($rounds === []) {
            return false;
        }

        $lastRound = $rounds[array_key_last($rounds)];
        $attacker = $this->shipAmount($lastRound['attacker_ships'] ?? null);
        $defender = $this->shipAmount($lastRound['defender_ships'] ?? null);

        return $side === 'attacker'
            ? $attacker === 0 && $defender > 0
            : $defender === 0 && $attacker > 0;
    }

    private function shipAmount(mixed $units): int
    {
        $total = 0;

        if (!is_array($units)) {
            return $total;
        }

        foreach ($units as $amount) {
            $total += is_numeric($amount) ? (int) $amount : 0;
        }

        return $total;
    }

    /** @return array<string, mixed> */
    private function attackerSide(BattleReport $battleReport): array
    {
        return is_array($battleReport->attacker) ? $battleReport->attacker : [];
    }

    private function playerId(mixed $value): int|null
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
