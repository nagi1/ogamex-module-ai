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
    ];

    public function archetype(): AiArchetype
    {
        return AiArchetype::Casual;
    }
}
