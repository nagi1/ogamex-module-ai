<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiBuilding;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\BuildingQueue;
use OGame\Models\Planet;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use OGame\Services\PlayerGameStateService;

/**
 * Module-owned adapter over the normal OGameX building queue.
 *
 * It refreshes a fresh actor and delegates to the existing authoritative queue
 * service; it does not add an AI contract or rule to the host application.
 */
class QueueAiBuildingAction implements QueueAiBuilding
{
    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
        private BuildingQueueService $buildingQueueService,
    ) {
    }

    public function handle(int $playerId, int $planetId, int $buildingId): AiActionResult
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
            $building = ObjectService::getObjectById($buildingId);
            if ($building->type !== GameObjectType::Building && $building->type !== GameObjectType::Station) {
                return AiActionResult::rejected(AiQueueActionReason::NotABuilding);
            }
            // The host's own predicate: the two unit-producing stations cannot be upgraded while
            // ships or defence are in production. The module names no object here.
            if ($player->isObjectUpgradeBlocked($buildingId)) {
                return AiActionResult::rejected(AiQueueActionReason::ShipyardBusy);
            }

            $this->buildingQueueService->add($planet, $buildingId);
            $queueId = BuildingQueue::query()
                ->where('planet_id', $planetId)
                ->where('object_id', $buildingId)
                ->latest('id')
                ->value('id');

            return $queueId === null ? AiActionResult::rejected(AiQueueActionReason::QueueNotCreated) : AiActionResult::queued($queueId);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }
}
