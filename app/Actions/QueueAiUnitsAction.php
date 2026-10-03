<?php

namespace Modules\AI\Actions;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\AI\Contracts\QueueAiUnits;
use Modules\AI\Domain\Decision\DefenseCompositionPlanner;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\Planet;
use OGame\Models\UnitQueue;
use OGame\Services\ObjectService;
use OGame\Services\PlayerGameStateService;
use OGame\Services\PlayerService;
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

            // The host's add() returns silently, with nothing queued, for a unit whose requirements or
            // character class the planet does not meet: ask both first so the refusal has a name
            // instead of reading as queue_not_created.
            if (!ObjectService::objectRequirementsMet($unit->machine_name, $planet) || !ObjectService::objectCharacterClassMet($unit->machine_name, $planet)) {
                return AiActionResult::rejected(AiQueueActionReason::NothingQueueable);
            }

            if ($unit->type === GameObjectType::Defense) {
                return $this->queueDefence($player, $planetId, $unitId, $amount);
            }

            // Priced a session ago: the host's add() silently ignores a batch the planet can no longer
            // pay for whole, so the order is what it can pay for now, as the defence path does.
            $amount = min($amount, ObjectService::getObjectMaxBuildAmount($unit->machine_name, $planet, true));
            if ($amount < 1) {
                return AiActionResult::rejected(AiQueueActionReason::NothingQueueable);
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

    /**
     * The defence order, trimmed to what the planet's standing wall has left of the behaviour file's
     * ceiling, with the reading and the writing under one lock.
     *
     * The planner refuses a wall at the ceiling, but a session backlog runs several intents on the
     * same planet at once and each reads the same shortfall before any of them reaches the yard, so
     * the ceiling is applied where the units are written. The planet row is locked across the read
     * and the write, so a worker that waited sees what the one before it bought (live 2 Oct 2026: a
     * planet the file caps at 20,000 units held 24,900, one batch per concurrent worker).
     */
    private function queueDefence(PlayerService $player, int $planetId, int $unitId, int $amount): AiActionResult
    {
        return DB::transaction(function () use ($player, $planetId, $unitId, $amount): AiActionResult {
            DB::table('planets')->where('id', $planetId)->lockForUpdate()->value('id');

            $planet = $this->planetServiceFactory->makeForPlayer($player, $planetId, false);
            $remaining = app(DefenseCompositionPlanner::class)->remainingCeiling($planet);
            $amount = $remaining === null ? $amount : min($amount, max(0, $remaining));
            // The order was priced a session ago and other work on this planet has spent the balance
            // since: the host's add() returns silently for a batch it cannot pay for whole, so the wall
            // never appeared while the planet stayed naked (live 2 Oct 2026: 4 x QueueUnits refused with
            // queue_not_created). What this planet can pay for now is the order.
            $amount = min($amount, ObjectService::getObjectMaxBuildAmount(ObjectService::getObjectById($unitId)->machine_name, $planet, true));
            if ($amount < 1) {
                return AiActionResult::rejected(AiQueueActionReason::NothingQueueable);
            }

            $this->unitQueueService->add($planet, $unitId, $amount);
            $queueId = UnitQueue::query()
                ->where('planet_id', $planetId)
                ->where('object_id', $unitId)
                ->where('processed', 0)
                ->latest('id')
                ->value('id');

            return $queueId === null ? AiActionResult::rejected(AiQueueActionReason::QueueNotCreated) : AiActionResult::queued($queueId);
        });
    }
}
