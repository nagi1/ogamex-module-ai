<?php

namespace Modules\AI\Domain\Attack;

use OGame\Models\FleetMission;

/**
 * The attacks one account has already spent on one target inside the rolling day.
 *
 * A target may only be attacked a fixed number of times in a window (the host's bashing rule), so the
 * count itself is the budget: the caller that owns the one stated cap and window hands them in, and
 * this class reads the account's own attack history on the target and tells whether the budget is gone.
 */
final class DailyAttackBudget
{
    /**
     * @param int $missionType the host's attack mission type, read by the caller that owns the raid policy
     */
    public function exhausted(int $playerId, int $targetPlanetId, int $missionType, int $cap, int $windowHours): bool
    {
        return $this->spent($playerId, $targetPlanetId, $missionType, $windowHours) >= $cap;
    }

    /** Attacks on the target whose arrival falls inside the window, from the host's own mission history. */
    public function spent(int $playerId, int $targetPlanetId, int $missionType, int $windowHours): int
    {
        return FleetMission::query()
            ->where('user_id', $playerId)
            ->where('planet_id_to', $targetPlanetId)
            ->where('mission_type', $missionType)
            ->where('time_arrival', '>=', now()->subHours($windowHours)->timestamp)
            ->count();
    }
}
