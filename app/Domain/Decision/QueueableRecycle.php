<?php

namespace Modules\AI\Domain\Decision;

/**
 * A debris field this account can harvest now.
 *
 * The origin planet carries the harvest hull; the target is a host debris field
 * (never a planet). The hull the host's recycle mission consumes is read from
 * the mission itself for the target slot, so no ship is named here. The mass the
 * field still holds travels with the plan, so the decision compares this errand
 * by what it actually returns rather than a fixed taste.
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
        public float $mass = 0.0,
    ) {
    }
}
