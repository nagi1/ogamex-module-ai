<?php

namespace Modules\AI\Domain\Decision\Policies;

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;

class FleeterPolicy extends ConfiguredArchetypePolicy
{
    protected array $preferences = [
        AiCandidateActionType::FleetSave->value => 1.0,
        AiCandidateActionType::Recall->value => 0.9,
        AiCandidateActionType::Raid->value => 0.9,
        AiCandidateActionType::Spy->value => 0.8,
        AiCandidateActionType::QueueUnits->value => 0.6,
    ];

    public function archetype(): AiArchetype
    {
        return AiArchetype::Fleeter;
    }
}
