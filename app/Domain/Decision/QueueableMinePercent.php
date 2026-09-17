<?php

namespace Modules\AI\Domain\Decision;

/**
 * A mine-percentage change this account can apply right now: the host's own
 * percentage setter, called with a building the planner derived from host
 * production and a percentage in the host's own 0–10 scale (10 = 100%).
 */
readonly class QueueableMinePercent
{
    public function __construct(
        public int $planetId,
        public int $buildingId,
        public int $percentage,
        public string $reason,
    ) {
    }
}
