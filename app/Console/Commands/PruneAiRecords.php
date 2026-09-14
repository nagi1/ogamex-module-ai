<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\PruneAiRecordsAction;

#[Description('Delete AI bookkeeping that has outlived its declared retention.')]
#[Signature('ai:prune')]
class PruneAiRecords extends Command
{
    public function handle(): int
    {
        $this->info(app(PruneAiRecordsAction::class)->handle() . ' AI record(s) pruned.');

        return self::SUCCESS;
    }
}
