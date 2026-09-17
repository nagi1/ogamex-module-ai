<?php

namespace Modules\AI\Domain\Raid;

/**
 * What a raid candidate would do to this account, sampled from the host battle
 * engine.
 *
 * `p20NetProfit` and `p20Loot` are lower-tail (P20) quantiles from the same
 * seeded runs: net gates survival-worth (a non-positive value already means at
 * least a fifth of the runs lost money) and loot gates worth-flying (the host's
 * own cargo-constrained, fill-ordered plunder against the fuel tier). `pWin` is
 * the fraction of runs the attacking fleet survived (not wiped); the fleet-loss
 * rate is its complement `1 - pWin`. The host battle engine stays the only
 * authority on what actually happens when the attack lands.
 */
readonly class RaidEstimate
{
    public function __construct(
        public int $samples,
        public float $p20NetProfit,
        public float $p20Loot,
        public float $pWin,
    ) {
    }
}
