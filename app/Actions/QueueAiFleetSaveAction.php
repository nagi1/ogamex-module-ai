<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiFleetSave;
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
    ): AiActionResult {
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
                [$military, $civil] = $this->splitFleet($origin);
                if ($military->units !== [] && $civil->units !== []) {
                    return $this->dispatchShadowWaves($player, $origin, $destination, $shadow, $military, $civil);
                }
            }

            // A save is incomplete unless cargo is loaded too: in-flight
            // resources cannot be raided, and a stripped planet is unprofitable
            // to hit (FS-006). Lift the planet's stock up to what the fleet can
            // carry.
            $cargo = $this->liftableStock($player, $origin, $origin->getShipUnits());

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            $mission = $fleetMissions->createNewFromPlanet(
                $origin,
                $destination->getPlanetCoordinates(),
                $destination->getPlanetType(),
                DeploymentMission::getTypeId(),
                $origin->getShipUnits(),
                $cargo,
                self::SAVE_SPEED,
            );

            return AiActionResult::queued($mission->id);
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
            $fleet = $origin->getShipUnits();
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
    private function dispatchShadowWaves(PlayerService $player, PlanetService $origin, PlanetService $destination, PlanetService $shadow, UnitCollection $military, UnitCollection $civil): AiActionResult
    {
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);

        $mission = $fleetMissions->createNewFromPlanet(
            $origin,
            $destination->getPlanetCoordinates(),
            $destination->getPlanetType(),
            DeploymentMission::getTypeId(),
            $military,
            new Resources(),
            self::SAVE_SPEED,
        );

        $fleetMissions->createNewFromPlanet(
            $origin,
            $shadow->getPlanetCoordinates(),
            $shadow->getPlanetType(),
            DeploymentMission::getTypeId(),
            $civil,
            $this->liftableStock($player, $origin, $civil),
            self::SAVE_SPEED,
        );

        return AiActionResult::queued($mission->id);
    }

    /**
     * The origin's hulls separated by the host's own military/civil
     * classification (FS-009).
     *
     * @return array{0: UnitCollection, 1: UnitCollection}
     */
    private function splitFleet(PlanetService $origin): array
    {
        $militaryNames = array_map(static fn ($object): string => $object->machine_name, ObjectService::getMilitaryShipObjects());
        $civilNames = array_map(static fn ($object): string => $object->machine_name, ObjectService::getCivilShipObjects());

        $military = new UnitCollection();
        $civil = new UnitCollection();

        foreach ($origin->getShipUnits()->units as $entry) {
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
    private function liftableStock(PlayerService $player, PlanetService $origin, UnitCollection $fleet): Resources
    {
        $stock = new Resources($origin->metal()->get(), $origin->crystal()->get(), $origin->deuterium()->get());
        $capacity = $fleet->getTotalCargoCapacity($player);

        if ($stock->sum() <= $capacity) {
            return $stock;
        }

        return $capacity > 0 ? $stock->multiply($capacity / $stock->sum()) : new Resources();
    }
}
