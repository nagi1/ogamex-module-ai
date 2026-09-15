<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiFleetSave;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\DeploymentMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\PlayerGameStateService;

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

    public function handle(int $playerId, int $originPlanetId, int $destinationPlanetId): AiActionResult
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

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            $mission = $fleetMissions->createNewFromPlanet(
                $origin,
                $destination->getPlanetCoordinates(),
                $destination->getPlanetType(),
                DeploymentMission::getTypeId(),
                $origin->getShipUnits(),
                new Resources(),
                self::SAVE_SPEED,
            );

            return AiActionResult::queued($mission->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }
}
