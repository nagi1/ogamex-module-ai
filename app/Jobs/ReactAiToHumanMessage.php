<?php

namespace Modules\AI\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\AI\Actions\ReactAiToHumanMessageAction;
use Modules\AI\Enums\AiQueueName;

/** Reads a human's message off the request that sent it: the classifier call belongs on the language lane. */
class ReactAiToHumanMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $chatMessageId)
    {
        $this->onQueue(AiQueueName::AiLanguage->value);
    }

    public function handle(ReactAiToHumanMessageAction $react): void
    {
        $react->handle($this->chatMessageId);
    }
}
