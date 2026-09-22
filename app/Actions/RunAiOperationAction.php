<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiOperation;
use Modules\AI\Jobs\RunAiOperationJob;
use Modules\AI\Models\AiOperationLog;

/**
 * Records a queued run of one console operation and hands it to the AI lane. The job runs the
 * command, never this action, so a click never runs artisan inside the request.
 */
class RunAiOperationAction
{
    public function handle(AiOperation $operation, int|null $actorPlayerId): AiOperationLog
    {
        $log = AiOperationLog::query()->create([
            'operation' => $operation->value,
            'actor_player_id' => $actorPlayerId,
            'status' => 'queued',
        ]);

        RunAiOperationJob::dispatch($operation->value, $log->id);

        return $log;
    }
}
