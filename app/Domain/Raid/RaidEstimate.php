<?php

namespace Modules\AI\Domain\Raid;

/**
 * What a raid candidate would do to this account, sampled from the host battle
 * engine.
 *
 * The figures are a pair on purpose: a single mean profit hid a losing majority
 * in the corpus's worst documented case, so the estimate carries both the
 * lower-tail net profit (P20) and how many sampled runs lost money. Neither
 * number is a commitment — the host battle engine stays the only authority on
 * what actually happens when the attack lands.
 */
readonly class RaidEstimate
{
    public function __construct(
        public int $samples,
        public int $losingRuns,
        public float $p20NetProfit,
    ) {
    }
}
