<?php

namespace Modules\AI\Domain\Decision\Policies;

use Modules\AI\Enums\AiArchetype;

class MinerPolicy extends ConfiguredArchetypePolicy
{
    public function archetype(): AiArchetype
    {
        return AiArchetype::Miner;
    }
}
