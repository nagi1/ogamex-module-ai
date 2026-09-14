<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\SeedAiTestUniverseAction;
use RuntimeException;

#[Description('Create synthetic AI accounts for a test or pilot universe. Refuses production.')]
#[Signature('ai:seed-test-universe
        {--players=3 : How many accounts to create}
        {--confirm : Confirm these accounts are meant to exist}')]
class SeedAiTestUniverse extends Command
{
    public function handle(): int
    {
        // There is no flag that makes this safe, because there is no safe amount of synthetic
        // population on a universe real players share with each other under its own rules.
        if (app()->environment('production')) {
            $this->error('Refusing to seed synthetic accounts in production.');

            return self::FAILURE;
        }

        if (!$this->option('confirm')) {
            $this->error('This creates real accounts and real profiles. Re-run with --confirm.');

            return self::FAILURE;
        }

        try {
            $seeded = app(SeedAiTestUniverseAction::class)->handle(max(1, (int) $this->option('players')));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($seeded as $account) {
            $this->line(sprintf(
                '%s player %d as %s',
                $account['created'] ? 'created' : 'already seeded',
                $account['player_id'],
                $account['archetype'],
            ));
        }

        $this->line('Their first session is due now; the scheduler will pick it up on its next pass.');

        return self::SUCCESS;
    }
}
