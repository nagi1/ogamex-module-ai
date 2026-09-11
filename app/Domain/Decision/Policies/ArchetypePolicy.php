<?php

namespace Modules\AI\Domain\Decision\Policies;

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;

/**
 * Separates a persona's legal intent set from its taste among legal intents.
 *
 * `allows` is a hard safety boundary; `preference` only influences scoring and
 * must never make a prohibited action available.
 */
interface ArchetypePolicy
{
    public function archetype(): AiArchetype;

    public function allows(AiCandidateActionType $action): bool;

    public function preference(AiCandidateActionType $action): float;
}
