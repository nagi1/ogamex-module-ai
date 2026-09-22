<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiCampaignControl;
use Modules\AI\Jobs\RunAiCampaignControlJob;
use Modules\AI\Models\AiOperationLog;

/**
 * Records a queued run of one campaign control and hands it to the AI lane. The job runs the
 * action or command, never this action, so a click never mutates a campaign inside the request.
 */
class RunAiCampaignControlAction
{
    /**
     * @param array<string, mixed> $params
     */
    public function handle(AiCampaignControl $control, array $params, int|null $actorPlayerId): AiOperationLog
    {
        $log = AiOperationLog::query()->create([
            'operation' => $control->value,
            'actor_player_id' => $actorPlayerId,
            'status' => 'queued',
        ]);

        RunAiCampaignControlJob::dispatch($control->value, $log->id, $params);

        return $log;
    }
}
