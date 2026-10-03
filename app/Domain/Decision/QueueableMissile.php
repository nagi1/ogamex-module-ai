<?php

namespace Modules\AI\Domain\Decision;

/**
 * A volley of interplanetary missiles from one own planet at one defended target in range: the
 * defence goes first so the fleet that follows meets a thinner wall.
 */
readonly class QueueableMissile
{
    public function __construct(
        public int $originPlanetId,
        public int $targetGalaxy,
        public int $targetSystem,
        public int $targetPosition,
        public int $targetType,
        public int $missiles,
    ) {
    }
}
