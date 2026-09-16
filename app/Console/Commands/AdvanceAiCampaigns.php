<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\AdvanceAiCampaignStateAction;

#[Description('Advance the cooperative campaign lifecycle.')]
#[Signature('ai:advance-campaigns')]
class AdvanceAiCampaigns extends Command
{
    public function handle(): int
    {
        $advanced = app(AdvanceAiCampaignStateAction::class)->handle();

        $this->line(sprintf('Advanced %d campaigns.', $advanced));

        return self::SUCCESS;
    }
}
