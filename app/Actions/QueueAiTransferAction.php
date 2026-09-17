<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiTransfer;
use Modules\AI\Domain\Decision\QueueableTransferPlanner;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\TransportMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerGameStateService;
use OGame\Services\PlayerService;

/**
 * Module-owned adapter over the host's transport mission.
 *
 * The target legality, the cargo-capacity check and the atomic resource debit are the host's; the
 * module adds the ship selection -- just enough of the cargo hulls the source already owns to carry
 * the shipment, never the combat fleet a player would not risk on a ferry run.
 */
class QueueAiTransferAction implements QueueAiTransfer
{
    /** 100%: a ferry is wanted at the target, not parked in space. */
    private const TRANSPORT_SPEED = 10.0;

    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
    ) {
    }

    public function handle(int $playerId, int $sourcePlanetId, int $targetPlanetId, int $metal, int $crystal, int $deuterium): AiActionResult
    {
        if (!Planet::query()->whereKey($sourcePlanetId)->where('user_id', $playerId)->exists()) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }
        if (!Planet::query()->whereKey($targetPlanetId)->where('user_id', $playerId)->exists()) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }

        try {
            $player = $this->playerGameStateService->advance($playerId, $sourcePlanetId);

            if ($player->isBanned()) {
                return AiActionResult::rejected(AiQueueActionReason::PlayerBanned);
            }
            if ($player->isInVacationMode()) {
                return AiActionResult::rejected(AiQueueActionReason::VacationMode);
            }

            $source = $this->planetServiceFactory->makeForPlayer($player, $sourcePlanetId, false);
            $target = $this->planetServiceFactory->makeForPlayer($player, $targetPlanetId, false);

            $shipment = $this->loadable($source, $metal, $crystal, $deuterium);
            if ($shipment === null) {
                return AiActionResult::rejected(AiQueueActionReason::SourceShortAtDispatch);
            }

            $fleet = $this->transportFleet($player, $source, $shipment);
            if ($fleet === null) {
                return AiActionResult::rejected(AiQueueActionReason::NoTransportFleet);
            }

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            $shipment = $this->leavingFuelBehind($source, $shipment, $fleet, $target, $fleetMissions);

            $mission = $fleetMissions->createNewFromPlanet(
                $source,
                $target->getPlanetCoordinates(),
                $target->getPlanetType(),
                TransportMission::getTypeId(),
                $fleet,
                $shipment,
                self::TRANSPORT_SPEED,
            );

            return AiActionResult::queued($mission->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }

    /**
     * The shipment is quoted when the session decides, but a work item can run minutes later and at
     * this game speed the source has spent part of the surplus by then: the host refuses a flight
     * whose cargo the planet no longer holds, which is what most refused transfers were. A player
     * loads what is still on the pad, so each resource is clamped to the source's own stock, and a
     * remnant below the ordinary minimum is abandoned instead of flying a fleet for it.
     */
    private function loadable(PlanetService $source, int $metal, int $crystal, int $deuterium): ?Resources
    {
        $held = [
            (int) floor($source->metal()->get()),
            (int) floor($source->crystal()->get()),
            (int) floor($source->deuterium()->get()),
        ];

        // Nothing was spent between the decision and this dispatch, so the quote stands.
        if ($metal <= $held[0] && $crystal <= $held[1] && $deuterium <= $held[2]) {
            return new Resources($metal, $crystal, $deuterium);
        }

        $shipment = new Resources(min($metal, $held[0]), min($crystal, $held[1]), min($deuterium, $held[2]));

        if ($shipment->metal->get() + $shipment->crystal->get() < QueueableTransferPlanner::MINIMUM_SHIPMENT) {
            return null;
        }

        return $shipment;
    }

    /**
     * The host demands the cargo *and* the flight's own fuel on the origin planet, so a shipment that
     * takes the last deuterium is refused for resources even though the cargo itself fits. The fuel
     * is the host's own figure for this fleet and route; a player leaves it behind and flies with
     * the rest.
     */
    private function leavingFuelBehind(PlanetService $source, Resources $shipment, UnitCollection $fleet, PlanetService $target, FleetMissionService $fleetMissions): Resources
    {
        $fuel = $fleetMissions->calculateConsumption($source, $fleet, $target->getPlanetCoordinates(), 0, self::TRANSPORT_SPEED);
        $spare = (int) floor($source->deuterium()->get()) - (int) ceil($fuel);

        return new Resources(
            $shipment->metal->get(),
            $shipment->crystal->get(),
            min($shipment->deuterium->get(), max(0, $spare)),
        );
    }

    /**
     * Just enough of the cargo hulls the source already owns to carry the shipment, or null when the
     * source's fleet cannot carry it. Ships without cargo capacity are never taken, so a ferry run
     * never moves the combat fleet.
     */
    private function transportFleet(PlayerService $player, PlanetService $source, Resources $shipment): ?UnitCollection
    {
        $fleet = new UnitCollection();
        $remaining = $shipment->sum();
        if ($remaining <= 0) {
            return null;
        }

        foreach ($source->getShipUnits()->units as $entry) {
            $capacity = $entry->unitObject->properties->capacity->calculate($player)->totalValue;
            if ($capacity <= 0) {
                continue;
            }

            $amount = min((int) ceil($remaining / $capacity), $entry->amount);

            $fleet->addUnit($entry->unitObject, $amount);
            $remaining -= $amount * $capacity;
            if ($remaining <= 0) {
                return $fleet;
            }
        }

        return null;
    }
}
