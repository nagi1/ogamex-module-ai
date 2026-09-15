<?php

namespace Modules\AI\Domain\Decision;

/**
 * A transport between two of the account's own bodies: the shortfall a target
 * planet cannot cover for its next level, ferried from a source that can spare
 * it. The amounts are the shipment, already rounded to what the host stores.
 */
readonly class QueueableTransfer
{
    public function __construct(
        public int $sourcePlanetId,
        public int $targetPlanetId,
        public int $metal,
        public int $crystal,
        public int $deuterium,
    ) {
    }
}
