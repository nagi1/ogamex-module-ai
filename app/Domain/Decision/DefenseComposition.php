<?php

namespace Modules\AI\Domain\Decision;

use OGame\GameObjects\Models\UnitObject;

/**
 * The unit an account's defence doctrine is most behind on, and how many of it this planet can
 * put in the yard now.
 *
 * Composition is split from need: this carries only *what the wall is missing* relative to its
 * doctrine, never whether the account wants more defence. The unit is the host object the doctrine
 * named, the amount is what the host says the planet can pay for, and the doctrine names the
 * source-derived ratio that produced the choice so a trace can show it.
 */
readonly class DefenseComposition
{
    public function __construct(
        public UnitObject $unit,
        public int $amount,
        public string $doctrine,
        public string $reason,
    ) {
    }
}
