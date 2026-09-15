<?php

namespace Modules\AI\Domain\Decision;

/**
 * An expedition this account can dispatch: slot 16 of its own system.
 *
 * The target is always the system's outer edge, so the decision carries only
 * the origin body and the coordinate; the fleet is chosen at dispatch from the
 * host's own units, never a module list.
 */
readonly class QueueableExpedition
{
    public function __construct(
        public int $planetId,
        public int $galaxy,
        public int $system,
        public int $position,
    ) {
    }
}
