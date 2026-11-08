<?php

namespace Modules\AI\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\AI\Actions\BuildAiModelLabAction;
use Modules\AI\Enums\AiQueueName;

/**
 * Asks the trained models about every AI account off the request path (about a second per account), so the model lab
 * opens at once. Unique: however many page views ask while a pass runs, one pass runs.
 */
class RefreshAiModelLabJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue(AiQueueName::Ai->value);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['ai', 'ai:model-lab'];
    }

    public function handle(): void
    {
        app(BuildAiModelLabAction::class)->refresh();
    }
}
