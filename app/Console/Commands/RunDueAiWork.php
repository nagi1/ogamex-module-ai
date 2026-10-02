<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Modules\AI\Actions\ResolveAiAdmissionAction;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiRuntimeSettings;

#[Description('Dispatch due AI work items without making decisions.')]
#[Signature('ai:run-due-work {--limit=}')]
class RunDueAiWork extends Command
{
    public function handle(): int
    {
        // No flag means the configured batch: the scheduler fires once a minute, so a fixed 100 starved a cohort larger than that.
        $requestedLimit = max(1, (int) ($this->option('limit') ?? app(AiRuntimeSettings::class)->dispatchBatchSize()));
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
            // Orders already due go before sessions: an accelerated session is claimable whenever it is
            // dispatched, so by due time alone the batch fills with sessions and the building, transfer
            // and save orders they scheduled wait behind them.
            ->orderByRaw('kind = ? asc', [AiWorkKind::RunSession->value])
            ->oldest('due_at')
            ->limit($admission->limit);

        if ((int) config('ai.population.session_interval_seconds', 0) <= 0) {
            $work->where('due_at', '<=', now());
        }
        if ((int) config('ai.population.session_interval_seconds', 0) > 0) {
            $work->where(fn (Builder $due) => $due->where('kind', AiWorkKind::RunSession->value)->orWhere('due_at', '<=', now()));
        }

        $work->pluck('id')
            ->each(static fn (int $workItemId) => ProcessAiWork::dispatch($workItemId));

        return self::SUCCESS;
    }
}
