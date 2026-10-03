<?php

namespace Modules\AI\Domain\Decision;

/**
 * Half the combat fleet of one own body, sent to hold at an alliance co-member's planet that was just
 * attacked (LOOP-003). The planet is the destination; the host's own ACS-defend gate decides legality.
 */
readonly class QueueableDefend
{
    public function __construct(
        public int $sourcePlanetId,
        public int $targetPlanetId,
    ) {
    }
}
