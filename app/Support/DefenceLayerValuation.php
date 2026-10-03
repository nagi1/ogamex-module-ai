<?php

namespace Modules\AI\Support;

/**
 * 70% of a destroyed defensive unit is rebuilt for free (the host's rebuild chance), so losing one
 * costs only the remaining 30% of its build cost. A wall is valued by that loss, not the full price.
 */
final class DefenceLayerValuation
{
    /** Share of a destroyed unit's cost that is actually lost. */
    public const LOSS_SHARE = 0.30;

    public static function lossOnDestruction(float $buildCost, int $destroyed = 1): float
    {
        return max(0.0, $buildCost) * max(0, $destroyed) * self::LOSS_SHARE;
    }
}
