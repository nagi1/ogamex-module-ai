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
            if (!$perception->availableActions[$capability->value]) {
                continue;
            }

            $type = $capability->actionType();
            $candidates[] = app()->makeWith(CandidateAction::class, [
                'type' => $type,
                'reason' => AiCandidateReason::publishedCapability($capability),
                'parameters' => [],
                'features' => $this->features($resourceNeed, $type === AiCandidateActionType::SaveResources ? 0.7 : 0.2, 0, 0, $perception->recoveryFactor),
                'sourceTimestamps' => $perception->sourceTimestamps,
            ]);
        }

        return $candidates;
    }

    /** @return array<int, CandidateAction> */
    private function eligibleFleetSaveCandidates(PerceptionSnapshot $perception): array
    {
        if ($perception->fleetsaveEligible) {
            return [app()->makeWith(CandidateAction::class, [
                'type' => AiCandidateActionType::FleetSave,
                'reason' => AiCandidateReason::EligibleFleetSave->value,
                'parameters' => [],
                'features' => $this->features(0, 1, 0, 0, $perception->recoveryFactor),
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
            'features' => $this->features(0, 1, 0, 0, $perception->recoveryFactor),
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
            'features' => $this->features(0, 0.7, 0, 0, $perception->recoveryFactor),
            'sourceTimestamps' => $perception->sourceTimestamps,
        ])];
    }

    /** @return array<int, CandidateAction> */
    private function eligibleExpeditionCandidates(PerceptionSnapshot $perception): array
    {
        if ($this->queueableExpeditionPlanner->plan($perception->playerId) === null) {
            return [];
        }

        return [app()->makeWith(CandidateAction::class, [
            'type' => AiCandidateActionType::Expedition,
            'reason' => AiCandidateReason::EligibleExpedition->value,
            'parameters' => [],
            'features' => $this->features(0.3, 0.6, 0, 0, $perception->recoveryFactor),
            'sourceTimestamps' => $perception->sourceTimestamps,
        ])];
    }

    /** @return array<int, CandidateAction> */
    private function eligibleTransferCandidates(PerceptionSnapshot $perception): array
    {
        if ($this->queueableTransferPlanner->plan($perception->playerId) === null) {
            return [];
        }

        return [app()->makeWith(CandidateAction::class, [
            'type' => AiCandidateActionType::Transfer,
            'reason' => AiCandidateReason::EligibleTransfer->value,
            'parameters' => [],
            'features' => $this->features(0.4, 0.3, 0, 0, $perception->recoveryFactor),
            'sourceTimestamps' => $perception->sourceTimestamps,
        ])];
    }

    private function raidCandidatesFromVisibleReports(PerceptionSnapshot $perception): CandidateGeneration
    {
        $candidates = [];
        $rejections = [];

        // The fleet raids on the storage-fill schedule, not ad hoc every
        // session (RAID-009): computed once, it gates every visible target.
        $storageReady = $this->raidPlanner->storageReady($perception->playerId);

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

            if (!$storageReady) {
                $rejections[$reportKey] = AiCandidateRejectionReason::StorageNotFull->value;
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
                'features' => $this->features(0.7, 0.1, $report['confidence'], $report['travel_cost'], $perception->recoveryFactor),
                'sourceTimestamps' => [AiCandidateReason::reportSource($report['report_id']) => date(DATE_ATOM, $report['observed_at'])],
            ]);
        }

        return app()->makeWith(CandidateGeneration::class, [
            'candidates' => $candidates,
            'rejections' => $rejections,
        ]);
    }

    private function doNothing(PerceptionSnapshot $perception): CandidateAction
    {
        return app()->makeWith(CandidateAction::class, [
            'type' => AiCandidateActionType::DoNothing,
            'reason' => AiCandidateReason::AlwaysAvailable->value,
            'parameters' => [],
            'features' => $this->features(0, 0.1, 0, 0, $perception->recoveryFactor),
            'sourceTimestamps' => $perception->sourceTimestamps,
        ]);
    }

    /** @return array{resource_need:float,safety:float,target_confidence:float,travel_cost:float,recovery:float} */
    private function features(float $resourceNeed, float $safety, float $targetConfidence, float $travelCost, float $recovery): array
    {
        return [
            'resource_need' => $resourceNeed,
            'safety' => $safety,
            'target_confidence' => $targetConfidence,
            'travel_cost' => $travelCost,
            'recovery' => $recovery,
        ];
    }
}
