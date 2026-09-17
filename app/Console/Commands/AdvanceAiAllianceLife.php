<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\AdvanceAiAllianceLifeAction;

#[Description('Advance cooperative alliance life: apply accounts to a suitable alliance.')]
#[Signature('ai:advance-alliance-life')]
class AdvanceAiAllianceLife extends Command
{
    public function handle(): int
    {
        $applied = app(AdvanceAiAllianceLifeAction::class)->handle();

        $this->line(sprintf('Applied %d accounts to an alliance.', $applied));

        return self::SUCCESS;
    }
}
