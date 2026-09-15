<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\BuildAiPilotReportAction;
use Modules\AI\Domain\Operability\AiPilotReport;
use Modules\AI\Domain\Review\AiScoreReport;
use RuntimeException;

#[Description('Report a pilot window: action outcomes, failures, lateness, cost and growth.')]
#[Signature('ai:pilot-report
        {--days=1 : How many days back the window reaches}
        {--json : Print the same window as machine-readable JSON for a review to parse}
        {--feedback= : Optional JSON file with the human feedback for this window}')]
class ReportAiPilot extends Command
{
    public function handle(): int
    {
        $feedback = $this->option('feedback');

        try {
            $report = app(BuildAiPilotReportAction::class)->handle(
                max(1, (int) $this->option('days')),
                is_string($feedback) && $feedback !== '' ? $feedback : null,
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        // One read, two renderings: the JSON is the report's own shape, so a review parses fields
        // and diffs two windows instead of reading prose back out of the human output.
        if ($this->option('json')) {
            $this->line(json_encode(
                $report->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        }

        $this->report($report);

        return self::SUCCESS;
    }

    private function report(AiPilotReport $report): void
    {
        $this->newLine();
        $this->line(sprintf('Pilot window: last %d day(s) · %d enabled AI profiles', $report->days, $report->profiles));
        $this->line('  work: ' . $this->counts($report->work));
        $this->line('  actions: ' . $this->counts($report->actions));
        $this->line(sprintf(
            '  lateness: p50 %.1f min · p95 %.1f min over %d completed sessions',
            $report->latencyPercentile(50.0),
            $report->latencyPercentile(95.0),
            count($report->latencyMinutes),
        ));
        $this->line('  language: ' . $this->counts($report->language));
        $this->line('  score: ' . $this->score($report->score));
        $this->line(sprintf(
            '  read cost: %.1f ms · %d queries',
            $report->readCost['milliseconds'],
            $report->readCost['queries'],
        ));

        if ($report->feedback === null) {
            $this->line('  human feedback: not recorded for this window.');

            return;
        }

        foreach ($report->feedback as $question => $answer) {
            $this->line('  feedback ' . $question . ': ' . (is_scalar($answer) ? (string) $answer : json_encode($answer)));
        }
    }

    /**
     * The score line states which of the three states a window is in — not collected, collected and
     * empty, or collected — because an operator reading "no growth" must not be reading a window
     * where the collection was switched off.
     */
    private function score(AiScoreReport $score): string
    {
        if (!$score->enabled) {
            return 'not collected (ai.review.enabled is false)';
        }

        if ($score->samples === 0) {
            return 'no samples in this window';
        }

        return sprintf(
            '%d accounts · %d samples · general delta min %d · median %d · max %d · largest hour +%d · no growth %d · military lost %d',
            $score->accounts,
            $score->samples,
            $score->generalDeltaMin,
            $score->generalDeltaMedian,
            $score->generalDeltaMax,
            $score->largestHourlyJump,
            $score->zeroGrowthAccounts,
            $score->militaryLost,
        );
    }

    /**
     * @param array<string, int> $counts
     */
    private function counts(array $counts): string
    {
        if ($counts === []) {
            return 'none';
        }

        return implode(', ', array_map(
            static fn (string $name, int $value): string => $name . ' ' . $value,
            array_keys($counts),
            array_values($counts),
        ));
    }
}
