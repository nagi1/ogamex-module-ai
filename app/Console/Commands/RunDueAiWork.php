<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Command;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiWorkItem;

class RunDueAiWork extends Command
{
    protected $signature = 'ai:run-due-work {--limit=100}';

    protected $description = 'Dispatch due AI work items without making decisions.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        AiWorkItem::query()
            ->whereIn('state', [AiWorkState::Pending, AiWorkState::Retry])
            ->where('due_at', '<=', now())
            ->orderBy('due_at')
            ->limit($limit)
            ->pluck('id')
            ->each(static fn (int $workItemId) => ProcessAiWork::dispatch($workItemId));

        return self::SUCCESS;
    }
}
