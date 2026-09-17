<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Actions\CurrentAiAffectIntensityAction;
use Modules\AI\Contracts\ArchetypePolicyResolver;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\RandomSource;

class UtilityScorer
{
    private const RESOURCE_NEED_WEIGHT = 30.0;

    private const SAFETY_WEIGHT = 50.0;

    private const TARGET_CONFIDENCE_WEIGHT = 30.0;

    private const TRAVEL_COST_WEIGHT = 25.0;

    private const RECOVERY_WEIGHT = 20.0;

    private const ARCHETYPE_WEIGHT = 25.0;

    public function __construct(
        private ArchetypePolicyResolver $policyRegistry,
        private RandomSource $randomSource,
        private CurrentAiAffectIntensityAction $currentAffectIntensity,
        private AiClock $clock,
    ) {
    }

    /** @return array<int, ScoredCandidate> */
    public function score(AiProfile $profile, CandidateGeneration $generation, string $decisionKey): array
    {
        $policy = $this->policyRegistry->for($profile->archetype);
        $affectWeight = (float) config('ai.cognition.affect.decision_weight', 0);
        // One mood read per decision, never per candidate, and only when the opt-in weight is
        // on: the default path performs no affect query and changes no score.
        $appetite = $affectWeight > 0.0 ? $this->threatAppetite($profile) : 0.0;
        $scored = [];
        foreach ($generation->candidates as $candidate) {
            if (!$policy->allows($candidate->type)) {
                continue;
            }

            $features = $candidate->features;
            $variation = ($this->randomSource->unitInterval($profile->random_seed, $decisionKey . ':' . $candidate->type->value) - 0.5) * $profile->skill_band->variationWeight();
            $components = [
                'resource_need' => $features['resource_need'] * self::RESOURCE_NEED_WEIGHT,
                'safety' => $features['safety'] * self::SAFETY_WEIGHT,
                'target_confidence' => $features['target_confidence'] * self::TARGET_CONFIDENCE_WEIGHT,
                'travel_cost' => -$features['travel_cost'] * self::TRAVEL_COST_WEIGHT,
                'recovery' => $features['recovery'] * self::RECOVERY_WEIGHT,
                'archetype_preference' => $policy->preference($candidate->type) * self::ARCHETYPE_WEIGHT,
                'seeded_variation' => $variation,
                'affect' => $appetite * $this->appetiteDirection($candidate->type) * $affectWeight,
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

    /**
     * The account's current mood as a signed appetite: anger presses the attack, fear presses
     * the save, and the skill band decides how fully the account plays the feeling. The value
     * stays inside [-1, 1] so the opt-in weight alone bounds what a mood can move.
     */
    private function threatAppetite(AiProfile $profile): float
    {
        $now = $this->clock->now();
        $anger = $this->currentAffectIntensity->handle($profile->player_id, AiAffectEmotion::Anger, $now);
        $fear = $this->currentAffectIntensity->handle($profile->player_id, AiAffectEmotion::Fear, $now);

        return max(-1.0, min(1.0, ($anger - $fear) * $profile->skill_band->evidenceReaction()));
    }

    /**
     * Which way a candidate type leans on the appetite: an attack wants anger, a save or
     * fleetsave wants caution, and everything else is a routine step the mood leaves alone.
     */
    private function appetiteDirection(AiCandidateActionType $type): int
    {
        return match ($type) {
            AiCandidateActionType::Raid => 1,
            AiCandidateActionType::FleetSave => -1,
            default => 0,
        };
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
