<?php

namespace Modules\AI\Domain\Decision\Policies;

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;

class CasualPolicy extends ConfiguredArchetypePolicy
{
    protected array $preferences = [
        AiCandidateActionType::Build->value => 0.5,
        AiCandidateActionType::Spy->value => 0.3,
        AiCandidateActionType::DoNothing->value => 0.5,
        AiCandidateActionType::FleetSave->value => 0.7,
        // Allied defence, merchant trade and relocation: how readily this temperament does each (LOOP-002/003/004).
        AiCandidateActionType::Defend->value => 0.2,
        AiCandidateActionType::Trade->value => 0.3,
        AiCandidateActionType::Relocate->value => 0.1,
        AiCandidateActionType::JumpGate->value => 0.2,
    ];

    public function archetype(): AiArchetype
    {
        return AiArchetype::Casual;
    }
}
