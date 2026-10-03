<?php

namespace Modules\AI\Support;

use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Planet\Coordinate;
use OGame\Services\FleetMissionService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * The host's two fuel refusals as a question asked before dispatch: the planet must hold the flight's
 * deuterium ("Not enough resources on the planet") and the fleet's own tanks must carry it ("You don't
 * have sufficient storage capacity"). An errand that fails either is not sent; the STUCK rows measured
 * the same account retrying such a dispatch every few minutes.
 */
class FlightFuel
{
    public function affordable(PlayerService $player, PlanetService $origin, UnitCollection $fleet, Coordinate $target, float $speedPercent, int $holdingHours = 0): bool
    {
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
        $fuel = (float) $fleetMissions->calculateConsumption($origin, $fleet, $target, $holdingHours, $speedPercent);

        return $fuel <= floor($origin->deuterium()->get()) && $fuel <= $fleet->getTotalFuelCapacity($player);
    }
}
