<?php

namespace Modules\AI\Domain\Decision;

use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Services\PlayerService;

/**
 * The subset of a fleet that can actually fly.
 *
 * Solar satellites are ship objects with speed zero and never leave orbit, but
 * the host's getShipUnits() returns them alongside the movable hulls. The host's
 * flight math divides by the slowest ship's speed, so a stationary hull left in
 * the collection makes calculateFleetMissionDuration divide by zero. Every
 * flight-time or fuel quote is therefore priced on the hulls that can move.
 */
final class MovableFleet
{
    public static function of(PlayerService $player, UnitCollection $units): UnitCollection
    {
        $movable = new UnitCollection();

        foreach ($units->units as $entry) {
            if ($entry->unitObject->properties->speed->calculate($player)->totalValue > 0) {
                $movable->addUnit($entry->unitObject, $entry->amount);
            }
        }

        return $movable;
    }
}
