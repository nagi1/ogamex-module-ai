<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiResearch;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\Planet;
use OGame\Models\ResearchQueue;
use OGame\Services\ObjectService;
use OGame\Services\PlayerGameStateService;
use OGame\Services\ResearchQueueService;

/**
 * Module-owned adapter over the normal OGameX research queue.
 *
 * It exists beside the building adapter for the same reason that one does: the decision is the
 * module's, the queue, its requirements and its prices are the host's, and research differs from
 * building in one way that matters -- the laboratory that carries it, and the queue that accepts it,
 * belong to a planet while the technology belongs to the account.
 */
class QueueAiResearchAction implements QueueAiResearch
{
    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
        private ResearchQueueService $researchQueueService,
    ) {
    }

    public function handle(int $playerId, int $planetId, int $researchId): AiActionResult
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
            $technology = ObjectService::getObjectById($researchId);
            if ($technology->type !== GameObjectType::Research) {
                return AiActionResult::rejected(AiQueueActionReason::NotAResearch);
            }

            // Research is one technology at a time for the whole account, and the host derives the
            // target level from the count of rows already active for that technology. Two sessions
            // queueing the same technology therefore both aim at the same level, and the host cancels
            // the loser as "requirements not met" the moment the first one lands -- which is how 205
            // of the newest 300 rows on the grand cohort ended up cancelled. A player does not queue a
            // technology that is already in research, so neither does this.
            if ($this->researchQueueService->activeResearchQueueItemCount($player, $researchId) > 0) {
                return AiActionResult::rejected(AiQueueActionReason::AlreadyResearching);
            }

            $this->researchQueueService->add($player, $planet, $researchId);
            $queueId = ResearchQueue::query()
                ->where('planet_id', $planetId)
                ->where('object_id', $researchId)
                ->latest('id')
                ->value('id');

            return $queueId === null ? AiActionResult::rejected(AiQueueActionReason::QueueNotCreated) : AiActionResult::queued($queueId);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }
}
