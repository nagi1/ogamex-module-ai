<?php

namespace Modules\AI\Support;

/**
 * Raid profit as defined by WIK-015: loot minus flight cost.
 *
 * Both figures arrive from the caller — the 1000 deuterium loot and the 26 deuterium cost in the
 * source are example inputs, not constants of the mechanic — and the result stays in the loot's
 * own unit, so nothing is converted or derived here.
 */
final class RaidProfit
{
    public function profit(int $loot, int $flightCost): int
    {
        return $loot - $flightCost;
    }
}
