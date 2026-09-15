<?php

namespace Modules\AI\Domain\Review;

/**
 * What the cohort's public score did over one review window.
 *
 * This is the only reading of the growth curve that exists anywhere, because the host stores
 * current points and no history. The figures are deliberately few — how far the accounts moved,
 * how far apart they moved, the largest single-hour jump and whether anything was lost — because a
 * review compares one window against another, and a figure nobody can state in a sentence does not
 * get compared.
 */
readonly class AiScoreReport
{
    public function __construct(
        public bool $enabled = true,
        public int $accounts = 0,
        public int $samples = 0,
        public int $generalDeltaMin = 0,
        public int $generalDeltaMedian = 0,
        public int $generalDeltaMax = 0,
        public int $largestHourlyJump = 0,
        public int $zeroGrowthAccounts = 0,
        public int $militaryLost = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'accounts' => $this->accounts,
            'samples' => $this->samples,
            'general_delta' => [
                'min' => $this->generalDeltaMin,
                'median' => $this->generalDeltaMedian,
                'max' => $this->generalDeltaMax,
            ],
            'largest_hourly_jump' => $this->largestHourlyJump,
            'zero_growth_accounts' => $this->zeroGrowthAccounts,
            'military_lost' => $this->militaryLost,
        ];
    }
}
