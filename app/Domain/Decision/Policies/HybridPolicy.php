<?php

namespace Modules\AI\Domain\Decision\Policies;

use Modules\AI\Enums\AiArchetype;

class HybridPolicy extends ConfiguredArchetypePolicy
{
    public function archetype(): AiArchetype
    {
        return AiArchetype::Hybrid;
    }
}
