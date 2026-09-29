<?php

namespace Modules\AI\Ai\Doctrine;

/**
 * WIK-023 states that canceling a queued item refunds its resources.
 *
 * The statement comes from a low-confidence wiki stub, so the rule is kept as a
 * claim that planners can read rather than as behaviour they may act on.
 */
final class DefenseCancelRefundClaim
{
    public function __construct(
        public bool $refundsResources = true,
        public string $confidence = 'low',
        public bool $verified = false,
    ) {
    }

    /**
     * Queue-then-cancel may only be treated as a saving tactic once an official
     * source has confirmed that canceling really returns the resources.
     */
    public function canBeUsedForResourceSaving(): bool
    {
        return $this->verified && $this->refundsResources;
    }
}
