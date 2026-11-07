<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\BuildHumanExperienceReportAction;

#[Description('Report how human players experience the AI accounts: interactions, pressure, resilience, retention and guardrails.')]
#[Signature('ai:human-experience
        {--days=7 : How many days back the window reaches}
        {--loss=20 : Percent of an account\'s worth lost to AI accounts that counts as a heavy loss}
        {--json : Print the report as machine-readable JSON}')]
class ReportHumanExperience extends Command
{
    public function handle(BuildHumanExperienceReportAction $action): int
    {
        $report = $action->handle(max(1, (int) $this->option('days')), max(0.0, (float) $this->option('loss')) / 100.0);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line(sprintf('Human experience: last %d day(s) · %d human accounts (AI accounts excluded)', $report['days'], $report['humans']));
        $rows = [];
        foreach (['interactions', 'opportunities', 'pressure', 'resilience', 'guardrails'] as $section) {
            foreach ($report[$section] as $name => $value) {
                $rows[] = [$section, $name, $this->value($value)];
            }
        }

        foreach ($report['retention'] as $cohort => $marks) {
            foreach ($marks as $mark => $value) {
                $rows[] = ['retention', $cohort . ' ' . $mark, sprintf('%d/%d (%s)', $value['retained'], $value['eligible'], $this->value($value['rate']))];
            }
        }

        $this->table(['section', 'measure', 'value'], $rows);

        return self::SUCCESS;
    }

    private function value(mixed $value): string
    {
        return match (true) {
            $value === null => 'not measured',
            is_array($value) => implode(' · ', array_map(static fn (string $key, mixed $item): string => $key . ' ' . $item, array_keys($value), $value)),
            default => (string) $value,
        };
    }
}
