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
    ];

    public function archetype(): AiArchetype
    {
        return AiArchetype::Raider;
    }
}
