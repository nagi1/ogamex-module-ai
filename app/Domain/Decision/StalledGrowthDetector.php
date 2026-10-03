<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiScoreSample;

/**
 * Whether an account's public score has stopped moving (IMPL-69).
 *
 * The hourly score sample is the only growth history the host does not keep. An account whose last
 * several samples, spanning at least that many hours, all carry the same general score has not grown in
 * that time: whatever it is saving for is not arriving, so the saving is dropped and the account spends
 * what it holds the way a player does who notices their points have been flat all day.
 */
class StalledGrowthDetector
{
    /** Samples read, one per hour, so also the number of flat hours that counts as stalled. */
    private const FLAT_HOURS = 6;

    public function stalled(int $playerId): bool
    {
        $scores = AiScoreSample::query()
            ->where('player_id', $playerId)
            ->orderByDesc('sampled_at')
            ->limit(self::FLAT_HOURS)
            ->pluck('general');

        if ($scores->count() < self::FLAT_HOURS) {
            return false;
        }

        return $scores->unique()->count() === 1;
    }
}
