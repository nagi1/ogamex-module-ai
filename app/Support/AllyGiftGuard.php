<?php

namespace Modules\AI\Support;

use Modules\AI\Enums\AiQueueActionReason;
use OGame\GameMissions\TransportMission;
use OGame\Models\FleetMission;
use OGame\Models\Highscore;
use OGame\Models\Planet;
use OGame\Models\User;

/**
 * The pushing-rule guard for a resource gift to another player (official rule 5; DEF-031).
 *
 * A gift leaves the account only inside the one relationship where it is ordinary play: an
 * alliance co-member. It never goes to someone who out-scores the sender (that is feeding a
 * bigger account, not helping a smaller one), never exceeds a tenth of what the source holds,
 * and is rationed to two per rolling 72 hours so a repeated trickle cannot become a transfer
 * channel. The count is read from the host's own fleet missions, so it holds across restarts and
 * needs no table of its own.
 */
class AllyGiftGuard
{
    /** The largest share of the source's stock one gift may carry, per resource. */
    public const MAX_STOCK_SHARE = 0.10;

    public const MAX_GIFTS = 2;

    public const WINDOW_HOURS = 72;

    /**
     * @param  float  $sourceMetal  what the source planet holds now
     */
    public function refusal(int $senderId, int $targetPlanetId, int $metal, int $crystal, int $deuterium, float $sourceMetal, float $sourceCrystal, float $sourceDeuterium): ?AiQueueActionReason
    {
        $recipientId = Planet::query()->whereKey($targetPlanetId)->value('user_id');
        if ($recipientId === null || (int) $recipientId === $senderId) {
            return AiQueueActionReason::GiftNotAllowed;
        }

        if (!$this->coMembers($senderId, (int) $recipientId)) {
            return AiQueueActionReason::GiftNotAllowed;
        }

        if ($this->outScores((int) $recipientId, $senderId)) {
            return AiQueueActionReason::GiftNotAllowed;
        }

        if ($metal > $sourceMetal * self::MAX_STOCK_SHARE
            || $crystal > $sourceCrystal * self::MAX_STOCK_SHARE
            || $deuterium > $sourceDeuterium * self::MAX_STOCK_SHARE) {
            return AiQueueActionReason::GiftNotAllowed;
        }

        if ($this->recentGifts($senderId) >= self::MAX_GIFTS) {
            return AiQueueActionReason::GiftNotAllowed;
        }

        return null;
    }

    private function coMembers(int $a, int $b): bool
    {
        $alliances = User::query()->whereIn('id', [$a, $b])->pluck('alliance_id');

        return $alliances->count() === 2 && $alliances->first() !== null && $alliances->unique()->count() === 1;
    }

    /** A recipient with no score row is treated as the smaller account. */
    private function outScores(int $recipientId, int $senderId): bool
    {
        $scores = Highscore::query()->whereIn('player_id', [$recipientId, $senderId])->pluck('general', 'player_id');

        return (int) ($scores[$recipientId] ?? 0) > (int) ($scores[$senderId] ?? 0);
    }

    private function recentGifts(int $senderId): int
    {
        return FleetMission::query()
            ->where('user_id', $senderId)
            ->where('mission_type', TransportMission::getTypeId())
            ->where('canceled', 0)
            ->where('time_departure', '>=', now()->subHours(self::WINDOW_HOURS)->timestamp)
            ->whereIn('planet_id_to', Planet::query()->where('user_id', '!=', $senderId)->select('id'))
            ->count();
    }
}
