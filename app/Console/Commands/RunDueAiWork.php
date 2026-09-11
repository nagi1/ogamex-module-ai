<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiWorkItem;

#[Description('Dispatch due AI work items without making decisions.')]
#[Signature('ai:run-due-work {--limit=100}')]
class RunDueAiWork extends Command
{
    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        AiWorkItem::query()
            ->whereIn('state', [AiWorkState::Pending, AiWorkState::Retry])
            ->where('due_at', '<=', now())
            ->oldest('due_at')
            ->limit($limit)
            ->pluck('id')
            ->each(static fn (int $workItemId) => ProcessAiWork::dispatch($workItemId));

        return self::SUCCESS;
    }
}
