<?php

namespace Modules\AI\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Modules\AI\Enums\AiOperation;
use Modules\AI\Enums\AiQueueName;
use Modules\AI\Models\AiOperationLog;
use Throwable;

/**
 * Runs one console operation off the request path and writes the outcome to the audit row the
 * action created. A failure marks the row failed with the error, so a queued run is never left
 * looking like it might still finish.
 */
class RunAiOperationJob implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt: an operation is a one-shot command, and a re-run after a failure should be a
     * new, separately-audited click rather than an automatic second try.
     */
    public int $tries = 1;

    public function __construct(public readonly string $operation, public readonly int $logId)
    {
        $this->onQueue(AiQueueName::Ai->value);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['ai', 'ai:operation', 'ai:operation:'.$this->operation];
    }

    public function handle(): void
    {
        $this->finish('completed', $this->run(AiOperation::from($this->operation)));
    }

    public function failed(Throwable $exception): void
    {
        $this->finish('failed', $exception->getMessage());
    }

    private function run(AiOperation $operation): string
    {
        return match ($operation) {
            AiOperation::RunDueWork => $this->command('ai:run-due-work'),
            AiOperation::Prune => $this->command('ai:prune'),
            AiOperation::ReconcileLanguage => $this->command('ai:reconcile-language-requests'),
            AiOperation::SampleScores => $this->command('ai:record-score-samples'),
            AiOperation::RetryFailedJobs => $this->retryFailedJobs(),
            AiOperation::ClearCaches => $this->command('optimize:clear'),
            AiOperation::RestartWorker => $this->command('horizon:terminate'),
        };
    }

    private function command(string $name): string
    {
        Artisan::call($name);

        return trim((string) Artisan::output());
    }

    private function retryFailedJobs(): string
    {
        $failer = app('queue.failer');
        $total = 0;

        foreach (AiQueueName::values() as $queue) {
            $pending = method_exists($failer, 'ids') ? $failer->ids($queue) : [];
            if ($pending === []) {
                continue;
            }

            Artisan::call('queue:retry', ['--queue' => $queue]);
            $total += count($pending);
        }

        return $total === 0 ? 'No failed jobs to retry.' : "Retried {$total} failed job(s).";
    }

    private function finish(string $status, string $result): void
    {
        AiOperationLog::query()->whereKey($this->logId)->update([
            'status' => $status,
            'result' => $result,
            'finished_at' => now(),
        ]);
    }
}
