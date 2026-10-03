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
use OGame\GameObjects\Models\Units\UnitEntry;
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

            $shipment = $this->fittedToHold($player, $source, $shipment);
            $fleet = $this->transportFleet($player, $source, $shipment);
            if ($fleet === null) {
                return AiActionResult::rejected(AiQueueActionReason::NoTransportFleet);
            }

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            $fuel = $fleetMissions->calculateConsumption($source, $fleet, $target->getPlanetCoordinates(), 0, self::TRANSPORT_SPEED);
            if ($fuel > $fleet->getTotalFuelCapacity($player)) {
                return AiActionResult::rejected(AiQueueActionReason::NoTransportFleet);
            }
            if ($fuel > floor($source->deuterium()->get())) {
                return AiActionResult::rejected(AiQueueActionReason::SourceShortAtDispatch);
            }

            $shipment = $this->leavingFuelBehind($source, $shipment, $fleet, $target, $fleetMissions);
            $shipment = $this->fittedBesideFuel($player, $fleet, $shipment, (int) ceil($fuel));

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
     * The flight's fuel rides in the same hold as the cargo, so a shipment that fills the hold
     * leaves no room for it and the host refuses with insufficient storage capacity. A player
     * loads what is left of the hold after the fuel.
     */
    private function fittedBesideFuel(PlayerService $player, UnitCollection $fleet, Resources $shipment, int $fuel): Resources
    {
        $room = max(0, $fleet->getTotalCargoCapacity($player) - $fuel);
        $total = $shipment->sum();
        if ($total <= $room) {
            return $shipment;
        }

        $share = $room / $total;

        return new Resources(
            floor($shipment->metal->get() * $share),
            floor($shipment->crystal->get() * $share),
            floor($shipment->deuterium->get() * $share),
        );
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
    /**
     * A player ships what the hold takes: a store of millions beside a few hundred cargo ships sends
     * a full fleet's load, it is not refused for being bigger than the fleet.
     */
    private function fittedToHold(PlayerService $player, PlanetService $source, Resources $shipment): Resources
    {
        $capacity = $source->getShipUnits()->getTotalCargoCapacity($player);
        $total = $shipment->sum();
        if ($capacity <= 0 || $total <= $capacity) {
            return $shipment;
        }

        $share = $capacity / $total;

        return new Resources(
            floor($shipment->metal->get() * $share),
            floor($shipment->crystal->get() * $share),
            floor($shipment->deuterium->get() * $share),
        );
    }

    /**
     * The deuterium the ferry's own flight burns from this source, or null when it has no fleet that
     * can carry the shipment. The planner asks it before publishing, so a source that cannot pay the
     * fuel is not offered the way a player does not plan a run with empty tanks.
     */
    public function flightFuel(PlayerService $player, PlanetService $source, PlanetService $target, Resources $shipment): float|null
    {
        $fleet = $this->transportFleet($player, $source, $this->fittedToHold($player, $source, $shipment));
        if ($fleet === null) {
            return null;
        }
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
        $fuel = (float) $fleetMissions->calculateConsumption($source, $fleet, $target->getPlanetCoordinates(), 0, self::TRANSPORT_SPEED);

        // The tanks too: the dispatch refuses a flight the fleet cannot carry the fuel for, so a ferry
        // that fails that test is not planned in the first place.
        return $fuel > $fleet->getTotalFuelCapacity($player) ? null : $fuel;
    }

    private function transportFleet(PlayerService $player, PlanetService $source, Resources $shipment): ?UnitCollection
    {
        $fleet = new UnitCollection();
        $remaining = $shipment->sum();
        if ($remaining <= 0) {
            return null;
        }

        foreach ($this->holdsByCapacity($player, $source) as $entry) {
            $capacity = $entry->unitObject->properties->capacity->calculate($player)->totalValue;

            $amount = min((int) ceil($remaining / $capacity), $entry->amount);

            $fleet->addUnit($entry->unitObject, $amount);
            $remaining -= $amount * $capacity;
            if ($remaining <= 0) {
                return $fleet;
            }
        }

        return null;
    }

    /**
     * Biggest holds first: a player ferries with cargo ships, and a fleet padded with fighters
     * burns more deuterium than its own tanks carry, which the host refuses as storage capacity.
     *
     * @return array<UnitEntry>
     */
    private function holdsByCapacity(PlayerService $player, PlanetService $source): array
    {
        $entries = [];
        foreach ($source->getShipUnits()->units as $entry) {
            $capacity = $entry->unitObject->properties->capacity->calculate($player)->totalValue;
            if ($capacity > 0) {
                $entries[] = [$capacity, $entry];
            }
        }
        usort($entries, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_map(static fn (array $pair) => $pair[1], $entries);
    }
}
