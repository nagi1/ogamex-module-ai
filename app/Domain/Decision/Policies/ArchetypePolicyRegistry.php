<?php

namespace Modules\AI\Domain\Decision\Policies;

use LogicException;
use Modules\AI\Enums\AiArchetype;

class ArchetypePolicyRegistry
{
    /** @var array<int, ArchetypePolicy> */
    private array $policies = [];

    /** @param iterable<ArchetypePolicy> $policies */
    public function __construct(iterable $policies)
    {
        foreach ($policies as $policy) {
            $this->policies[$policy->archetype()->value] = $policy;
        }
    }

    public function for(AiArchetype $archetype): ArchetypePolicy
    {
        if (!isset($this->policies[$archetype->value])) {
            throw new LogicException('No policy registered for ' . $archetype->name);
        }

        return $this->policies[$archetype->value];
    }
}
