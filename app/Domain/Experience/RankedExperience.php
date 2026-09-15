<?php

namespace Modules\AI\Domain\Experience;

use Modules\AI\Enums\AiExperienceOutcome;

readonly class RankedExperience
{
    public function __construct(
        public int $caseId,
        public AiExperienceOutcome $outcome,
        public float $similarity,
        public float $utility,
        public float $uncertainty,
        // The external driver's own similarity score, present only when it actually ranked
        // the casebase. The native similarity stays the canonical figure the decision
        // policy weighs.
        public float|null $driverSimilarity = null,
    ) {
    }
}
