<?php

namespace Modules\AI\Domain\Raid;

/**
 * What a raid candidate would do to this account, sampled from the host battle
 * engine.
 *
 * The single figure is the lower-tail net profit (P20). Because P20 is the 20th
 * percentile, a non-positive P20 already means at least a fifth of the sampled
 * runs lost money, so a separate losing-run count would only restate the same
 * gate. The host battle engine stays the only authority on what actually
 * happens when the attack lands.
 */
readonly class RaidEstimate
{
    public function __construct(
        public int $samples,
        public float $p20NetProfit,
    ) {
    }
}
