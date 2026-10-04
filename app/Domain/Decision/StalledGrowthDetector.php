<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiScoreSample;
use Symfony\Component\Yaml\Yaml;

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
    /** Samples read, one per hour, so also the number of flat hours that counts as stalled: the behaviour file's bound. */
    private const BEHAVIOR_FILE = '/resources/behavior/growth.yaml';

    /** @var array<int, bool> */
    private array $stalled = [];

    public function stalled(int $playerId): bool
    {
        return $this->stalled[$playerId] ??= $this->flatForTheWindow($playerId);
    }

    private function flatForTheWindow(int $playerId): bool
    {
        $window = (int) Yaml::parseFile(dirname(__DIR__, 3) . self::BEHAVIOR_FILE)['stalled_growth']['flat_hours'];
        $scores = AiScoreSample::query()
            ->where('player_id', $playerId)
            ->orderByDesc('sampled_at')
            ->limit($window)
            ->pluck('general');

        if ($scores->count() < $window) {
            return false;
        }

        return $scores->unique()->count() === 1;
    }
}
