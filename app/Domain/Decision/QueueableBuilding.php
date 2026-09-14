<?php

namespace Modules\AI\Domain\Decision;

/**
 * A building this account can legally queue right now, on the planet the host approved.
 *
 * The building comes from the same chooser the executor calls, so a published capability and the
 * queued intent cannot describe different actions.
 */
readonly class QueueableBuilding
{
    public function __construct(
        public int $planetId,
        public int $buildingId,
    ) {
    }
}
