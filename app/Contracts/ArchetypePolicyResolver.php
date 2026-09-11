<?php

namespace Modules\AI\Contracts;

use Modules\AI\Domain\Decision\Policies\ArchetypePolicy;
use Modules\AI\Enums\AiArchetype;

interface ArchetypePolicyResolver
{
    public function for(AiArchetype $archetype): ArchetypePolicy;
}
