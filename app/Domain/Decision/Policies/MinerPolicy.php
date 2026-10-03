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
        // Allied defence, merchant trade and relocation: how readily this temperament does each (LOOP-002/003/004).
        AiCandidateActionType::Defend->value => 0.5,
        AiCandidateActionType::Trade->value => 0.6,
        AiCandidateActionType::Relocate->value => 0.3,
        AiCandidateActionType::JumpGate->value => 0.3,
    ];

    public function archetype(): AiArchetype
    {
        return AiArchetype::Miner;
    }
}
