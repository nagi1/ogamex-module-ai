<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiUnits;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\Planet;
use OGame\Models\UnitQueue;
use OGame\Services\ObjectService;
use OGame\Services\PlayerGameStateService;
use OGame\Services\UnitQueueService;

/**
 * Module-owned adapter over the normal OGameX unit queue.
 *
 * The decision is the module's; the queue, its requirements and its prices are the host's. The host
 * service silently returns when the amount is unaffordable, so the planner must have already asked
 * what the planet can pay for -- this adapter only refuses what the host would never accept as a
 * unit, and reports whether a queue row actually appeared.
 */
class QueueAiUnitsAction implements QueueAiUnits
{
    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
        private UnitQueueService $unitQueueService,
    ) {
    }

    public function handle(int $playerId, int $planetId, int $unitId, int $amount): AiActionResult
    {
        if (!Planet::query()->whereKey($planetId)->where('user_id', $playerId)->exists()) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }

        if ($amount < 1) {
            return AiActionResult::rejected(AiQueueActionReason::NothingQueueable);
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
            $unit = ObjectService::getObjectById($unitId);
            if ($unit->type !== GameObjectType::Ship && $unit->type !== GameObjectType::Defense) {
                return AiActionResult::rejected(AiQueueActionReason::NotAUnit);
            }

            $this->unitQueueService->add($planet, $unitId, $amount);
            $queueId = UnitQueue::query()
                ->where('planet_id', $planetId)
                ->where('object_id', $unitId)
                ->where('processed', 0)
                ->latest('id')
                ->value('id');

            return $queueId === null ? AiActionResult::rejected(AiQueueActionReason::QueueNotCreated) : AiActionResult::queued($queueId);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }
}
