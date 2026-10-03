<?php

namespace Modules\AI\Domain\Decision\Policies;

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;

class TraderPolicy extends ConfiguredArchetypePolicy
{
    protected array $preferences = [
        AiCandidateActionType::Raid->value => 0.1,
        AiCandidateActionType::Build->value => 0.6,
        AiCandidateActionType::Colonize->value => 0.4,
        AiCandidateActionType::FleetSave->value => 0.8,
        // Allied defence, merchant trade and relocation: how readily this temperament does each (LOOP-002/003/004).
        AiCandidateActionType::Defend->value => 0.3,
        AiCandidateActionType::Trade->value => 1.0,
        AiCandidateActionType::Relocate->value => 0.4,
        AiCandidateActionType::JumpGate->value => 0.2,
    ];

    public function archetype(): AiArchetype
    {
        return AiArchetype::Trader;
    }
}
