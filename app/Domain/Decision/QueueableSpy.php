<?php

namespace Modules\AI\Domain\Decision;

/**
 * A legal espionage target this account can probe right now.
 *
 * The origin planet carries the probe; the target is a foreign, non-destroyed
 * planet whose owner is neither the account itself, in vacation mode, nor the
 * protected administrator — the same three gates the host's own espionage
 * mission applies.
 */
readonly class QueueableSpy
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
