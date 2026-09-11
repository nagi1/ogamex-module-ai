<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\ReconcileAiLanguageRequestsAction;

#[Description('Settle AI language attempts whose provider completion can no longer be observed.')]
#[Signature('ai:reconcile-language-requests')]
class ReconcileLanguageRequests extends Command
{
    public function handle(): int
    {
        $settled = app(ReconcileAiLanguageRequestsAction::class)->handle();

        $this->info($settled . ' AI language reservation(s) reconciled.');

        return self::SUCCESS;
    }
}
