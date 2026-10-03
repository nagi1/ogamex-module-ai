<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\AutoRunAiCampaignAction;

#[Description('Open a cooperative campaign with declared strongholds when none is running.')]
#[Signature('ai:run-campaign')]
class RunAiCampaign extends Command
{
    public function handle(): int
    {
        $campaign = app(AutoRunAiCampaignAction::class)->handle();

        $this->line($campaign === null ? 'A campaign is already running.' : sprintf('Opened campaign %d.', $campaign->id));

        return self::SUCCESS;
    }
}
