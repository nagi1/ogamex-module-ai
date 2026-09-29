<?php

declare(strict_types=1);

namespace Modules\AI\Support;

/**
 * Raid profit per WIK-015: loot minus flight cost.
 *
 * The flight cost is always an input and never derived here. It depends on distance, speed and
 * drive research, so recomputing it inside the profit rule would freeze contested fleet
 * mechanics into a helper that has no business knowing about them.
 */
final class Profit
{
    /**
     * Break-even: a flight that only returns its own cost has not paid for itself.
     */
    public const BREAK_EVEN = 0;

    /**
     * @param int $loot Resources recovered from the target, in the resource the cost is paid in.
     * @param int $flightCost Injected cost of flying the fleet there and back.
     */
    public function amount(int $loot, int $flightCost): int
    {
        return $loot - $flightCost;
    }

    /**
     * @param int $loot Resources recovered from the target, in the resource the cost is paid in.
     * @param int $flightCost Injected cost of flying the fleet there and back.
     */
    public function isWorthwhile(int $loot, int $flightCost): bool
    {
        return $this->amount($loot, $flightCost) > self::BREAK_EVEN;
    }
}
