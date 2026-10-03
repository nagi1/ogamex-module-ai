<?php

namespace Modules\AI\Domain\Decision;

/** An own planet on a poor slot, and the free better slot in its own system it should move to (LOOP-004). */
readonly class QueueableRelocation
{
    public function __construct(
        public int $planetId,
        public int $galaxy,
        public int $system,
        public int $position,
    ) {
    }
}
