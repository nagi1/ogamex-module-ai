<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
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
