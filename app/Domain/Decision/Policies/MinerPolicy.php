<?php

namespace Modules\AI\Domain\Decision\Policies;

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;

class MinerPolicy extends ConfiguredArchetypePolicy
{
    protected array $preferences = [
        AiCandidateActionType::Build->value => 1.0,
        AiCandidateActionType::Research->value => 0.7,
        AiCandidateActionType::SaveResources->value => 0.8,
        AiCandidateActionType::FleetSave->value => 0.9,
    ];

    protected array $allowed = [AiCandidateActionType::Raid->value => false];

    public function archetype(): AiArchetype
    {
        return AiArchetype::Miner;
    }
}
