<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\RandomSource;

class DecisionEngine
{
    public function __construct(
        private CandidateActionFactory $candidateActionFactory,
        private UtilityScorer $utilityScorer,
        private RandomSource $randomSource,
    ) {
    }

    public function decide(AiProfile $profile, PerceptionSnapshot $perception, string $decisionKey): DecisionTrace
    {
        $generation = $this->candidateActionFactory->create($perception);
        $scored = $this->utilityScorer->score($profile, $generation, $decisionKey);
        $selected = $this->idleOverride(
            $profile,
            $perception,
            $scored,
            $this->utilityScorer->select($profile, $scored, $decisionKey),
            $decisionKey,
        );
        $inputHash = hash('sha256', json_encode($perception->traceInput(), JSON_THROW_ON_ERROR));

        return app()->makeWith(DecisionTrace::class, [
            'perception' => $perception,
            'candidates' => $scored,
            'selected' => $selected,
            'rejections' => $generation->rejections,
            'inputHash' => $inputHash,
        ]);
    }

    /**
     * The rare "opened the game, did nothing, closed it" moment: a small seeded,
     * skill-band-aware draw that picks DoNothing even when a real action won.
     * A save or a reaction wake always wins — variance never trades away a real
     * reaction (V2/V6 safety).
     *
     * @param array<int, ScoredCandidate> $scored
     */
    private function idleOverride(AiProfile $profile, PerceptionSnapshot $perception, array $scored, ScoredCandidate $selected, string $decisionKey): ScoredCandidate
    {
        if ($perception->fleetsaveEligible || $perception->reactionWakeAt !== null) {
            return $selected;
        }

        if ($selected->candidate->type === AiCandidateActionType::DoNothing) {
            return $selected;
        }

        if ($this->randomSource->unitInterval($profile->random_seed, $decisionKey . ':idle') >= $profile->skill_band->idleOverrideProbability()) {
            return $selected;
        }

        foreach ($scored as $candidate) {
            if ($candidate->candidate->type === AiCandidateActionType::DoNothing) {
                return $candidate;
            }
        }

        return $selected;
    }
}
