<?php

namespace Modules\AI\Domain\Conversation;

/**
 * How one contact changes what this AI thinks of the player who made it.
 *
 * Every delta is a bounded score change applied by the relationship action, not an absolute
 * value, so contact nudges a relationship instead of resetting it.
 */
readonly class ContactImpact
{
    public function __construct(
        public float $trust = 0.0,
        public float $threat = 0.0,
        public float $affinity = 0.0,
        public float $respect = 0.0,
        public float $socialImportance = 0.0,
    ) {
    }
}
