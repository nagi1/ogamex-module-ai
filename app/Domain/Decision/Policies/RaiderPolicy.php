<?php

namespace Modules\AI\Domain\Decision\Policies;

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;

class RaiderPolicy extends ConfiguredArchetypePolicy
{
    protected array $preferences = [
        AiCandidateActionType::Raid->value => 1.0,
        AiCandidateActionType::Spy->value => 0.9,
        AiCandidateActionType::FleetSave->value => 0.9,
        AiCandidateActionType::Recycle->value => 0.7,
        AiCandidateActionType::QueueUnits->value => 0.6,
        // Allied defence, merchant trade and relocation: how readily this temperament does each (LOOP-002/003/004).
        AiCandidateActionType::Defend->value => 0.4,
        AiCandidateActionType::Trade->value => 0.3,
        AiCandidateActionType::Relocate->value => 0.3,
    ];

    public function archetype(): AiArchetype
    {
        return AiArchetype::Raider;
    }
}
