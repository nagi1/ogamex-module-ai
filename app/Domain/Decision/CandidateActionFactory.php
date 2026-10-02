<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiCandidateReason;
use Modules\AI\Enums\AiCandidateRejectionReason;
use Modules\AI\Enums\AiCapability;

class CandidateActionFactory
{
    private const RESOURCE_RESERVE = 1_000;

    public function __construct(
        private readonly RaidPlanner $raidPlanner,
        private readonly QueueableExpeditionPlanner $queueableExpeditionPlanner,
        private readonly QueueableTransferPlanner $queueableTransferPlanner,
        private readonly QueueableFleetSavePlanner $queueableFleetSavePlanner,
        private readonly QueueableRecyclePlanner $queueableRecyclePlanner,
        private readonly QueueablePhalanxPlanner $queueablePhalanxPlanner,
    ) {
    }

    public function create(PerceptionSnapshot $perception): CandidateGeneration
    {
        // A safe fallback makes a missing capability an observable no-op,
        // rather than pressure to invent an action or access hidden state.
        $raidGeneration = $this->raidCandidatesFromVisibleReports($perception);

        return app()->makeWith(CandidateGeneration::class, [
            'candidates' => [
                $this->doNothing($perception),
                ...$this->publishedCapabilityCandidates($perception),
                ...$this->eligibleFleetSaveCandidates($perception),
                ...$this->eligibleRecallCandidates($perception),
                ...$this->eligibleExpeditionCandidates($perception),
                ...$this->eligibleTransferCandidates($perception),
                ...$this->eligibleRecycleCandidates($perception),
                ...$this->phalanxCandidate($perception)->candidates,
                ...$raidGeneration->candidates,
            ],
            'rejections' => $raidGeneration->rejections,
        ]);
    }

    /** @return array<int, CandidateAction> */
    private function publishedCapabilityCandidates(PerceptionSnapshot $perception): array
    {
        $resourceNeed = $perception->totalResources() > self::RESOURCE_RESERVE ? 1.0 : 0.2;
        $candidates = [];

        foreach (AiCapability::cases() as $capability) {
            // A replay scenario bypasses the perception builder, so a capability the scenario
            // predates is absent rather than false; absent means unpublished, same as the builder.
            if (!($perception->availableActions[$capability->value] ?? false)) {
                continue;
            }

            $type = $capability->actionType();
            $need = $type === AiCandidateActionType::Build
                ? $resourceNeed + $this->buildScarcityBoost($perception, $resourceNeed)
                : $resourceNeed;

            // SP8: a spy or colony dispatch the host would refuse for slot exhaustion is
            // never offered — the account checks the fleet screen before sending.
            if (($type === AiCandidateActionType::Spy || $type === AiCandidateActionType::Colonize) && $perception->fleetSlotsFree < 1) {
                continue;
            }

            // CL3: a colony the account cannot develop outranks nothing, so colonise is
            // offered only when the existing production can bring the new body online.
            if ($type === AiCandidateActionType::Colonize && !$perception->colonizeEligible) {
                continue;
            }

            $candidates[] = app()->makeWith(CandidateAction::class, [
                'type' => $type,
                'reason' => AiCandidateReason::publishedCapability($capability),
                'parameters' => [],
                'features' => $this->features($type, $need, 0, 0, $perception->recoveryFactor),
                'sourceTimestamps' => $perception->sourceTimestamps,
            ]);
        }

        return $candidates;
    }

    /**
     * The surplus is spent by the mine that grows what the account is short of, before the persona's
     * ship habit: the more one resource towers over the least, the more a build is the answer. The
     * floor ignores mild imbalance, so a safety action (a fleetsave under a visible raid) is never
     * outranked by an ordinary surplus; only a severe one — one resource hundreds of times another —
     * makes the mine the answer.
     */
    private function buildScarcityBoost(PerceptionSnapshot $perception, float $resourceNeed): float
    {
        if ($resourceNeed < 1.0) {
            return 0.0;
        }

        $metal = 0.0;
        $crystal = 0.0;
        $deuterium = 0.0;

        foreach ($perception->planets as $planet) {
            $metal += (float) ($planet['resources']['metal'] ?? 0.0);
            $crystal += (float) ($planet['resources']['crystal'] ?? 0.0);
            $deuterium += (float) ($planet['resources']['deuterium'] ?? 0.0);
        }

        $abundant = max($metal, $crystal, $deuterium);
        $scarce = min($metal, $crystal, $deuterium);

        // 0 below 10:1, 1.0 once one resource is 1,000x another: the scarce resource
        // ranks above the persona's habit only when it really is the binding constraint.
        return min(1.0, max(0.0, (log10($abundant / max(1.0, $scarce)) - 1.0) / 2.0));
    }

    /** @return array<int, CandidateAction> */
    private function eligibleFleetSaveCandidates(PerceptionSnapshot $perception): array
    {
        if ($perception->fleetsaveEligible) {
            return [app()->makeWith(CandidateAction::class, [
                'type' => AiCandidateActionType::FleetSave,
                'reason' => AiCandidateReason::EligibleFleetSave->value,
                'parameters' => [],
                'features' => $this->features(AiCandidateActionType::FleetSave, 0, 0, 0, $perception->recoveryFactor),
                'sourceTimestamps' => $perception->sourceTimestamps,
            ])];
        }

        // The proactive save (V6) is the other half: no hostile inbound, but an
        // absence ahead and a fleet worth losing. The reactive branch above
        // already owns the inbound case, so the two never compete.
        if ($perception->upcomingAbsenceMinutes === null) {
            return [];
        }

        if ($this->queueableFleetSavePlanner->proactivePlan($perception->playerId, $perception->upcomingAbsenceMinutes) === null) {
            return [];
        }

        return [app()->makeWith(CandidateAction::class, [
            'type' => AiCandidateActionType::FleetSave,
            'reason' => AiCandidateReason::ProactiveSave->value,
            'parameters' => [],
            'features' => $this->features(AiCandidateActionType::FleetSave, 0, 0, 0, $perception->recoveryFactor),
            'sourceTimestamps' => $perception->sourceTimestamps,
        ])];
    }

    /** @return array<int, CandidateAction> */
    private function eligibleRecallCandidates(PerceptionSnapshot $perception): array
    {
        if (!$perception->recallEligible) {
            return [];
        }

        return [app()->makeWith(CandidateAction::class, [
            'type' => AiCandidateActionType::Recall,
            'reason' => AiCandidateReason::EligibleRecall->value,
            'parameters' => [],
            'features' => $this->features(AiCandidateActionType::Recall, 0, 0, 0, $perception->recoveryFactor),
            'sourceTimestamps' => $perception->sourceTimestamps,
        ])];
    }

    /** @return array<int, CandidateAction> */
    private function eligibleExpeditionCandidates(PerceptionSnapshot $perception): array
    {
        if ($perception->fleetSlotsFree < 1) {
            return [];
        }

        if ($this->queueableExpeditionPlanner->plan($perception->playerId) === null) {
            return [];
        }

        return [app()->makeWith(CandidateAction::class, [
            'type' => AiCandidateActionType::Expedition,
            'reason' => AiCandidateReason::EligibleExpedition->value,
            'parameters' => [],
            'features' => $this->features(AiCandidateActionType::Expedition, 0, 0, 0, $perception->recoveryFactor),
            'sourceTimestamps' => $perception->sourceTimestamps,
        ])];
    }

    /** @return array<int, CandidateAction> */
    private function eligibleTransferCandidates(PerceptionSnapshot $perception): array
    {
        if ($perception->fleetSlotsFree < 1 || $this->queueableTransferPlanner->plan($perception->playerId) === null) {
            return [];
        }

        return [app()->makeWith(CandidateAction::class, [
            'type' => AiCandidateActionType::Transfer,
            'reason' => AiCandidateReason::EligibleTransfer->value,
            'parameters' => [],
            'features' => $this->features(AiCandidateActionType::Transfer, 0, 0, 0, $perception->recoveryFactor),
            'sourceTimestamps' => $perception->sourceTimestamps,
        ])];
    }

    /** @return array<int, CandidateAction> */
    private function eligibleRecycleCandidates(PerceptionSnapshot $perception): array
    {
        if ($perception->fleetSlotsFree < 1) {
            return [];
        }

        $plan = $this->queueableRecyclePlanner->plan($perception->playerId);
        if (!$plan instanceof QueueableRecycle) {
            return [];
        }

        return [app()->makeWith(CandidateAction::class, [
            'type' => AiCandidateActionType::Recycle,
            'reason' => AiCandidateReason::EligibleRecycle->value,
            'parameters' => [],
            'features' => $this->features(AiCandidateActionType::Recycle, $this->debrisNeed($perception, $plan->mass), 0, 0, $perception->recoveryFactor),
            'sourceTimestamps' => $perception->sourceTimestamps,
        ])];
    }

    /**
     * How much the field the plan chose is worth to this account: the mass the host still holds,
     * set against the stock the account already has. A pile larger than everything it owns is
     * worth the trip the way a full store is worth building; a scrap beside a rich account is not.
     *
     * @param float $mass metal plus crystal in the field, as the host counts it
     */
    private function debrisNeed(PerceptionSnapshot $perception, float $mass): float
    {
        if ($mass <= 0.0) {
            return 0.0;
        }

        return min(1.0, $mass / max(1.0, $perception->totalResources()));
    }

    private function raidCandidatesFromVisibleReports(PerceptionSnapshot $perception): CandidateGeneration
    {
        // SP8: no free fleet slot means no raid can be dispatched, so no target is
        // offered — the account checks the fleet screen before sending.
        if ($perception->fleetSlotsFree < 1) {
            return app()->makeWith(CandidateGeneration::class, [
                'candidates' => [],
                'rejections' => [],
            ]);
        }

        $candidates = [];
        $rejections = [];

        // Raid candidates are assembled solely from the published report
        // projection. The scorer never receives unseen defender information.
        foreach ($perception->targetReports as $report) {
            $reportKey = 'report:' . $report['report_id'];
            if (!$report['attack_permitted']) {
                $rejections[$reportKey] = AiCandidateRejectionReason::AttackNotPermitted->value;
                continue;
            }

            if ($report['expires_at'] <= $perception->observedAt->getTimestamp()) {
                $rejections[$reportKey] = AiCandidateRejectionReason::StaleTargetIntel->value;
                continue;
            }

            // A target scoring under ~⅕ of ours cannot defend its loot, so it is
            // dropped before the estimator runs (RAID-008). Fixtures built
            // without the field stay viable rather than silently disappearing.
            if (!($report['score_viable'] ?? true)) {
                $rejections[$reportKey] = AiCandidateRejectionReason::ScoreBelowViability->value;
                continue;
            }

            // The raid planner is the only authority on whether this raid can be carried out at
            // all: it applies the bashing limit, the fleet's own capacity and the profit estimate,
            // and none of those travel in the report projection. Offering a target the planner will
            // decline is how the population came to decide without ever acting -- measured on the
            // grand test at 154 of 181 raid selections producing no work item at all, a third of
            // every decision made. Asking here keeps the candidate list to raids that will actually
            // be queued, and the drop is recorded so the trace can show it rather than hide it.
            if (!$this->raidPlanner->plan($perception->playerId, (int) $report['report_id']) instanceof QueueableRaid) {
                $rejections[$reportKey] = AiCandidateRejectionReason::RaidNotViable->value;
                continue;
            }

            $candidates[] = app()->makeWith(CandidateAction::class, [
                'type' => AiCandidateActionType::Raid,
                'reason' => AiCandidateReason::FreshVisibleReport->value,
                'parameters' => ['report_id' => $report['report_id']],
                'features' => $this->features(AiCandidateActionType::Raid, 0, $report['confidence'], $report['travel_cost'], $perception->recoveryFactor),
                'sourceTimestamps' => [AiCandidateReason::reportSource($report['report_id']) => date(DATE_ATOM, $report['observed_at'])],
            ]);
        }

        return app()->makeWith(CandidateGeneration::class, [
            'candidates' => $candidates,
            'rejections' => $rejections,
        ]);
    }

    /**
     * A phalanx scan is offered when the account owns a moon with a sensor phalanx and a
     * raid target inside range: the scan runs first, and the raid decision later reads what
     * it saw. A scan needs no fleet slot — it is a host read, not a dispatch.
     */
    private function phalanxCandidate(PerceptionSnapshot $perception): CandidateGeneration
    {
        if (!$this->queueablePhalanxPlanner->plan($perception->playerId) instanceof QueueablePhalanx) {
            return app()->makeWith(CandidateGeneration::class, [
                'candidates' => [],
                'rejections' => [],
            ]);
        }

        return app()->makeWith(CandidateGeneration::class, [
            'candidates' => [app()->makeWith(CandidateAction::class, [
                'type' => AiCandidateActionType::Phalanx,
                'reason' => AiCandidateReason::PhalanxScanAvailable->value,
                'parameters' => [],
                'features' => $this->features(AiCandidateActionType::Phalanx, 0, 0, 0, $perception->recoveryFactor),
                'sourceTimestamps' => $perception->sourceTimestamps,
            ])],
            'rejections' => [],
        ]);
    }

    private function doNothing(PerceptionSnapshot $perception): CandidateAction
    {
        return app()->makeWith(CandidateAction::class, [
            'type' => AiCandidateActionType::DoNothing,
            'reason' => AiCandidateReason::AlwaysAvailable->value,
            'parameters' => [],
            'features' => $this->features(AiCandidateActionType::DoNothing, 0, 0, 0, $perception->recoveryFactor),
            'sourceTimestamps' => $perception->sourceTimestamps,
        ]);
    }

    /**
     * probe
     * The feature profile per action intent (specs/decision-doctrine.md D-table): resource_need is
     * economy pressure, safety is exposure removed, target_confidence intel quality, travel_cost
     * fuel, recovery the post-loss appetite. The host-derived values (confidence, travel_cost) arrive
     * per candidate; resource_need, safety and recovery are the floor a plan starts from, and the
     * errand's own plan replaces the floor when it can price it. Every capability a subject
     * account can queue is offered, so the choice among errands is the only question left.
     *
     * @return array{resource_need:float,safety:float,target_confidence:float,travel_cost:float,recovery:float}
     */
    private function features(AiCandidateActionType $type, float $resourceNeed, float $confidence, float $travelCost, float $recovery): array
    {
        [$need, $safety, $targetConfidence, $travel] = match ($type) {
            AiCandidateActionType::DoNothing => [0.0, 0.1, 0.0, 0.0],
            AiCandidateActionType::FleetSave => [0.0, 1.0, 0.0, 0.0],
            AiCandidateActionType::Recall => [0.0, 0.7, 0.0, 0.0],
            AiCandidateActionType::Expedition => [0.3, 0.6, 0.0, 0.0],
            AiCandidateActionType::Transfer, AiCandidateActionType::Recycle => [0.4, 0.3, 0.0, 0.0],
            AiCandidateActionType::Phalanx => [0.5, 0.2, 0.3, 0.0],
            // The planner has already proved this raid pays, so it is as pressing as a full store.
            AiCandidateActionType::Raid => [1.0, 0.1, $confidence, $travelCost],
            default => [$resourceNeed, 0.2, 0.0, 0.0],
        };

        return [
            'resource_need' => $need,
            'safety' => $safety,
            'target_confidence' => $targetConfidence,
            'travel_cost' => $travel,
            'recovery' => $recovery,
        ];
    }
}
