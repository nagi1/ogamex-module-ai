<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\QueueableColony;
use Modules\AI\Domain\Decision\QueueableColonyPlanner;
use Modules\AI\Domain\Decision\QueueableExpedition;
use Modules\AI\Domain\Decision\QueueableExpeditionPlanner;
use Modules\AI\Domain\Decision\QueueableFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Domain\Decision\QueueableMinePercent;
use Modules\AI\Domain\Decision\QueueableMinePercentPlanner;
use Modules\AI\Domain\Decision\QueueablePhalanx;
use Modules\AI\Domain\Decision\QueueablePhalanxPlanner;
use Modules\AI\Domain\Decision\QueueableRaid;
use Modules\AI\Domain\Decision\QueueableRecall;
use Modules\AI\Domain\Decision\QueueableRecycle;
use Modules\AI\Domain\Decision\QueueableRecyclePlanner;
use Modules\AI\Domain\Decision\QueueableResearch;
use Modules\AI\Domain\Decision\QueueableSpy;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Domain\Decision\QueueableTransfer;
use Modules\AI\Domain\Decision\QueueableTransferPlanner;
use Modules\AI\Domain\Decision\QueueableUnit;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiStopReason;
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

    private const PAYLOAD_RESEARCH_ID = 'research_id';

    private const PAYLOAD_UNIT_ID = 'unit_id';

    private const PAYLOAD_AMOUNT = 'amount';

    private const PAYLOAD_GALAXY = 'galaxy';

    private const PAYLOAD_SYSTEM = 'system';

    private const PAYLOAD_POSITION = 'position';

    private const PAYLOAD_MISSION_TYPE = 'mission_type';

    private const PAYLOAD_LAUNCH_UNITS = 'launch_units';

    private const PAYLOAD_DESTINATION_PLANET_ID = 'destination_planet_id';

    private const PAYLOAD_SHADOW_DESTINATION_PLANET_ID = 'shadow_destination_planet_id';

    private const PAYLOAD_TARGET_GALAXY = 'target_galaxy';

    private const PAYLOAD_TARGET_SYSTEM = 'target_system';

    private const PAYLOAD_TARGET_POSITION = 'target_position';

    private const PAYLOAD_TARGET_TYPE = 'target_type';

    private const PAYLOAD_PROBE_COUNT = 'probe_count';

    private const PAYLOAD_SOURCE_PLANET_ID = 'source_planet_id';

    private const PAYLOAD_TARGET_PLANET_ID = 'target_planet_id';

    private const PAYLOAD_METAL = 'metal';

    private const PAYLOAD_CRYSTAL = 'crystal';

    private const PAYLOAD_DEUTERIUM = 'deuterium';

    private const PAYLOAD_REASON = 'reason';

    private const PAYLOAD_SPEED = 'speed';

    private const PAYLOAD_JUMP_GATE_PLANET_ID = 'jump_gate_planet_id';

    private const PAYLOAD_PERCENTAGE = 'percentage';

    /**
     * The gap between one planet's order and the next in a login, the way a player clicks through
     * planets. It also keeps a session's intents from reaching the workers in the same instant, where
     * all but one lose the per-player lock and wait out a retry.
     */
    private const SECONDS_BETWEEN_PLANETS = 20;

    public function __construct(
        private QueueableBuildingPlanner $queueableBuildingPlanner,
        private QueueableUnitPlanner $queueableUnitPlanner,
        private QueueableColonyPlanner $queueableColonyPlanner,
        private QueueableExpeditionPlanner $queueableExpeditionPlanner,
        private QueueableFleetSavePlanner $queueableFleetSavePlanner,
        private QueueableSpyPlanner $queueableSpyPlanner,
        private QueueableTransferPlanner $queueableTransferPlanner,
        private QueueableRecyclePlanner $queueableRecyclePlanner,
        private QueueableMinePercentPlanner $queueableMinePercentPlanner,
        private QueueablePhalanxPlanner $queueablePhalanxPlanner,
        private RaidPlanner $raidPlanner,
        private AiClock $clock,
    ) {
    }

    /**
     * Writes the one work item that carries the intent out, keyed by the session's
     * own id: a retried session converges on one action, a later session decides
     * again. The payload is whatever the plan approved, so the schedule and the
     * executor always name the same objective.
     *
     * @param array<string, mixed> $payload
     */
    private function enqueue(AiProfile $profile, AiWorkItem $sessionWorkItem, AiWorkKind $kind, array $payload, ?CarbonImmutable $dueAt = null, string $keySuffix = ''): void
    {
        AiWorkItem::query()->firstOrCreate(
            ['idempotency_key' => 'intent:session:' . $sessionWorkItem->id . $keySuffix],
            [
                'player_id' => $profile->player_id,
                'kind' => $kind,
                'due_at' => $dueAt ?? $this->clock->now(),
                'schedule_generation' => (int) ($sessionWorkItem->schedule_generation ?? 1),
                'state' => AiWorkState::Pending,
                'payload' => $payload,
            ],
        );
    }

    public function handle(AiProfile $profile, AiWorkItem $sessionWorkItem, DecisionTrace $trace): void
    {
        // Every case is listed: adding a capability means deciding here where it is executed, and
        // a capability with no executor must not be published to begin with.
        $type = $trace->selected->candidate->type;
        // A player refills the build queues and the lab every login before turning to the shipyard or
        // the fleet; choosing a raid or a ship must not leave nine planets idle until the next session.
        $economySteps = $this->fillsEconomy($type) && $this->economyOffered($trace)
            ? $this->fillQueues($profile, $sessionWorkItem, ':economy')
            : 0;

        match ($type) {
            AiCandidateActionType::Build, AiCandidateActionType::Research => $this->fillQueues($profile, $sessionWorkItem, ''),
            // The shipyard gets what the buildings leave, so its order waits until they are placed:
            // the host cancels a building it cannot pay for, and a ship order placed first would cause it.
            AiCandidateActionType::QueueUnits => $this->scheduleUnits($profile, $sessionWorkItem, $this->clock->now()->addSeconds($economySteps * self::SECONDS_BETWEEN_PLANETS)),
            AiCandidateActionType::Colonize => $this->scheduleColony($profile, $sessionWorkItem),
            AiCandidateActionType::Expedition => $this->scheduleExpedition($profile, $sessionWorkItem),
            AiCandidateActionType::Transfer => $this->scheduleTransfer($profile, $sessionWorkItem),
            AiCandidateActionType::Recycle => $this->scheduleRecycle($profile, $sessionWorkItem),
            AiCandidateActionType::FleetSave => $this->scheduleFleetSave($profile, $sessionWorkItem),
            AiCandidateActionType::Recall => $this->scheduleRecall($profile, $sessionWorkItem),
            AiCandidateActionType::Spy => $this->scheduleSpy($profile, $sessionWorkItem),
            AiCandidateActionType::Raid => $this->scheduleRaid($profile, $sessionWorkItem, $trace),
            AiCandidateActionType::ThrottleMine => $this->scheduleMinePercent($profile, $sessionWorkItem),
            AiCandidateActionType::Phalanx => $this->schedulePhalanx($profile, $sessionWorkItem),
            AiCandidateActionType::DoNothing => $this->recordQuietDecision($profile, $trace),
        };
    }

    /**
     * Whether the economy is filled before the selected action. Transfers and saves move the stock
     * away, so a building priced against it would be cancelled; Build and Research fill it themselves;
     * an idle session is a player who did not log in to play.
     */
    private function fillsEconomy(AiCandidateActionType $type): bool
    {
        return match ($type) {
            AiCandidateActionType::QueueUnits,
            AiCandidateActionType::Spy,
            AiCandidateActionType::Raid,
            AiCandidateActionType::Expedition,
            AiCandidateActionType::Recycle,
            AiCandidateActionType::Recall,
            AiCandidateActionType::Phalanx,
            AiCandidateActionType::ThrottleMine,
            AiCandidateActionType::Colonize => true,
            default => false,
        };
    }

    /** Only what the engine offered this session, so legality and persona policy stay its own. */
    private function economyOffered(DecisionTrace $trace): bool
    {
        foreach ($trace->candidates as $scored) {
            if (in_array($scored->candidate->type, [AiCandidateActionType::Build, AiCandidateActionType::Research], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One intent per queue the planner found free and payable: a building per planet and one
     * technology. Legality is re-asked here rather than trusted from the decision, and the step the
     * planner verified travels with the intent, so the schedule and the executor name one objective.
     * The first step keeps the session's own key, so a retried session converges on the same work.
     */
    private function fillQueues(AiProfile $profile, AiWorkItem $sessionWorkItem, string $keyPrefix): int
    {
        $steps = $this->queueableBuildingPlanner->steps($profile->player_id);
        foreach ($steps as $index => $step) {
            [$kind, $payload] = $step instanceof QueueableResearch
                ? [AiWorkKind::QueueResearch, [self::PAYLOAD_RESEARCH_ID => $step->researchId]]
                : [AiWorkKind::BuildFirstBuilding, [self::PAYLOAD_BUILDING_ID => $step->buildingId]];

            $this->enqueue($profile, $sessionWorkItem, $kind, [
                self::PAYLOAD_PLANET_ID => $step->planetId,
                ...$payload,
                self::PAYLOAD_REASON => $step->reason,
            ], $this->clock->now()->addSeconds($index * self::SECONDS_BETWEEN_PLANETS), $keyPrefix . ($index === 0 ? '' : ':' . $index));
        }

        return count($steps);
    }

    /**
     * W8-L7: a quiet session leaves a counter naming why nothing else was available, so the
     * review loop can read "why is the population quiet today" from the same artifact every
     * other refusal uses. No new table.
     */
    private function recordQuietDecision(AiProfile $profile, DecisionTrace $trace): void
    {
        app(RecordAiStopReasonAction::class)->handle(AiStopReason::QuietDecision, [
            'player_id' => $profile->player_id,
            'candidates' => count($trace->candidates),
            'rejections' => count($trace->rejections),
        ]);
    }

    /**
     * A unit the plan approved travels with the intent, hull and amount.
     *
     * Re-deciding at execution time would let a published capability, the schedule and the queued
     * unit name three different objectives, which is how an account ends up building combat ships
     * while it claims to be assembling cargo.
     */
    private function scheduleUnits(AiProfile $profile, AiWorkItem $sessionWorkItem, CarbonImmutable $dueAt): void
    {
        $plan = $this->queueableUnitPlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableUnit) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::QueueUnits, [
            self::PAYLOAD_PLANET_ID => $plan->planetId,
            self::PAYLOAD_UNIT_ID => $plan->unitId,
            self::PAYLOAD_AMOUNT => $plan->amount,
            self::PAYLOAD_REASON => $plan->reason,
        ], $dueAt);
    }

    /**
     * The same for a colony the plan approved: the origin planet and the empty
     * slot travel with the intent, so the published capability and the launched
     * mission name the same destination.
     */
    private function scheduleColony(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableColonyPlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableColony) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Colonize, [
            self::PAYLOAD_PLANET_ID => $plan->planetId,
            self::PAYLOAD_GALAXY => $plan->galaxy,
            self::PAYLOAD_SYSTEM => $plan->system,
            self::PAYLOAD_POSITION => $plan->position,
            self::PAYLOAD_MISSION_TYPE => $plan->missionType,
            self::PAYLOAD_REASON => 'colony:' . $plan->galaxy . ':' . $plan->system . ':' . $plan->position,
        ]);
    }

    /**
     * The same for an expedition the plan approved: the origin body and the
     * slot-16 coordinate travel with the intent, re-planned so a full slot or a
     * missing disposable ship never becomes work.
     */
    private function scheduleExpedition(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableExpeditionPlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableExpedition) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Expedition, [
            self::PAYLOAD_PLANET_ID => $plan->planetId,
            self::PAYLOAD_GALAXY => $plan->galaxy,
            self::PAYLOAD_SYSTEM => $plan->system,
            self::PAYLOAD_POSITION => $plan->position,
            self::PAYLOAD_REASON => 'expedition:' . $plan->galaxy . ':' . $plan->system . ':' . $plan->position,
        ]);
    }

    /**
     * The same for a transfer the plan approved: source, target and the shipment travel with the
     * intent, so the ferry funds the body the session saw rather than a re-decided shortfall.
     */
    private function scheduleTransfer(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableTransferPlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableTransfer) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Transfer, [
            self::PAYLOAD_SOURCE_PLANET_ID => $plan->sourcePlanetId,
            self::PAYLOAD_TARGET_PLANET_ID => $plan->targetPlanetId,
            self::PAYLOAD_METAL => $plan->metal,
            self::PAYLOAD_CRYSTAL => $plan->crystal,
            self::PAYLOAD_DEUTERIUM => $plan->deuterium,
            self::PAYLOAD_REASON => 'transfer:' . $plan->sourcePlanetId . ':' . $plan->targetPlanetId,
        ]);
    }

    /**
     * The same for a debris field the plan approved: the origin body and the field
     * coordinate travel with the intent, so the harvest goes where the session saw.
     */
    private function scheduleRecycle(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableRecyclePlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableRecycle) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Recycle, [
            self::PAYLOAD_PLANET_ID => $plan->planetId,
            self::PAYLOAD_TARGET_GALAXY => $plan->targetGalaxy,
            self::PAYLOAD_TARGET_SYSTEM => $plan->targetSystem,
            self::PAYLOAD_TARGET_POSITION => $plan->targetPosition,
            self::PAYLOAD_TARGET_TYPE => $plan->targetType,
            self::PAYLOAD_MISSION_TYPE => $plan->missionType,
            self::PAYLOAD_REASON => 'recycle:' . $plan->targetGalaxy . ':' . $plan->targetSystem . ':' . $plan->targetPosition,
        ]);
    }

    /**
     * The same for a fleetsave the plan approved: the threatened planet and the
     * destination travel with the intent, so the save moves the fleet to the
     * planet the session saw rather than a re-decided one.
     */
    private function scheduleFleetSave(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableFleetSavePlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableFleetSave) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::FleetSave, [
            self::PAYLOAD_PLANET_ID => $plan->originPlanetId,
            self::PAYLOAD_DESTINATION_PLANET_ID => $plan->destinationPlanetId,
            self::PAYLOAD_SHADOW_DESTINATION_PLANET_ID => $plan->shadowDestinationPlanetId,
            self::PAYLOAD_MISSION_TYPE => $plan->missionType,
            self::PAYLOAD_TARGET_GALAXY => $plan->harvestGalaxy,
            self::PAYLOAD_TARGET_SYSTEM => $plan->harvestSystem,
            self::PAYLOAD_TARGET_POSITION => $plan->harvestPosition,
            self::PAYLOAD_JUMP_GATE_PLANET_ID => $plan->jumpGatePlanetId,
            self::PAYLOAD_SPEED => $plan->speed,
            self::PAYLOAD_REASON => 'fleetsave',
        ]);
    }

    /**
     * A recall names no target: the parked deployment is re-planned here, and
     * only a real in-flight save becomes work. The planet travels with the
     * intent so the dispatch advances the body the deployment left from.
     */
    private function scheduleRecall(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableFleetSavePlanner->recallPlan($profile->player_id);
        if (!$plan instanceof QueueableRecall) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Recall, [
            self::PAYLOAD_PLANET_ID => $plan->planetId,
            self::PAYLOAD_REASON => 'recall',
        ], CarbonImmutable::createFromTimestamp($plan->recallAt));
    }

    /**
     * The same for an espionage target the plan approved: the origin planet and
     * the target travel with the intent, so the probe goes where the session saw.
     */
    private function scheduleSpy(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableSpyPlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableSpy) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Spy, [
            self::PAYLOAD_PLANET_ID => $plan->planetId,
            self::PAYLOAD_TARGET_GALAXY => $plan->targetGalaxy,
            self::PAYLOAD_TARGET_SYSTEM => $plan->targetSystem,
            self::PAYLOAD_TARGET_POSITION => $plan->targetPosition,
            self::PAYLOAD_TARGET_TYPE => $plan->targetType,
            self::PAYLOAD_MISSION_TYPE => $plan->missionType,
            self::PAYLOAD_PROBE_COUNT => $plan->probeCount,
            self::PAYLOAD_REASON => 'spy:' . $plan->targetGalaxy . ':' . $plan->targetSystem . ':' . $plan->targetPosition,
        ]);
    }

    /**
     * A phalanx scan of one raid target: the moon and the target travel with the intent,
     * so the scan runs against what the session saw.
     */
    private function schedulePhalanx(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueablePhalanxPlanner->plan($profile->player_id);
        if (!$plan instanceof QueueablePhalanx) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Phalanx, [
            self::PAYLOAD_PLANET_ID => $plan->moonPlanetId,
            self::PAYLOAD_TARGET_PLANET_ID => $plan->targetPlanetId,
            self::PAYLOAD_REASON => 'phalanx:' . $plan->targetPlanetId,
        ]);
    }

    /**
     * A mine-percentage change travels as its own intent: the planet, the mine
     * and the percentage the planner derived from the host's own numbers.
     */
    private function scheduleMinePercent(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableMinePercentPlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableMinePercent) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::SetMinePercent, [
            self::PAYLOAD_PLANET_ID => $plan->planetId,
            self::PAYLOAD_BUILDING_ID => $plan->buildingId,
            self::PAYLOAD_PERCENTAGE => $plan->percentage,
            self::PAYLOAD_REASON => $plan->reason,
        ]);
    }

    /**
     * The same for a raid the session selected: the report the decision named is
     * re-planned through the profit test and bashing limit, and the target that
     * passed travels with the intent.
     */
    private function scheduleRaid(AiProfile $profile, AiWorkItem $sessionWorkItem, DecisionTrace $trace): void
    {
        $reportId = (int) ($trace->selected->candidate->parameters['report_id'] ?? 0);
        if ($reportId === 0) {
            return;
        }

        $plan = $this->raidPlanner->plan($profile->player_id, $reportId);
        if (!$plan instanceof QueueableRaid) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Raid, [
            self::PAYLOAD_PLANET_ID => $plan->originPlanetId,
            self::PAYLOAD_TARGET_GALAXY => $plan->targetGalaxy,
            self::PAYLOAD_TARGET_SYSTEM => $plan->targetSystem,
            self::PAYLOAD_TARGET_POSITION => $plan->targetPosition,
            self::PAYLOAD_TARGET_TYPE => $plan->targetType,
            self::PAYLOAD_MISSION_TYPE => $plan->missionType,
            self::PAYLOAD_LAUNCH_UNITS => $plan->launchUnits,
            self::PAYLOAD_REASON => 'raid:' . $reportId,
        ]);
    }
}
