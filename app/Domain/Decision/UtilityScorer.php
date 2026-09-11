<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Contracts\ArchetypePolicyResolver;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\RandomSource;

class UtilityScorer
{
    private const RESOURCE_NEED_WEIGHT = 30.0;

    private const ENERGY_BLOCKER_WEIGHT = 20.0;

    private const SAFETY_WEIGHT = 50.0;

    private const TARGET_CONFIDENCE_WEIGHT = 30.0;

    private const TRAVEL_COST_WEIGHT = 25.0;

    private const RECOVERY_WEIGHT = 20.0;

    private const ARCHETYPE_WEIGHT = 25.0;

    public function __construct(
        private ArchetypePolicyResolver $policyRegistry,
        private RandomSource $randomSource,
    ) {
    }

    /** @return array<int, ScoredCandidate> */
    public function score(AiProfile $profile, CandidateGeneration $generation, string $decisionKey): array
    {
        $policy = $this->policyRegistry->for($profile->archetype);
        $scored = [];
        foreach ($generation->candidates as $candidate) {
            if (!$policy->allows($candidate->type)) {
                continue;
            }

            $features = $candidate->features;
            $variation = ($this->randomSource->unitInterval($profile->random_seed, $decisionKey . ':' . $candidate->type->value) - 0.5) * $profile->skill_band->variationWeight();
            $components = [
                'resource_need' => $features['resource_need'] * self::RESOURCE_NEED_WEIGHT,
                'energy_blocker' => $features['energy_blocker'] * self::ENERGY_BLOCKER_WEIGHT,
                'safety' => $features['safety'] * self::SAFETY_WEIGHT,
                'target_confidence' => $features['target_confidence'] * self::TARGET_CONFIDENCE_WEIGHT,
                'travel_cost' => -$features['travel_cost'] * self::TRAVEL_COST_WEIGHT,
                'recovery' => $features['recovery'] * self::RECOVERY_WEIGHT,
                'archetype_preference' => $policy->preference($candidate->type) * self::ARCHETYPE_WEIGHT,
                'seeded_variation' => $variation,
            ];
            $scored[] = app()->makeWith(ScoredCandidate::class, [
                'candidate' => $candidate,
                'score' => array_sum($components),
                'components' => $components,
            ]);
        }

        // A stable secondary key prevents an otherwise identical replay from
        // changing only because PHP received candidates in a different order.
        usort($scored, static fn (ScoredCandidate $left, ScoredCandidate $right): int => $right->score <=> $left->score ?: $left->candidate->type->value <=> $right->candidate->type->value);

        return $scored;
    }

    /** @param array<int, ScoredCandidate> $candidates */
    public function select(AiProfile $profile, array $candidates, string $decisionKey): ScoredCandidate
    {
        $best = $candidates[0];
        // Skill affects choice among close options, never whether a clearly
        // superior safety or economy decision is eligible to win.
        $nearEqual = array_values(array_filter(
            $candidates,
            static fn (ScoredCandidate $candidate): bool => $candidate->score >= $best->score - $profile->skill_band->selectionMargin(),
        ));
        $index = (int) floor($this->randomSource->unitInterval($profile->random_seed, $decisionKey . ':select') * count($nearEqual));

        return $nearEqual[min($index, count($nearEqual) - 1)];
    }
}
