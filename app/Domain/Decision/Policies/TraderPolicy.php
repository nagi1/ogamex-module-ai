<?php

namespace Modules\AI\Domain\Decision\Policies;

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;

class TraderPolicy extends ConfiguredArchetypePolicy
{
    protected array $preferences = [
        AiCandidateActionType::SaveResources->value => 1.0,
        AiCandidateActionType::Build->value => 0.6,
        AiCandidateActionType::Colonize->value => 0.4,
        AiCandidateActionType::FleetSave->value => 0.8,
    ];

    protected array $allowed = [AiCandidateActionType::Raid->value => false];

    public function archetype(): AiArchetype
    {
        return AiArchetype::Trader;
    }
}
