<?php

namespace Modules\AI\Domain\Perception;

use OGame\Services\PlanetService;

/**
 * Read-only derived signals over the host's public activity marker and intel
 * freshness, computed once for the tactical planners (SP7).
 *
 * The 15-minute activity star is a galaxy-visible fact: `Planet.time_last_update`
 * names which body was touched and when (INT-004/005/010). This reader never
 * writes and never manufactures the marker — it is read, not fabricated.
 */
class ActivityIntelReader
{
    /** The activity star vanishes exactly this many minutes after the body's last update (INT-010). */
    private const ACTIVITY_WINDOW_MINUTES = 15;

    private const ACTIVITY_WINDOW_SECONDS = self::ACTIVITY_WINDOW_MINUTES * 60;

    /** Intel types that go stale quickly: loot, fleet and defence change fast (decision-policies.md). */
    private const FAST_TYPES = ['resources', 'ships', 'defense'];

    public function activityAt(PlanetService $body): bool
    {
        return $body->getMinutesSinceLastUpdate() < self::ACTIVITY_WINDOW_MINUTES;
    }

    /**
     * Activity on a moon with none on its planet signals the moon's facilities
     * (phalanx / jump gate) in use while a fleet is in transit (INT-006).
     */
    public function moonOnlyActivity(PlanetService $moon, PlanetService $planet): bool
    {
        return $this->activityAt($moon) && !$this->activityAt($planet);
    }

    /**
     * Confidence of a report for one intel type, as a fraction of its freshness
     * window. Resources, fleet and defence go stale fast; coordinates and past
     * losses slowly (RAID-005).
     */
    public function intelConfidence(int $observedAt, int $now, int $ttlHours, string $type): float
    {
        $ageSeconds = max(0, $now - $observedAt);
        $windowSeconds = $this->typeWindowSeconds($type, $ttlHours);
        $confidence = 1.0 - ($ageSeconds / $windowSeconds);

        return max(0.0, min(1.0, $confidence));
    }

    /**
     * Probability the target is online when the fleet lands, from its last-update
     * stamp alone — the raid-risk input (RAID-004).
     */
    public function activityProbabilityAtEta(PlanetService $body, int $now, int $etaAt): float
    {
        $lastUpdateAt = $body->getUpdatedAt()->getTimestamp();

        if (($now - $lastUpdateAt) >= self::ACTIVITY_WINDOW_SECONDS) {
            return 0.0;
        }

        $minutesToArrival = max(0.0, ($etaAt - $now) / 60);

        // ponytail: exponential decay over the 15-minute window is the smallest
        // honest model for "still online on arrival"; the true session length is
        // a Weibull (H2) and the calibration runs can swap the flat window for it.
        return exp(-$minutesToArrival / self::ACTIVITY_WINDOW_MINUTES);
    }

    /**
     * The completeness factor a report's sections justify: a probe that returned
     * no fleet or defence section cannot answer "raid it or not" even when fresh,
     * so each missing section halves the confidence and a present one never
     * raises it (INT-003). null = the section was not returned, [] = returned
     * and empty.
     *
     * @param array<string, int>|null $ships
     * @param array<string, int>|null $defense
     */
    public function completenessFactor(?array $ships, ?array $defense): float
    {
        $factor = 1.0;
        if ($ships === null) {
            $factor *= 0.5;
        }
        if ($defense === null) {
            $factor *= 0.5;
        }

        return $factor;
    }

    private function typeWindowSeconds(string $type, int $ttlHours): int
    {
        // ponytail: the fast/slow split is sourced (decision-policies.md); the
        // 6:1 ratio is a placeholder for the calibration runs, not a measured
        // constant. Replacing it changes only this division.
        $hours = in_array($type, self::FAST_TYPES, true) ? (int) ceil($ttlHours / 6) : $ttlHours;

        return max(1, $hours) * 3600;
    }
}
