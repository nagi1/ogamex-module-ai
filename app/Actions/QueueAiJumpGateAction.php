<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiJumpGate;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Planet;
use OGame\Services\JumpGateService;
use OGame\Services\PlayerGameStateService;

/**
 * Module-owned adapter over the host's Jump Gate: the same checks the jump dialog runs (gate on both
 * moons, no cooldown on either, no fleet still arriving) and then the host's own transfer and cooldown.
 */
class QueueAiJumpGateAction implements QueueAiJumpGate
{
    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
        private JumpGateService $jumpGateService,
    ) {
    }

    public function handle(int $playerId, int $sourceMoonId, int $targetMoonId): AiActionResult
    {
        $owned = Planet::query()->whereIn('id', [$sourceMoonId, $targetMoonId])->where('user_id', $playerId)->count();
        if ($owned !== 2 || $sourceMoonId === $targetMoonId) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }

        try {
            $player = $this->playerGameStateService->advance($playerId, $sourceMoonId);
            if ($player->isBanned()) {
                return AiActionResult::rejected(AiQueueActionReason::PlayerBanned);
            }
            if ($player->isInVacationMode()) {
                return AiActionResult::rejected(AiQueueActionReason::VacationMode);
            }

            $source = $this->planetServiceFactory->makeForPlayer($player, $sourceMoonId, false);
            $target = $this->planetServiceFactory->makeForPlayer($player, $targetMoonId, false);

            if ($source->getObjectLevel('jump_gate') < 1 || $target->getObjectLevel('jump_gate') < 1) {
                return AiActionResult::rejected(AiQueueActionReason::NothingQueueable);
            }
            if ($this->jumpGateService->isOnCooldown($source) || $this->jumpGateService->isOnCooldown($target)) {
                return AiActionResult::rejected(AiQueueActionReason::NothingQueueable);
            }
            if ($this->jumpGateService->hasUnprocessedArrivedFleet($source) || $this->jumpGateService->hasUnprocessedArrivedFleet($target)) {
                return AiActionResult::rejected(AiQueueActionReason::NothingQueueable);
            }

            $ships = [];
            foreach ($this->jumpGateService->getTransferableShips() as $ship) {
                $amount = $source->getObjectAmount($ship);
                if ($amount > 0) {
                    $ships[$ship] = $amount;
                }
            }
            if ($ships === []) {
                return AiActionResult::rejected(AiQueueActionReason::NoDisposableFleet);
            }

            if (!$this->jumpGateService->transferShips($source, $target, $ships)) {
                return AiActionResult::rejected(AiQueueActionReason::NothingQueueable);
            }
            $this->jumpGateService->setCooldown($source, $target);

            return AiActionResult::queued(0);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }
}
