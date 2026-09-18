<?php

namespace Modules\AI\Listeners;

use Modules\AI\Actions\RecordAiColonyCampaignSignalAction;
use OGame\Events\Game\PlanetCreated;

class RecordAiColonyCampaignSignal
{
    public function handle(PlanetCreated $event): void
    {
        app(RecordAiColonyCampaignSignalAction::class)->handle($event);
    }
}
