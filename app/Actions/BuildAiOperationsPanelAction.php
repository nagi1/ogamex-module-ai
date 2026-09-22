<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiOperation;
use Modules\AI\Models\AiOperationLog;

/**
 * The Operations tab: the fixed set of operations and the latest runs. One action, one answer —
 * the view renders this and queries nothing.
 */
class BuildAiOperationsPanelAction
{
    private const RECENT_RUNS = 10;

    /**
     * The four-line definition for every operation, keyed by the enum value.
     *
     * @var array<string, array{what: string, why: string, effect: string, restart: string}>
     */
    private const DEFINITIONS = [
        'run-due-work' => ['what' => 'Leases and enqueues the work that is due right now.', 'why' => 'Kick a pass now after you fixed something or turned the switch on.', 'effect' => 'Safe to run any time; work already leased is skipped.', 'restart' => 'No.'],
        'prune' => ['what' => 'Deletes records older than their retention window.', 'why' => 'Free disk now instead of waiting for the nightly sweep.', 'effect' => 'Only records past their window are removed; no accounts or game data are touched.', 'restart' => 'No.'],
        'reconcile-language' => ['what' => 'Closes provider calls that timed out and settles their cost.', 'why' => 'Close out a stuck provider charge now.', 'effect' => 'A call with no answer is settled against the budget.', 'restart' => 'No.'],
        'sample-scores' => ['what' => 'Records each account\'s current score.', 'why' => 'Refresh the growth curve after a big change.', 'effect' => 'Adds one sample per account; the next board read shows the new curve.', 'restart' => 'No.'],
        'retry-failed-jobs' => ['what' => 'Pushes the module lanes\' failed jobs back onto the queue.', 'why' => 'A blip parked a job — try it again.', 'effect' => 'Only jobs on the module\'s own lanes are retried.', 'restart' => 'No.'],
        'clear-caches' => ['what' => 'Clears the cached config and routes.', 'why' => 'Make a pasted setting live without a full redeploy.', 'effect' => 'The next few page loads are slower while the cache rebuilds; no accounts or game data are touched.', 'restart' => 'No.'],
        'restart-worker' => ['what' => 'Loads the new code into the worker.', 'why' => 'The worker is running old code — load the new code.', 'effect' => 'In-flight sessions finish first; new work continues on the fresh process.', 'restart' => 'This is the restart.'],
    ];

    /**
     * @return array{operations: list<AiOperation>, definitions: array<string, array{what: string, why: string, effect: string, restart: string}>, runs: list<array<string, mixed>>}
     */
    public function handle(): array
    {
        return [
            'operations' => AiOperation::cases(),
            'definitions' => self::DEFINITIONS,
            'runs' => $this->runs(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runs(): array
    {
        return AiOperationLog::query()
            ->latest('id')
            ->limit(self::RECENT_RUNS)
            ->get(['operation', 'status', 'result', 'created_at'])
            ->map(fn (AiOperationLog $log): array => [
                'operation' => $log->operation,
                'status' => $log->status,
                'result' => $log->result,
                'started_at' => $log->created_at?->toDateTimeString(),
            ])
            ->all();
    }
}
