<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\BuildAiPilotReportAction;
use Modules\AI\Domain\Operability\AiPilotReport;
use RuntimeException;

#[Description('Report a pilot window: action outcomes, failures, lateness and provider cost.')]
#[Signature('ai:pilot-report
        {--days=1 : How many days back the window reaches}
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

        if ($report->feedback === null) {
            $this->line('  human feedback: not recorded for this window.');

            return;
        }

        foreach ($report->feedback as $question => $answer) {
            $this->line('  feedback ' . $question . ': ' . (is_scalar($answer) ? (string) $answer : json_encode($answer)));
        }
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
