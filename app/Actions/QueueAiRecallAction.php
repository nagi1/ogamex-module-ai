<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiRecall;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Models\FleetMission;
use OGame\Services\FleetMissionService;
use OGame\Services\PlayerGameStateService;

/**
 * Module-owned adapter over the host's recall path.
 *
 * A fleetsave is a deployment that parks the fleet on another own body; the
 * host never sends it back on its own, so the recall is the other half of the
 * save. The host's cancelMission carries no ownership check (host R5), so the
 * module adds it: the deployment is resolved through the fleet-save planner's
 * own `user_id` filter, and never while a hostile is still inbound.
 */
class QueueAiRecallAction implements QueueAiRecall
{
    public function __construct(
        private PlayerGameStateService $playerGameStateService,
    ) {
    }

    public function handle(int $playerId, int $planetId): AiActionResult
    {
        try {
            $player = $this->playerGameStateService->advance($playerId, $planetId);

            if ($player->isBanned()) {
                return AiActionResult::rejected(AiQueueActionReason::PlayerBanned);
            }
            if ($player->isInVacationMode()) {
                return AiActionResult::rejected(AiQueueActionReason::VacationMode);
            }

            $plan = app(QueueableFleetSavePlanner::class)->recallPlan($playerId);
            if ($plan === null) {
                return AiActionResult::rejected(AiQueueActionReason::NoDeploymentToRecall);
            }

            // recallPlan resolved this mission a moment ago; a concurrent worker
            // may have removed it since, which findOrFail turns into the same
            // rejected dispatch as any other host refusal.
            $mission = FleetMission::query()->findOrFail($plan->missionId);

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);

            // Recalling a save while a hostile is inbound walks the fleet back
            // into the attack it was sent away from; the candidate was only
            // offered with no threat, but the dispatch re-asks what may have
            // changed in the gap.
            if ($fleetMissions->currentPlayerUnderAttack()) {
                return AiActionResult::rejected(AiQueueActionReason::UnderAttack);
            }

            $fleetMissions->cancelMission($mission);

            return AiActionResult::queued($mission->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }
}
