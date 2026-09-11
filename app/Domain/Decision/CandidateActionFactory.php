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
        if (!$perception->fleetsaveEligible) {
            return [];
        }

        return [app()->makeWith(CandidateAction::class, [
            'type' => AiCandidateActionType::FleetSave,
            'reason' => AiCandidateReason::EligibleFleetSave->value,
            'parameters' => [],
            'features' => $this->features(0, 1, 0, 0, $perception->recoveryFactor),
            'sourceTimestamps' => $perception->sourceTimestamps,
        ])];
    }

    private function raidCandidatesFromVisibleReports(PerceptionSnapshot $perception): CandidateGeneration
    {
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

    /** @return array{resource_need:float,energy_blocker:float,safety:float,target_confidence:float,travel_cost:float,recovery:float} */
    private function features(float $resourceNeed, float $safety, float $targetConfidence, float $travelCost, float $recovery): array
    {
        return [
            'resource_need' => $resourceNeed,
            'energy_blocker' => 0.0,
            'safety' => $safety,
            'target_confidence' => $targetConfidence,
            'travel_cost' => $travelCost,
            'recovery' => $recovery,
        ];
    }
}
