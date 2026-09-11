<?php

namespace Modules\AI\Listeners;

use Modules\AI\Actions\RecordAiBuildingCompletionExperienceAction;
use OGame\Events\Game\BuildingCompleted;

class RecordAiBuildingCompletionExperience
{
    public function handle(BuildingCompleted $event): void
    {
        app(RecordAiBuildingCompletionExperienceAction::class)->handle(
            $event->planetId,
            $event->machineName,
            $event->level,
        );
    }
}
