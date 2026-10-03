<?php

namespace Modules\AI\Domain\Decision\Policies;

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;

class HybridPolicy extends ConfiguredArchetypePolicy
{
    protected array $preferences = [
        AiCandidateActionType::Build->value => 0.8,
        AiCandidateActionType::Raid->value => 0.55,
        AiCandidateActionType::QueueUnits->value => 0.5,
        AiCandidateActionType::FleetSave->value => 0.8,
    ];

    public function archetype(): AiArchetype
    {
        return AiArchetype::Hybrid;
    }
}
