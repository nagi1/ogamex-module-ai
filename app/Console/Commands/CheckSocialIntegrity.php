<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\CheckSocialIntegrityAction;

#[Description('Read only: check that every war, pact, notice and peace AI leaders announced stands on the treaty record, and that no hostile fleet flies at a pact partner.')]
#[Signature('ai:social-integrity
        {--json : Print the full report as JSON}')]
class CheckSocialIntegrity extends Command
{
    public function handle(CheckSocialIntegrityAction $action): int
    {
        $report = $action->handle();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->line('Record: ' . $report['record']);
        $this->line('Acts: ' . json_encode($report['acts']));
        $this->line('Speech: ' . json_encode($report['speech']));
        $this->line(sprintf(
            'Unmatched speech: %d, silent acts: %d, hostile fleets at a pact partner: %d (espionage %d), hostile fleets at a war enemy: %d',
            count($report['unmatched_speech']),
            count($report['silent_acts']),
            count($report['fleets']['hostile_at_partner']),
            $report['fleets']['espionage_at_partner'],
            $report['fleets']['hostile_at_war_enemy']
        ));
        $this->line($report['ok'] ? 'SOCIAL INTEGRITY: PASS' : 'SOCIAL INTEGRITY: FAIL');

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
