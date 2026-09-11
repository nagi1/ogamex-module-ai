<?php

namespace Modules\AI\Contracts;

use Modules\AI\Domain\Experience\ExperienceQuery;
use Modules\AI\Domain\Experience\RankedExperience;

interface ExperienceEngine
{
    /** @return list<RankedExperience> */
    public function rankSimilarExperiences(ExperienceQuery $query): array;
}
