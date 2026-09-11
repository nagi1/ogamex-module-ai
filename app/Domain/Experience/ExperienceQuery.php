<?php

namespace Modules\AI\Domain\Experience;

use Modules\AI\Enums\AiExperienceCaseFamily;

readonly class ExperienceQuery
{
    /** @param array<string, int|float|string|null> $features */
    public function __construct(public int $playerId, public AiExperienceCaseFamily $family, public string $featureVersion, public string $rulesetVersion, public array $features, public int $limit = 5)
    {
    }
}
