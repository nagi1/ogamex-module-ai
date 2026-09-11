<?php

namespace Modules\AI\Domain\Cognition;

use Modules\AI\Enums\AiArchetype;

readonly class ObservedStimulus
{
    public function __construct(
        public AiArchetype $archetype,
        public float $harm,
        public float $aid,
        public float $threat,
        public float $relationshipTrust,
    ) {
    }
}
