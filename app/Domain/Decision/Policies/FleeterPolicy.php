<?php

namespace Modules\AI\Domain\Decision\Policies;

use Modules\AI\Enums\AiArchetype;

class FleeterPolicy extends ConfiguredArchetypePolicy
{
    public function archetype(): AiArchetype
    {
        return AiArchetype::Fleeter;
    }
}
