<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiDefend;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use Modules\AI\Support\FlightFuel;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\AcsDefendMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerGameStateService;

/**
 * Module-owned adapter over the host's ACS-defend mission: half of each combat hull the source owns
 * holds at an ally's planet for an hour. Whether the target is an ally or buddy, and whether ACS is on,
 * is the host's gate; the module only keeps half the fleet at home.
 */
class QueueAiDefendAction implements QueueAiDefend
{
    /** The flight the planner quotes before it offers the same fleet: one speed, one holding time. */
    public const SPEED = 10.0;

    public const HOLDING_HOURS = 1;

    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
    ) {
    }

    public function handle(int $playerId, int $sourcePlanetId, int $targetPlanetId): AiActionResult
    {
        if (!Planet::query()->whereKey($sourcePlanetId)->where('user_id', $playerId)->exists()) {
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
            $target = $this->planetServiceFactory->make($targetPlanetId, true);
            if ($target === null) {
                return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
            }

            $fleet = $this->heldFleet($source);
            if ($fleet->units === []) {
                return AiActionResult::rejected(AiQueueActionReason::NoDisposableFleet);
            }

            if (!app(FlightFuel::class)->affordable($player, $source, $fleet, $target->getPlanetCoordinates(), self::SPEED, self::HOLDING_HOURS)) {
                return AiActionResult::rejected(AiQueueActionReason::SourceShortAtDispatch);
            }

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            $mission = $fleetMissions->createNewFromPlanet(
                $source,
                $target->getPlanetCoordinates(),
                $target->getPlanetType(),
                AcsDefendMission::getTypeId(),
                $fleet,
                new Resources(),
                self::SPEED,
                self::HOLDING_HOURS,
            );

            return AiActionResult::queued($mission->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }

    /**
     * The hulls a defence sends: half of every combat ship the body holds, so half stays home. The
     * planner quotes this exact fleet, so what it promises is what the gate dispatches.
     */
    public function heldFleet(PlanetService $source): UnitCollection
    {
        $owned = $source->getShipUnits()->toArray();
        $fleet = new UnitCollection();
        foreach (ObjectService::getMilitaryShipObjects() as $ship) {
            $send = intdiv($owned[$ship->machine_name] ?? 0, 2);
            if ($send > 0) {
                $fleet->addUnit($ship, $send);
            }
        }

        return $fleet;
    }
}
