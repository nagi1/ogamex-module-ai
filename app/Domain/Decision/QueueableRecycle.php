<?php

namespace Modules\AI\Domain\Decision;

/**
 * A debris field this account can harvest now.
 *
 * The origin planet carries the harvest hull; the target is a host debris field
 * (never a planet). The hull the host's recycle mission consumes is read from
 * the mission itself for the target slot, so no ship is named here.
 */
readonly class QueueableRecycle
{
    public function __construct(
        public int $planetId,
        public int $targetGalaxy,
        public int $targetSystem,
        public int $targetPosition,
        public int $targetType,
        public int $missionType,
    ) {
    }
}
