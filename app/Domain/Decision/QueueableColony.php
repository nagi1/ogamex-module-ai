<?php

namespace Modules\AI\Domain\Decision;

/**
 * A colonisable, currently empty slot this account can legally claim right now.
 *
 * The origin planet carries the colony ship; the target is a coordinate the host
 * itself reports as empty and within this account's astrophysics reach, so the
 * module never judges a slot the host would refuse.
 */
readonly class QueueableColony
{
    public function __construct(
        public int $planetId,
        public int $galaxy,
        public int $system,
        public int $position,
        public int $missionType,
    ) {
    }
}
