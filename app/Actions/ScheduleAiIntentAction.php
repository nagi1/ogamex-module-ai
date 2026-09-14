<?php

namespace Modules\AI\Actions;

use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;

/**
 * Turns a session's selected intent into the one work item that carries it out.
 *
 * Recording the decision and acting on it stay separate steps: the decision is traceable whether or
 * not the host would allow it, and only an intent with a real executor becomes work. An intent
 * without one stays in the trace rather than becoming a work item that quietly does nothing.
 */
class ScheduleAiIntentAction
{
    private const PAYLOAD_PLANET_ID = 'planet_id';

    private const PAYLOAD_BUILDING_ID = 'building_id';

    private const PAYLOAD_REASON = 'reason';

    public function __construct(
        private QueueableBuildingPlanner $queueableBuildingPlanner,
        private AiClock $clock,
    ) {
    }

    public function handle(AiProfile $profile, AiWorkItem $sessionWorkItem, DecisionTrace $trace): void
    {
        // Every case is listed: adding a capability means deciding here where it is executed, and
        // a capability with no executor must not be published to begin with.
        match ($trace->selected->candidate->type) {
            AiCandidateActionType::Build => $this->scheduleBuild($profile, $sessionWorkItem),
            AiCandidateActionType::DoNothing,
            AiCandidateActionType::SaveResources,
            AiCandidateActionType::Research,
            AiCandidateActionType::QueueUnits,
            AiCandidateActionType::FleetSave,
            AiCandidateActionType::Spy,
            AiCandidateActionType::Raid,
            AiCandidateActionType::Colonize => null,
        };
    }

    private function scheduleBuild(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        // Legality is re-asked here rather than trusted from the decision: the host is the
        // authority, and a session that decided while the queue was free must not queue into a
        // full one.
        $plan = $this->queueableBuildingPlanner->plan($profile->player_id);
        if ($plan === null) {
            return;
        }

        // The building the plan verified travels with the intent. Re-deciding at execution time
        // would let a published capability, the schedule and the queued building name three
        // different objectives, which is exactly how an account ends up mining while it claims to
        // be reaching for a shipyard.
        //
        // The session's own id is the idempotency key: a retried session converges on one action,
        // while a later session decides again. The generation is inherited when the session knows
        // it and falls back to the column default when it does not.
        AiWorkItem::query()->firstOrCreate(
            ['idempotency_key' => 'intent:session:' . $sessionWorkItem->id],
            [
                'player_id' => $profile->player_id,
                'kind' => AiWorkKind::BuildFirstBuilding,
                'due_at' => $this->clock->now(),
                'schedule_generation' => (int) ($sessionWorkItem->schedule_generation ?? 1),
                'state' => AiWorkState::Pending,
                'payload' => [
                    self::PAYLOAD_PLANET_ID => $plan->planetId,
                    self::PAYLOAD_BUILDING_ID => $plan->buildingId,
                    self::PAYLOAD_REASON => $plan->reason,
                ],
            ],
        );
    }
}
