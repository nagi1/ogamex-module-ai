<?php

namespace Modules\AI\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\AI\Actions\AppraiseObservedBattleReportAction;
use Modules\AI\Enums\AiQueueName;

/**
 * Appraises one observed battle through the affect engine (FAtiMA, with the native fallback) off the login
 * path (architecture step 6). The login reads the stored mood and never waits on the sidecar's HTTP; the
 * appraisal, the episode, the grudge and the memory fact land a moment later on the AI lane.
 */
class AppraiseAiBattleReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $observationId)
    {
        $this->onQueue(AiQueueName::Ai->value);
    }

    public function handle(AppraiseObservedBattleReportAction $appraise): void
    {
        $appraise->handle($this->observationId);
    }
}
