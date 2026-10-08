<?php

namespace Modules\AI\Domain\Decision;

use OGame\Models\Highscore;
use OGame\Models\User;
use Symfony\Component\Yaml\Yaml;

/**
 * Why a player leaves a strong club's members alone: "attacking you means potentially provoking your entire alliance".
 * A target whose alliance is more than `deterrence_ratio` times the raider's own side in points, and who is not an
 * enemy of the raider, is not raided (farm.yaml). Against an enemy, the war decides.
 */
class AllianceDeterrence
{
    private const FILE = '/resources/behavior/farm.yaml';

    public function deters(int $raiderId, int $targetOwnerId): bool
    {
        $ratio = $this->ratio();
        $allianceId = User::query()->whereKey($targetOwnerId)->value('alliance_id');
        if ($ratio <= 0.0 || $allianceId === null || array_key_exists($targetOwnerId, app(Enemies::class)->of($raiderId))) {
            return false;
        }

        return $this->points((int) User::query()->whereKey($raiderId)->value('alliance_id'), $raiderId) * $ratio < $this->points((int) $allianceId, $targetOwnerId);
    }

    /** The side's points: the whole alliance, or the player alone when they sit in none. */
    private function points(int $allianceId, int $playerId): float
    {
        $members = $allianceId > 0 ? User::query()->where('alliance_id', $allianceId)->pluck('id')->all() : [$playerId];

        return max(1.0, (float) Highscore::query()->whereIn('player_id', $members)->sum('general'));
    }

    private function ratio(): float
    {
        $policy = Yaml::parseFile(dirname(__DIR__, 3) . self::FILE);

        return is_array($policy) ? (float) ($policy['deterrence_ratio'] ?? 0.0) : 0.0;
    }
}
