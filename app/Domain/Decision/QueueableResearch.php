<?php

namespace Modules\AI\Domain\Decision;

/**
 * A technology this account can legally research right now, on the planet that carries it.
 *
 * It has the same shape as a queueable building because it comes out of the same plan: a chain step
 * is a prerequisite, and which queue accepts it is the host's object type rather than this module's
 * opinion. The laboratory that makes it possible is on the planet it names.
 */
readonly class QueueableResearch
{
    public function __construct(
        public int $planetId,
        public int $researchId,
        public string $reason,
    ) {
    }
}
