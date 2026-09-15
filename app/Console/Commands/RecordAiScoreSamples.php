<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\RecordAiScoreSamplesAction;

#[Description('Record one hour of the cohort public score so the growth curve can be read back.')]
#[Signature('ai:record-score-samples')]
class RecordAiScoreSamples extends Command
{
    public function handle(): int
    {
        // The switch is read here, once per pass, so the scheduled run and a manual run behave
        // identically and nothing else in the module has to know the collection can be off.
        if (!config('ai.review.enabled')) {
            $this->warn('Score sampling is disabled (ai.review.enabled is false); nothing was recorded.');

            return self::SUCCESS;
        }

        $run = app(RecordAiScoreSamplesAction::class)->handle();

        $this->line(sprintf(
            'Recorded %d score samples for %s (%d enabled accounts had no score row yet).',
            $run->sampled,
            $run->sampledAt?->toDateTimeString() ?? 'now',
            $run->skipped,
        ));

        return self::SUCCESS;
    }
}
