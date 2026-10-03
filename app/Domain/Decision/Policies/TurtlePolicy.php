<?php

namespace Modules\AI\Domain\Decision\Policies;

use Modules\AI\Enums\AiArchetype;

class TurtlePolicy extends ConfiguredArchetypePolicy
{
    public function archetype(): AiArchetype
    {
        return AiArchetype::Turtle;
    }
}
