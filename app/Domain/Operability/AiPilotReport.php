<?php

namespace Modules\AI\Domain\Operability;

/**
 * What a pilot window looked like, in numbers an operator has to justify.
 *
 * The report is deliberately one window rather than a running total: a pilot is judged on whether
 * a fixed cohort stayed inside its limits for a full play cycle, and a cumulative figure cannot
 * show that. Action outcomes, worker failures, scheduling lateness and provider tokens are all
 * counts over the same window, so they can be read against each other instead of apart.
 *
 * @property array<string, int> $work
 * @property array<string, int> $actions
 * @property list<float> $latencyMinutes
 * @property array<string, int> $language
 * @property array<string, mixed>|null $feedback
 */
readonly class AiPilotReport
{
    /**
     * @param array<string, int> $work
     * @param array<string, int> $actions
     * @param list<float> $latencyMinutes
     * @param array<string, int> $language
     * @param array<string, mixed>|null $feedback
     */
    public function __construct(
        public int $days = 1,
        public int $profiles = 0,
        public array $work = [],
        public array $actions = [],
        public array $latencyMinutes = [],
        public array $language = [],
        public array|null $feedback = null,
    ) {
    }

    /**
     * Nearest-rank percentile, which is what a small pilot can honestly report: with a handful of
     * sessions a night, an interpolated percentile would invent precision the sample cannot carry.
     */
    public function latencyPercentile(float $percentile): float
    {
        if ($this->latencyMinutes === []) {
            return 0.0;
        }

        $sorted = $this->latencyMinutes;
        sort($sorted);
        $rank = (int) ceil($percentile / 100 * count($sorted)) - 1;

        return $sorted[max(0, min($rank, count($sorted) - 1))];
    }
}
