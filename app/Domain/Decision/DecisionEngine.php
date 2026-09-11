<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Models\AiProfile;

class DecisionEngine
{
    public function __construct(
        private CandidateActionFactory $candidateActionFactory,
        private UtilityScorer $utilityScorer,
    ) {
    }

    public function decide(AiProfile $profile, PerceptionSnapshot $perception, string $decisionKey): DecisionTrace
    {
        $generation = $this->candidateActionFactory->create($perception);
        $scored = $this->utilityScorer->score($profile, $generation, $decisionKey);
        $selected = $this->utilityScorer->select($profile, $scored, $decisionKey);
        $inputHash = hash('sha256', json_encode($perception->traceInput(), JSON_THROW_ON_ERROR));

        return app()->makeWith(DecisionTrace::class, [
            'perception' => $perception,
            'candidates' => $scored,
            'selected' => $selected,
            'rejections' => $generation->rejections,
            'inputHash' => $inputHash,
        ]);
    }
}
