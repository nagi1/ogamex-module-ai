<?php

namespace Modules\AI\Domain\Decision\Policies;

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;

class MinerPolicy extends ConfiguredArchetypePolicy
{
    protected array $preferences = [
        AiCandidateActionType::Raid->value => 0.15,
        AiCandidateActionType::Build->value => 1.0,
        AiCandidateActionType::Research->value => 0.7,
        AiCandidateActionType::FleetSave->value => 0.9,
    ];

    public function archetype(): AiArchetype
    {
        return AiArchetype::Miner;
    }
}
