<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Persona\PersonaTaste;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\RandomSource;
use OGame\Models\BattleReport;
use OGame\Models\ChatMessage;
use OGame\Models\Planet;
use OGame\Models\User;

/**
 * Lets an alliance member say what is happening in the alliance channel, the way members of a real club do: "X is
 * hitting me at 2:45:7, anyone in range?" when they are hit, "Y just got hit by X" when a mate is. Until now the
 * channel was reply-only, so a world with 900 mates under attack carried no alliance chat at all. It promises nothing
 * (the defend planner decides on its own), and it stays sparse: a fresh event, a member who has not just spoken, and
 * one event line per alliance per window.
 */
class SpeakInAllianceChannelAction
{
    private const FRESH_HOURS = 2;

    private const SPEAKER_QUIET_HOURS = 3;

    private const ALLIANCE_QUIET_MINUTES = 45;

    /** A target already called in the channel is not called again inside this window. */
    private const STRIKE_QUIET_HOURS = 6;

    /** A raid is shared only when it is the best of this many hours. */
    private const BEST_RAID_WINDOW_HOURS = 24;

    /** A member below this sociability does not type in the channel unprompted. */
    private const SOCIABILITY_FLOOR = 0.3;

    public function handle(int $playerId, CarbonImmutable $now): int
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        $allianceId = $profile === null ? null : User::query()->whereKey($playerId)->value('alliance_id');
        if ($allianceId === null || PersonaTaste::fromSeed((int) $profile->random_seed, app(RandomSource::class))->sociability < self::SOCIABILITY_FLOOR || $this->recentlySpoke($playerId, (int) $allianceId, $now)) {
            return 0;
        }

        $hit = $this->latestHit($playerId, AiObservationKind::BattleReportObserved, $now) ?? $this->latestHit($playerId, AiObservationKind::AllyUnderAttack, $now);
        if ($hit === null) {
            return $this->shareBestRaid($profile, (int) $allianceId, $now) ? 1 : 0;
        }

        [$observation, $report] = $hit;
        $victimId = (int) $report->planet_user_id;
        $names = User::query()->whereIn('id', [$victimId, (int) $observation->subject_player_id])->pluck('username', 'id');
        $key = $victimId === $playerId ? 'ally_hit.victim' : 'ally_hit.witness';
        $line = app(BuildAuthoredSocialReplyAction::class)->line($profile, $key, (string) $observation->id, [
            'victim' => (string) ($names[$victimId] ?? ''),
            'attacker' => (string) ($names[(int) $observation->subject_player_id] ?? ''),
            'coords' => "{$report->planet_galaxy}:{$report->planet_system}:{$report->planet_position}",
        ]);

        return $line !== null && app(DeliverAiAllianceReplyAction::class)->handle($playerId, (int) $allianceId, $line) !== null ? 1 : 0;
    }

    /**
     * A player posts the hit they are proud of: in the community threads the hit report is the most common post, and
     * the club answers it with a "gg". Only the best raid of the last day is worth a line, so a farm run is not narrated.
     */
    private function shareBestRaid(AiProfile $profile, int $allianceId, CarbonImmutable $now): bool
    {
        $playerId = (int) $profile->player_id;
        $observations = AiObservation::query()->where('player_id', $playerId)->where('kind', AiObservationKind::BattleReportObserved)
            ->where('source_time', '>', $now->subHours(self::BEST_RAID_WINDOW_HOURS))->latest('id')->get();
        $worth = static fn (BattleReport $report): float => (float) ($report->loot['metal'] ?? 0) + 1.5 * (float) ($report->loot['crystal'] ?? 0) + 2.0 * (float) ($report->loot['deuterium'] ?? 0);
        $raids = BattleReport::query()->whereIn('id', $observations->pluck('source_id'))->where('planet_user_id', '!=', $playerId)->get();
        $latest = $observations->first(static fn (AiObservation $observation): bool => $raids->contains('id', $observation->source_id));
        $report = $latest === null ? null : $raids->firstWhere('id', $latest->source_id);
        if ($report === null || $latest->source_time < $now->subHours(self::FRESH_HOURS) || $worth($report) <= 0 || $worth($report) < $raids->max($worth)) {
            return false;
        }

        $line = app(BuildAuthoredSocialReplyAction::class)->line($profile, 'ally_win', (string) $latest->id, [
            'victim' => (string) User::query()->whereKey($report->planet_user_id)->value('username'),
            'coords' => "{$report->planet_galaxy}:{$report->planet_system}:{$report->planet_position}",
            'loot' => number_format((int) $worth($report)),
        ]);

        return $line !== null && app(DeliverAiAllianceReplyAction::class)->handle($playerId, $allianceId, $line) !== null;
    }

    /**
     * The words that go with a fleet in the air: a member who has just sent ships to hold at a mate's planet says so, once
     * per mission, so what the channel reads is what the radar shows. It does not wait on the quiet windows above.
     */
    public function announceDefend(int $playerId, int $targetPlanetId, int $ships): bool
    {
        $target = Planet::query()->find($targetPlanetId);

        return $target !== null && $ships > 0 && $this->say($playerId, 'ally_defend', $targetPlanetId . ':' . $ships, [
            'victim' => (string) User::query()->whereKey($target->user_id)->value('username'),
            'coords' => "{$target->galaxy}:{$target->system}:{$target->planet}",
            'ships' => (string) $ships,
        ]);
    }

    /** The same for an attack flown with the club: opening an ACS for the mates, or joining one already flying. */
    public function announceStrike(int $playerId, string $key, int $missionId, string $targetName, string $coords): bool
    {
        // A leader posts the call once per target (9509 to 9511: the same coordinates called eight times in an evening);
        // a farm flown by many unions in a row is one call and then silence.
        $allianceId = User::query()->whereKey($playerId)->value('alliance_id');
        if ($allianceId !== null && ChatMessage::query()->where('alliance_id', $allianceId)->where('created_at', '>', now()->subHours(self::STRIKE_QUIET_HOURS))
            ->where('message', 'like', '%' . $coords . '%')->exists()) {
            return false;
        }

        return $this->say($playerId, $key, (string) $missionId, ['target' => $targetName, 'coords' => $coords]);
    }

    /** @param array<string, string> $vars */
    private function say(int $playerId, string $key, string $salt, array $vars): bool
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        $allianceId = $profile === null ? null : User::query()->whereKey($playerId)->value('alliance_id');
        $line = $allianceId === null ? null : app(BuildAuthoredSocialReplyAction::class)->line($profile, $key, $salt, $vars);

        return $line !== null && app(DeliverAiAllianceReplyAction::class)->handle($playerId, (int) $allianceId, $line) !== null;
    }

    /** One member speaks per window for the whole alliance, and no member twice in a row. */
    private function recentlySpoke(int $playerId, int $allianceId, CarbonImmutable $now): bool
    {
        return ChatMessage::query()->where('alliance_id', $allianceId)
            ->where(static fn ($query) => $query
                ->where(static fn ($own) => $own->where('sender_id', $playerId)->where('created_at', '>', $now->subHours(self::SPEAKER_QUIET_HOURS)))
                ->orWhere('created_at', '>', $now->subMinutes(self::ALLIANCE_QUIET_MINUTES)))
            ->exists();
    }

    /** @return array{0: AiObservation, 1: BattleReport}|null */
    private function latestHit(int $playerId, AiObservationKind $kind, CarbonImmutable $now): array|null
    {
        $observation = AiObservation::query()->where('player_id', $playerId)->where('kind', $kind)
            ->where('source_time', '>', $now->subHours(self::FRESH_HOURS))->latest('id')->first();
        $report = $observation === null ? null : BattleReport::query()->find($observation->source_id);

        // A hit on this account only counts when a mate could answer it; the observation carries the attacker either way.
        return $report === null || $report->planet_user_id === null || ($kind === AiObservationKind::BattleReportObserved && (int) $report->planet_user_id !== $playerId)
            ? null : [$observation, $report];
    }
}
