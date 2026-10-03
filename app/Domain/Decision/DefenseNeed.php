<?php

namespace Modules\AI\Domain\Decision;

/**
 * What the planet stands to lose, and the wall that covers it: the size half of the defence
 * decision.
 *
 * The value, not a unit count: how many units a wall of this worth is depends on the doctrine's
 * own shape and on the host's prices, which the composition planner owns. `reason` names what the
 * account has recently seen, so a trace can show why the wall grew rather than only that it did.
 *
 * `currentDefenseValue` is the built wall alone: the size the evaluator compares against exposure,
 * while the ceiling and the "is this planet still bare" question count the wall already paid for.
 * A null return from the evaluator is the one answer that reaches no wall at all, so the unit
 * planner falls back to the host's own cheapest defence unit rather than leaving the planet bare.
 *
 * `defenceValue` is the value the wall is sized to, and the composition planner reads it as the
 * wall still owed, so the wall a planet stands is subtracted before the size is handed over.
 *
 * `threatBand` names the contact the need was sized against (`inbound` or `unwatched`) and `intent`
 * says whether the wall is being reinforced ahead of an attack or held against the ordinary clock.
 */
readonly class DefenseNeed
{
    public function __construct(
        public float $defenceValue,
        public string $reason,
        public float $protectedValue = 0.0,
        public float $currentDefenseValue = 0.0,
        public ?string $threatBand = null,
        public ?string $intent = null,
    ) {
    }
}
