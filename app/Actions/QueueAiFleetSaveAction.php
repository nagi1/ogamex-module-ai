<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiFleetSave;
use Modules\AI\Domain\Decision\MovableFleet;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\DeploymentMission;
use OGame\GameMissions\RecycleMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\JumpGateService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerGameStateService;
use OGame\Services\PlayerService;

/**
 * Module-owned adapter over the host's deployment path, used as a fleetsave.
 *
 * A save is a deployment at the slowest speed between two of the account's own
 * planets: the fleet is away long enough to survive the inbound hostile, and it
 * can be recalled when the account is back. The host deducts the ships
 * atomically and stays the authority on legality.
 */
class QueueAiFleetSaveAction implements QueueAiFleetSave
{
    /** 1.0 is the slowest speed the host accepts (10%), the classic save speed. */
    private const SAVE_SPEED = 1.0;

    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
        private JumpGateService $jumpGate,
    ) {
    }

    public function handle(
        int $playerId,
        int $originPlanetId,
        int $destinationPlanetId,
        int $shadowDestinationPlanetId = 0,
        int $harvestGalaxy = 0,
        int $harvestSystem = 0,
        int $harvestPosition = 0,
        float $speed = 1.0,
        int $jumpGatePlanetId = 0,
    ): AiActionResult {
        if ($jumpGatePlanetId > 0) {
            return $this->jumpSave($playerId, $originPlanetId, $jumpGatePlanetId);
        }

        if ($harvestPosition > 0) {
            return $this->harvestSave($playerId, $originPlanetId, $harvestGalaxy, $harvestSystem, $harvestPosition);
        }

        if (!Planet::query()->whereKey($originPlanetId)->where('user_id', $playerId)->exists()) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }
        if (!Planet::query()->whereKey($destinationPlanetId)->where('user_id', $playerId)->exists()) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }

        try {
            $player = $this->playerGameStateService->advance($playerId, $originPlanetId);

            if ($player->isBanned()) {
                return AiActionResult::rejected(AiQueueActionReason::PlayerBanned);
            }
            if ($player->isInVacationMode()) {
                return AiActionResult::rejected(AiQueueActionReason::VacationMode);
            }

            $origin = $this->planetServiceFactory->makeForPlayer($player, $originPlanetId, false);
            $destination = $this->planetServiceFactory->makeForPlayer($player, $destinationPlanetId, false);

            // V8: a large fleet is split across two own bodies so a
            // phalanx-timed crash catches only part (FS-009). The split is
            // decided at planning time; it is re-checked here because the fleet
            // may have changed since.
            if ($shadowDestinationPlanetId > 0
                && Planet::query()->whereKey($shadowDestinationPlanetId)->where('user_id', $playerId)->exists()) {
                $shadow = $this->planetServiceFactory->makeForPlayer($player, $shadowDestinationPlanetId, false);
                [$military, $civil] = $this->splitFleet($player, $origin);
                if ($military->units !== [] && $civil->units !== []) {
                    return $this->dispatchShadowWaves($player, $origin, $destination, $shadow, $military, $civil, $speed);
                }
            }

            // A save is incomplete unless cargo is loaded too: in-flight
            // resources cannot be raided, and a stripped planet is unprofitable
            // to hit (FS-006). Lift the planet's stock up to what the fleet can
            // carry.
            $flying = MovableFleet::of($player, $origin->getShipUnits());
            if ($flying->units === []) {
                return AiActionResult::rejected(AiQueueActionReason::NoDisposableFleet);
            }

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);

            // The host refuses a flight whose fuel is more than the planet holds or the fleet's own
            // tanks carry (the two refusals STUCK rows measured on a hundred accounts): a fleet too
            // big to fuel sheds its thirstiest hull until it can fly, rather than being offered again.
            $flying = $this->trimmedToFuel($player, $origin, $flying, $destination, $speed, $fleetMissions);
            if ($flying === null) {
                return AiActionResult::rejected(AiQueueActionReason::SourceShortAtDispatch);
            }

            $cargo = $this->liftableStock($player, $origin, $flying, $destination, $speed, $fleetMissions);

            $mission = $fleetMissions->createNewFromPlanet(
                $origin,
                $destination->getPlanetCoordinates(),
                $destination->getPlanetType(),
                DeploymentMission::getTypeId(),
                $flying,
                $cargo,
                $speed,
            );

            return AiActionResult::queued($mission->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }

    /**
     * The jump-gate save: the whole transferable fleet moves instantly between two gated moons
     * instead of flying. The host's own eligibility is re-checked here — the fleet may have moved
     * and the cooldown may have landed since planning (RV-009).
     */
    private function jumpSave(int $playerId, int $originPlanetId, int $destinationPlanetId): AiActionResult
    {
        if (!Planet::query()->whereKey($originPlanetId)->where('user_id', $playerId)->exists()) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }
        if (!Planet::query()->whereKey($destinationPlanetId)->where('user_id', $playerId)->exists()) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }

        try {
            $player = $this->playerGameStateService->advance($playerId, $originPlanetId);

            if ($player->isBanned()) {
                return AiActionResult::rejected(AiQueueActionReason::PlayerBanned);
            }
            if ($player->isInVacationMode()) {
                return AiActionResult::rejected(AiQueueActionReason::VacationMode);
            }

            $origin = $this->planetServiceFactory->makeForPlayer($player, $originPlanetId, false);
            $destination = $this->planetServiceFactory->makeForPlayer($player, $destinationPlanetId, false);

            if ($origin->getObjectLevel('jump_gate') < 1
                || $destination->getObjectLevel('jump_gate') < 1
                || $this->jumpGate->isOnCooldown($origin)
                || $this->jumpGate->isOnCooldown($destination)) {
                return AiActionResult::rejected(AiQueueActionReason::JumpGateUnavailable);
            }

            $transferable = array_flip($this->jumpGate->getTransferableShips());
            $ships = array_intersect_key($origin->getShipUnits()->toArray(), $transferable);
            if ($ships === [] || !$this->jumpGate->transferShips($origin, $destination, $ships)) {
                return AiActionResult::rejected(AiQueueActionReason::JumpGateUnavailable);
            }

            $this->jumpGate->setCooldown($origin, $destination);

            return AiActionResult::succeeded(AiQueueActionReason::JumpGateJumped);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }

    /**
     * The single-planet fallback (FS-011): with no second own body the whole
     * fleet rides a recycle mission to a host debris field, so it is in the air
     * while the inbound hostile lands. The recycler the mission needs and the
     * field are host-read; the slowest speed keeps the fleet away longest. The
     * host stays the authority on whether the mission is legal.
     */
    private function harvestSave(int $playerId, int $originPlanetId, int $galaxy, int $system, int $position): AiActionResult
    {
        if (!Planet::query()->whereKey($originPlanetId)->where('user_id', $playerId)->exists()) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }

        try {
            $player = $this->playerGameStateService->advance($playerId, $originPlanetId);

            if ($player->isBanned()) {
                return AiActionResult::rejected(AiQueueActionReason::PlayerBanned);
            }
            if ($player->isInVacationMode()) {
                return AiActionResult::rejected(AiQueueActionReason::VacationMode);
            }

            $origin = $this->planetServiceFactory->makeForPlayer($player, $originPlanetId, false);
            $fleet = MovableFleet::of($player, $origin->getShipUnits());
            if ($fleet->units === []) {
                return AiActionResult::rejected(AiQueueActionReason::NoDisposableFleet);
            }

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            $mission = $fleetMissions->createNewFromPlanet(
                $origin,
                new Coordinate($galaxy, $system, $position),
                PlanetType::DebrisField,
                RecycleMission::getTypeId(),
                $fleet,
                new Resources(),
                self::SAVE_SPEED,
            );

            return AiActionResult::queued($mission->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }

    /**
     * Two save waves: the combat hulls to the safer body and the civil hulls,
     * which carry the stock, to the other (FS-009). The combat wave leaves
     * first, so a refusal on the second still leaves the valuable half parked.
     */
    private function dispatchShadowWaves(PlayerService $player, PlanetService $origin, PlanetService $destination, PlanetService $shadow, UnitCollection $military, UnitCollection $civil, float $speed): AiActionResult
    {
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);

        // A wave with no hulls has no speed to fly at: the host divides by it, so an all-military or
        // all-civil fleet sends the one wave it has.
        $mission = null;

        // Each wave is fuelled on its own: the military wave leaves first and the civil wave is trimmed
        // against what that leaves in the tank.
        $military = $this->trimmedToFuel($player, $origin, $military, $destination, $speed, $fleetMissions) ?? new UnitCollection();

        if ($military->units !== []) {
            $mission = $fleetMissions->createNewFromPlanet(
                $origin,
                $destination->getPlanetCoordinates(),
                $destination->getPlanetType(),
                DeploymentMission::getTypeId(),
                $military,
                new Resources(),
                $speed,
            );
        }

        $civil = $this->trimmedToFuel($player, $origin, $civil, $shadow, $speed, $fleetMissions) ?? new UnitCollection();

        if ($civil->units !== []) {
            $civilMission = $fleetMissions->createNewFromPlanet(
                $origin,
                $shadow->getPlanetCoordinates(),
                $shadow->getPlanetType(),
                DeploymentMission::getTypeId(),
                $civil,
                $this->liftableStock($player, $origin, $civil, $shadow, $speed, $fleetMissions),
                $speed,
            );
            $mission ??= $civilMission;
        }

        if ($mission === null) {
            return AiActionResult::rejected(AiQueueActionReason::NoDisposableFleet);
        }

        return AiActionResult::queued($mission->id);
    }

    /**
     * The origin's hulls separated by the host's own military/civil
     * classification (FS-009).
     *
     * @return array{0: UnitCollection, 1: UnitCollection}
     */
    private function splitFleet(PlayerService $player, PlanetService $origin): array
    {
        $militaryNames = array_map(static fn ($object): string => $object->machine_name, ObjectService::getMilitaryShipObjects());
        $civilNames = array_map(static fn ($object): string => $object->machine_name, ObjectService::getCivilShipObjects());

        $military = new UnitCollection();
        $civil = new UnitCollection();

        foreach (MovableFleet::of($player, $origin->getShipUnits())->units as $entry) {
            $name = $entry->unitObject->machine_name;
            if (in_array($name, $militaryNames, true)) {
                $military->addUnit($entry->unitObject, $entry->amount);
            }

            if (in_array($name, $civilNames, true)) {
                $civil->addUnit($entry->unitObject, $entry->amount);
            }
        }

        return [$military, $civil];
    }

    /**
     * The planet's stock, scaled to the fleet's cargo hold (FS-006).
     */
    private function liftableStock(PlayerService $player, PlanetService $origin, UnitCollection $fleet, PlanetService $destination, float $speed, FleetMissionService $fleetMissions): Resources
    {
        // The flight is paid from the planet's deuterium, so only what is left after the fuel (and at most
        // half of it) goes into the hold: a hold that took it all leaves the dispatch short of fuel.
        $fuel = (float) $fleetMissions->calculateConsumption($origin, $fleet, $destination->getPlanetCoordinates(), 0, $speed);
        $spare = max(0.0, floor($origin->deuterium()->get()) - ceil($fuel));
        $stock = new Resources($origin->metal()->get(), $origin->crystal()->get(), min(floor($origin->deuterium()->get() / 2), $spare));
        $capacity = $fleet->getTotalCargoCapacity($player);

        if ($stock->sum() <= $capacity) {
            return $stock;
        }

        if ($capacity <= 0) {
            return new Resources();
        }

        // Whole units, rounded down: the host refuses a hold that is full by a fraction.
        $share = $capacity / $stock->sum();

        return new Resources(
            floor($stock->metal->get() * $share),
            floor($stock->crystal->get() * $share),
            floor($stock->deuterium->get() * $share),
        );
    }

    /**
     * The fleet that can be fuelled from this planet and by its own tanks, or null when no hull can.
     * The thirstiest hull by fuel per unit is shed first until the flight fits both limits.
     */
    private function trimmedToFuel(PlayerService $player, PlanetService $origin, UnitCollection $fleet, PlanetService $destination, float $speed, FleetMissionService $fleetMissions): ?UnitCollection
    {
        $units = [];
        foreach ($fleet->units as $entry) {
            $units[$entry->unitObject->machine_name] = $entry;
        }

        while ($units !== []) {
            $candidate = new UnitCollection();
            foreach ($units as $entry) {
                $candidate->addUnit($entry->unitObject, $entry->amount);
            }

            $fuel = (float) $fleetMissions->calculateConsumption($origin, $candidate, $destination->getPlanetCoordinates(), 0, $speed);
            if ($fuel <= floor($origin->deuterium()->get()) && $fuel <= $candidate->getTotalFuelCapacity($player)) {
                return $candidate;
            }

            $thirstiest = null;
            $worst = -1.0;
            foreach ($units as $name => $entry) {
                $single = new UnitCollection();
                $single->addUnit($entry->unitObject, $entry->amount);
                $perHull = (float) $fleetMissions->calculateConsumption($origin, $single, $destination->getPlanetCoordinates(), 0, $speed);
                if ($perHull > $worst) {
                    $worst = $perHull;
                    $thirstiest = $name;
                }
            }
            unset($units[$thirstiest]);
        }

        return null;
    }
}
