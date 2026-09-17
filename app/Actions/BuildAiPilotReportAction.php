<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use Modules\AI\Domain\Operability\AiPilotReport;
use Modules\AI\Domain\Review\AiScoreReport;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiLanguageRequest;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiScoreSample;
use Modules\AI\Models\AiUsageReservation;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use RuntimeException;

/**
 * Builds the pilot report an operator shows: outcomes, failures, lateness, cost and growth for one
 * window.
 *
 * Everything here is read from records the module already keeps, which is what makes the report
 * evidence rather than a claim about the population. Human feedback is the one part the module
 * cannot measure, so it is read from a file the operator supplies and reported as absent when it
 * is missing instead of being filled in with an impression.
 */
class BuildAiPilotReportAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(int $days, string|null $feedbackPath = null): AiPilotReport
    {
        $window = max(1, $days);
        $connection = DB::connection();
        $wasLogging = $connection->logging();
        $connection->enableQueryLog();
        $queriesBefore = count($connection->getQueryLog());
        $startedAt = microtime(true);
        $readings = [];
        $readCost = ['milliseconds' => 0.0, 'queries' => 0];

        try {
            $now = $this->clock->now();
            $from = $now->subDays($window);
            $completed = AiWorkItem::query()
                ->where('state', AiWorkState::Completed)
                ->whereBetween('updated_at', [$from, $now])
                ->get(['due_at', 'updated_at']);

            $readings = [
                'profiles' => AiProfile::query()->where('enabled', true)->count(),
                'work' => [
                    'created' => AiWorkItem::query()->whereBetween('created_at', [$from, $now])->count(),
                    'completed' => $completed->count(),
                    'retried' => AiWorkItem::query()->whereBetween('created_at', [$from, $now])->where('attempts', '>', 1)->count(),
                    'stuck' => AiWorkItem::query()
                        ->where('state', AiWorkState::Leased)
                        ->where('lease_until', '<', $now)
                        ->count(),
                ],
                'actions' => $this->actions($from, $now),
                'latencyMinutes' => $this->lateness($completed),
                'language' => $this->language($from, $now),
                'score' => $this->score($from, $now),
                'feedback' => $feedbackPath === null ? null : $this->feedback($feedbackPath),
            ];
        } finally {
            // What the window cost is part of the window. A read that quietly becomes slow is how a
            // review stops happening, so the figure travels with the report instead of living in a
            // log nobody reads, and the caller's own query logging is left as it was found.
            $readCost = [
                'milliseconds' => round((microtime(true) - $startedAt) * 1000, 1),
                'queries' => count($connection->getQueryLog()) - $queriesBefore,
            ];

            if (!$wasLogging) {
                $connection->disableQueryLog();
            }
        }

        return app()->makeWith(AiPilotReport::class, $readings + [
            'days' => $window,
            'readCost' => $readCost,
        ]);
    }

    /**
     * What the cohort's public score did over the window, read from the module's own hourly samples
     * because the host keeps current points and no history.
     *
     * The figures are per account first: where the accounts moved, how far apart they moved, the
     * biggest single hour and how much was lost. An empty window is reported as empty rather than as
     * flat growth, because "nothing was recorded" and "nothing happened" are different findings.
     */
    private function score(CarbonImmutable $from, CarbonImmutable $now): AiScoreReport
    {
        $enabled = (bool) config('ai.review.enabled', true);
        $samples = AiScoreSample::query()
            ->whereBetween('sampled_at', [$from, $now])
            ->orderBy('player_id')
            ->oldest('sampled_at')
            ->get(['player_id', 'general', 'military_lost']);

        if ($samples->isEmpty()) {
            return app()->makeWith(AiScoreReport::class, ['enabled' => $enabled]);
        }

        $deltas = [];
        $largestJump = 0;
        $militaryLost = 0;

        foreach ($samples->groupBy('player_id') as $accountSamples) {
            $rows = $accountSamples->values();
            $first = $rows->firstOrFail();
            $last = $first;

            foreach ($rows as $index => $sample) {
                if ($index === 0) {
                    continue;
                }

                $largestJump = max($largestJump, $sample->general - $last->general);
                $last = $sample;
            }

            $deltas[] = $last->general - $first->general;
            $militaryLost += $last->military_lost - $first->military_lost;
        }

        sort($deltas);
        $accounts = count($deltas);

        return app()->makeWith(AiScoreReport::class, [
            'enabled' => $enabled,
            'accounts' => $accounts,
            'samples' => $samples->count(),
            'generalDeltaMin' => $deltas[0],
            // Nearest rank, the same honesty as the lateness percentiles: a handful of accounts
            // cannot carry an interpolated median, and inventing precision is worse than a coarse
            // figure that is exactly what was measured.
            'generalDeltaMedian' => $deltas[(int) ceil($accounts / 2) - 1],
            'generalDeltaMax' => $deltas[$accounts - 1],
            'largestHourlyJump' => $largestJump,
            'zeroGrowthAccounts' => count(array_filter($deltas, static fn (int $delta): bool => $delta === 0)),
            'militaryLost' => $militaryLost,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function actions(CarbonImmutable $from, CarbonImmutable $now): array
    {
        return AiActionReceipt::query()
            ->whereBetween('created_at', [$from, $now])
            ->pluck('state')
            ->countBy(static fn (AiReceiptState $state): string => $state->name)
            ->all();
    }

    /**
     * How late the module acted on work it had decided was due. This is the module's own lateness,
     * not a server tick: this host progresses resources lazily and delivers fleet arrivals
     * through queued jobs, so there is no tick to measure against.
     *
     * @param Collection<int, AiWorkItem> $completed
     * @return array<int, float>
     */
    private function lateness($completed): array
    {
        return $completed
            ->map(static fn (AiWorkItem $item): float => max(0.0, $item->due_at->diffInMinutes($item->updated_at)))
            ->values()
            ->all();
    }

    /**
     * @return array<string, int|float>
     */
    private function language(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $reservations = AiUsageReservation::query()
            ->whereBetween('reserved_for', [$from->toDateString(), $now->toDateString()])
            ->get(['actual_input_tokens', 'actual_output_tokens', 'cost']);

        return [
            'attempts' => AiLanguageRequest::query()->whereBetween('created_at', [$from, $now])->count(),
            'tokens' => (int) $reservations->sum(static fn ($reservation): int => (int) $reservation->actual_input_tokens + (int) $reservation->actual_output_tokens),
            'cost' => round((float) $reservations->sum(static fn ($reservation): float => (float) $reservation->cost), 8),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function feedback(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Feedback file not found: ' . $path);
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Feedback file is not valid JSON: ' . $path, 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Feedback file must contain a JSON object: ' . $path);
        }

        return $decoded;
    }
}
