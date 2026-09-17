<?php

namespace Modules\AI\Domain\Decision\Policies;

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;

class TurtlePolicy extends ConfiguredArchetypePolicy
{
    protected array $preferences = [
        AiCandidateActionType::QueueUnits->value => 1.0,
        AiCandidateActionType::Build->value => 0.7,
        AiCandidateActionType::FleetSave->value => 0.6,
    ];

    protected array $allowed = [AiCandidateActionType::Raid->value => false];

    public function archetype(): AiArchetype
    {
        return AiArchetype::Turtle;
    }
}
