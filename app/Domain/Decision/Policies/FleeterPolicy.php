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
        // Allied defence, merchant trade and relocation: how readily this temperament does each (LOOP-002/003/004).
        AiCandidateActionType::Defend->value => 0.6,
        AiCandidateActionType::Trade->value => 0.3,
        AiCandidateActionType::Relocate->value => 0.3,
        AiCandidateActionType::JumpGate->value => 0.4,
    ];

    public function archetype(): AiArchetype
    {
        return AiArchetype::Fleeter;
    }
}
