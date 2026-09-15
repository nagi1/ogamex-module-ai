<?php

namespace Modules\AI\Domain\Operability;

use Modules\AI\Domain\Review\AiScoreReport;

/**
 * What a pilot window looked like, in numbers an operator has to justify.
 *
 * The report is deliberately one window rather than a running total: a pilot is judged on whether
 * a fixed cohort stayed inside its limits for a full play cycle, and a cumulative figure cannot
 * show that. Action outcomes, worker failures, scheduling lateness, provider tokens and the
 * cohort's own score movement are all read over the same window, so they can be read against each
 * other instead of apart — which is the difference between "the plumbing works" and "the accounts
 * played well".
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
     * @param AiScoreReport $score what the sampled cohort score did over the window
     * @param array{milliseconds: float, queries: int} $readCost what the read itself cost, so a
     *        review that is quietly slow is visible in the record rather than assumed away
     * @param array<string, int> $work
     * @param array<string, int> $actions
     * @param list<float> $latencyMinutes
     * @param array<string, int> $language
     * @param array<string, mixed>|null $feedback
     */
    public function __construct(
        public AiScoreReport $score,
        public array $readCost,
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
     * The stable machine-readable shape a review parses. These field names are a contract: the
     * command's human rendering is a rendering of this answer, never a second computation of it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'days' => $this->days,
            'profiles' => $this->profiles,
            'work' => $this->work,
            'actions' => $this->actions,
            'lateness' => [
                'p50' => $this->latencyPercentile(50.0),
                'p95' => $this->latencyPercentile(95.0),
                'sessions' => count($this->latencyMinutes),
            ],
            'language' => $this->language,
            'score' => $this->score->toArray(),
            'feedback' => $this->feedback,
            'read_cost' => $this->readCost,
        ];
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
