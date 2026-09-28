<?php

namespace Modules\AI\Actions;

/**
 * Pure advice: the wiki's anti-ballistic missile tip reduced to one auditable number.
 *
 * Two ABMs per Plasma Turret is advice rather than a floor, so where the ratio would
 * push past the 70 total cap the cap wins and the recommendation stops growing.
 */
final class RecommendAntiBallisticMissiles
{
    private const MISSILES_PER_TURRET = 2;

    private const MAX_MISSILES = 70;

    public function recommend(int $plasmaTurrets): int
    {
        return min($plasmaTurrets * self::MISSILES_PER_TURRET, self::MAX_MISSILES);
    }
}
