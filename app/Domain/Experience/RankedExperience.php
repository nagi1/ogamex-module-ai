<?php

namespace Modules\AI\Domain\Experience;

use Modules\AI\Enums\AiExperienceOutcome;

readonly class RankedExperience
{
    public function __construct(public int $caseId, public AiExperienceOutcome $outcome, public float $similarity, public float $utility, public float $uncertainty)
    {
    }
}
