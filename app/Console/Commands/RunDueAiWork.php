<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Modules\AI\Actions\ResolveAiAdmissionAction;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiWorkItem;

#[Description('Dispatch due AI work items without making decisions.')]
#[Signature('ai:run-due-work {--limit=100}')]
class RunDueAiWork extends Command
{
    public function handle(): int
    {
        $requestedLimit = max(1, (int) $this->option('limit'));
        $admission = app(ResolveAiAdmissionAction::class)->forDispatch($requestedLimit);

        // A refusal is a state an operator chose, not a failure of this command, so the pass
        // reports it and succeeds; the reason is what the admin page and the pilot report read.
        if (!$admission->allowed) {
            $this->warn('AI work is not admitted: ' . $admission->stopReason?->value);

            return self::SUCCESS;
        }

        // This pass is the only thing that ever admits work, so a lease left behind by a worker the
        // queue killed mid-handle (a timeout, an OOM) has to be admitted here too: with its job gone
        // no retry will arrive, and ProcessAiWork's own reclaim would never be reached, stranding the
        // item Leased forever. A live lease cannot match, because a worker cannot outlive its lease.
        $work = AiWorkItem::query()
            ->where(function (Builder $query): void {
                $query->whereIn('state', [AiWorkState::Pending, AiWorkState::Retry])
                    ->orWhere(function (Builder $stranded): void {
                        $stranded->where('state', AiWorkState::Leased)
                            ->where('lease_until', '<', now());
                    });
            })
            ->oldest('due_at')
            ->limit($admission->limit);

        if ((int) config('ai.population.session_interval_seconds', 0) <= 0) {
            $work->where('due_at', '<=', now());
        }

        $work->pluck('id')
            ->each(static fn (int $workItemId) => ProcessAiWork::dispatch($workItemId));

        return self::SUCCESS;
    }
}
