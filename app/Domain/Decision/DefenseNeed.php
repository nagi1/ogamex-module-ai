<?php

namespace Modules\AI\Domain\Decision;

/**
 * What the planet stands to lose, and the wall that covers it: the size half of the defence
 * decision.
 *
 * The value, not a unit count: how many units a wall of this worth is depends on the doctrine's
 * own shape and on the host's prices, which the composition planner owns. `reason` names what the
 * account has recently seen, so a trace can show why the wall grew rather than only that it did.
 */
readonly class DefenseNeed
{
    public function __construct(
        public float $defenceValue,
        public string $reason,
    ) {
    }
}
