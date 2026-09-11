<?php

namespace Modules\AI\Domain\Decision\Policies;

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;

abstract class ConfiguredArchetypePolicy implements ArchetypePolicy
{
    /** @var array<int, float> */
    protected array $preferences = [];

    /** @var array<int, bool> */
    protected array $allowed = [];

    public function allows(AiCandidateActionType $action): bool
    {
        return $this->allowed[$action->value] ?? true;
    }

    public function preference(AiCandidateActionType $action): float
    {
        return $this->preferences[$action->value] ?? 0.0;
    }

    abstract public function archetype(): AiArchetype;
}
