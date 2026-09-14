<?php

namespace Modules\AI\Domain\Decision;

/**
 * A building this account can legally queue right now, on the planet the host approved.
 *
 * It is produced by the same chain planner the scheduling action runs, and the building it approved
 * travels with the intent, so a published capability, the scheduled intent and the queued building
 * cannot describe three different actions.
 */
readonly class QueueableBuilding
{
    public function __construct(
        public int $planetId,
        public int $buildingId,
        public string $reason,
    ) {
    }
}
