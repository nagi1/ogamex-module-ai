<?php

namespace Modules\AI\Support;

use InvalidArgumentException;

/**
 * Scales a moon-chance fleet by the debris ratio of the universe it flies in.
 *
 * A moon chance comes from the debris the attacking fleet itself leaves behind, so a
 * universe that returns only a fraction of that debris needs a proportionally larger
 * fleet for the same chance. The anchor is the documented light-fighter figure: 1250
 * light fighters give a 20% moon chance on a 0.40 debris universe.
 */
final class MoonChanceCalculator
{
    /**
     * Debris ratio the quoted light-fighter figures assume: 30% debris universes.
     */
    public const REFERENCE_DEBRIS_RATIO = 0.30;

    /**
     * Light fighters per moon-chance percentage point on a universe that returns the
     * whole fleet cost as debris, derived from the documented anchor:
     * 1250 light fighters * 0.40 debris / 20 percent = 25.
     */
    private const LIGHT_FIGHTERS_PER_PERCENT_AT_FULL_DEBRIS = 25.0;

    /**
     * The requirement is returned unrounded: rounding here would break the documented
     * relation that 1250 is exactly three quarters of the 30% requirement, so rounding
     * stays with the caller.
     *
     * @param float $targetChancePercent Wanted moon chance in percentage points (20.0 = 20%).
     * @param float $debrisRatio Share of the fleet's resource cost returned as debris (0.40 = 40%).
     */
    public function requiredLightFighters(float $targetChancePercent, float $debrisRatio): float
    {
        $this->assertUsableDebrisRatio($debrisRatio);

        return $targetChancePercent * self::LIGHT_FIGHTERS_PER_PERCENT_AT_FULL_DEBRIS / $debrisRatio;
    }

    /**
     * Re-scales a fleet size that was already computed for REFERENCE_DEBRIS_RATIO.
     *
     * @param float $referenceFleetSize Light fighters needed on a REFERENCE_DEBRIS_RATIO universe.
     * @param float $debrisRatio Debris ratio of the universe the fleet will fly in (0.40 = 40%).
     */
    public function scaleFleetSize(float $referenceFleetSize, float $debrisRatio): float
    {
        $this->assertUsableDebrisRatio($debrisRatio);

        return $referenceFleetSize * self::REFERENCE_DEBRIS_RATIO / $debrisRatio;
    }

    /**
     * A universe that returns no debris at all can never be scaled into a finite fleet.
     */
    private function assertUsableDebrisRatio(float $debrisRatio): void
    {
        if ($debrisRatio <= 0.0) {
            throw new InvalidArgumentException('Debris ratio must be greater than zero.');
        }
    }
}
