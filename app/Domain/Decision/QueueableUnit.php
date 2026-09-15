<?php

namespace Modules\AI\Domain\Decision;

/**
 * A ship or defence piece this account can legally queue right now, on one of its planets.
 *
 * The unit and the amount are both derived from host properties and host prices: which object fills
 * a role is not a name this module keeps, and how many fit is what the host says the planet can pay
 * for. The reason names the role that selected it, so a trace can show why this unit rather than
 * another without restating the arithmetic.
 */
readonly class QueueableUnit
{
    public function __construct(
        public int $planetId,
        public int $unitId,
        public int $amount,
        public string $reason,
    ) {
    }
}
