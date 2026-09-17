<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiMinePercent;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Planet;
use OGame\Services\PlayerGameStateService;

/**
 * Module-owned adapter over the host's own mine-percentage setter.
 *
 * The decision and the percentage are the module's; whether the body exists,
 * the owner is legal and the percentage is in range are the host's —
 * `PlanetService::setBuildingPercent()` is the same validated path a player's
 * resources page uses.
 */
class QueueAiMinePercentAction implements QueueAiMinePercent
{
    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
    ) {
    }

    public function handle(int $playerId, int $planetId, int $buildingId, int $percentage): AiActionResult
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
            if (!$planet->setBuildingPercent($buildingId, $percentage)) {
                return AiActionResult::rejected(AiQueueActionReason::PercentRefused);
            }

            return AiActionResult::succeeded(AiQueueActionReason::PercentApplied);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }
}
