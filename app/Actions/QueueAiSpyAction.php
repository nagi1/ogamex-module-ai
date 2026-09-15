<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiSpy;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\EspionageMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlayerGameStateService;

/**
 * Module-owned adapter over the host's espionage fleet path.
 *
 * The decision and the target are the module's; the mission, its legality and
 * counter-espionage are the host's. One probe is sent and deducted atomically by
 * the host's own mission start.
 */
class QueueAiSpyAction implements QueueAiSpy
{
    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
    ) {
    }

    public function handle(int $playerId, int $planetId, int $targetGalaxy, int $targetSystem, int $targetPosition, int $targetType): AiActionResult
    {
        if (!Planet::query()->whereKey($planetId)->where('user_id', $playerId)->exists()) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }

        try {
            $player = $this->playerGameStateService->advance($playerId, $planetId);

            if ($player->isBanned()) {
                return AiActionResult::rejected(AiQueueActionReason::PlayerBanned);
            }
            if ($player->isInVacationMode()) {
                return AiActionResult::rejected(AiQueueActionReason::VacationMode);
            }

            $planet = $this->planetServiceFactory->makeForPlayer($player, $planetId, false);
            $units = new UnitCollection();
            // The ship the host's own espionage mission consumes; the name comes from the mission,
            // never from a module constant.
            $probe = ObjectService::getUnitObjectByMachineName(EspionageMission::getRequiredShipMachineNames()[0]);
            $units->addUnit($probe, 1);

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            $mission = $fleetMissions->createNewFromPlanet(
                $planet,
                new Coordinate($targetGalaxy, $targetSystem, $targetPosition),
                PlanetType::from($targetType),
                EspionageMission::getTypeId(),
                $units,
                new Resources(),
                10,
            );

            return AiActionResult::queued($mission->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }
}
