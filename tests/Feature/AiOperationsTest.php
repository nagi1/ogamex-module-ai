<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\AI\Actions\RunAiOperationAction;
use Modules\AI\Enums\AiOperation;
use Modules\AI\Jobs\RunAiOperationJob;
use Modules\AI\Models\AiOperationLog;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;

require_once __DIR__ . '/../Support/AiQueueModuleTestCase.php';

uses(AiQueueModuleTestCase::class);

beforeEach(function (): void {
    $this->artisan('ogamex:admin:assign-role', ['username' => $this->currentUsername]);
});

test('a queued operation records who ran it and what', function (): void {
    Queue::fake();

    $this->post(route('ai.operations'), ['operation' => 'prune'])
        ->assertRedirect(route('ai.index', ['tab' => 'operations']));

    $log = AiOperationLog::query()->sole();

    expect($log->operation)->toBe('prune')
        ->and($log->status)->toBe('queued')
        ->and($log->actor_player_id)->toBe($this->currentUserId);

    Queue::assertPushed(RunAiOperationJob::class, static fn (RunAiOperationJob $job): bool => $job->logId === $log->id);
});

test('an operation outside the allow-list is refused', function (): void {
    Queue::fake();

    $this->post(route('ai.operations'), ['operation' => 'wipe-everything'])
        ->assertSessionHasErrors('operation');

    expect(AiOperationLog::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('the retry operation reports when there is nothing to retry', function (): void {
    Queue::fake();

    $log = app(RunAiOperationAction::class)->handle(AiOperation::RetryFailedJobs, $this->currentUserId);

    (new RunAiOperationJob($log->operation, $log->id))->handle();

    $log->refresh();

    expect($log->status)->toBe('completed')
        ->and($log->result)->toBe('No failed jobs to retry.');
});

test('the operation allow-list is the seven audited operations', function (): void {
    expect(array_column(AiOperation::cases(), 'value'))
        ->toBe(['run-due-work', 'prune', 'reconcile-language', 'sample-scores', 'retry-failed-jobs', 'clear-caches', 'restart-worker']);
});

test('the apply bridge offers clear caches and restart worker as queued operations', function (): void {
    Queue::fake();

    $content = $this->get('/admin/ai?tab=operations')->getContent();

    expect($content)->toContain('Clear caches')
        ->toContain('Restart the AI worker');
});

test('the operations tab renders the buttons and the recent runs', function (): void {
    Queue::fake();

    app(RunAiOperationAction::class)->handle(AiOperation::Prune, $this->currentUserId);

    $content = $this->get('/admin/ai?tab=operations')->getContent();

    expect($content)->toContain('Run an operation')
        ->toContain('Run due work now')
        ->toContain('Sweep old records now')
        ->toContain('Recent runs')
        ->toContain('queued');
});

test('the operation job records a failure', function (): void {
    Queue::fake();

    $log = app(RunAiOperationAction::class)->handle(AiOperation::Prune, $this->currentUserId);

    (new RunAiOperationJob($log->operation, $log->id))->failed(new RuntimeException('boom'));

    $log->refresh();

    expect($log->status)->toBe('failed')
        ->and($log->result)->toBe('boom');
});

test('the operation job runs a command and records the result', function (): void {
    Queue::fake();

    $log = app(RunAiOperationAction::class)->handle(AiOperation::Prune, $this->currentUserId);

    (new RunAiOperationJob($log->operation, $log->id))->handle();

    $log->refresh();

    expect($log->status)->toBe('completed')
        ->and($log->result)->toContain('pruned');
});

test('the operation job carries the lane tag', function (): void {
    expect((new RunAiOperationJob('prune', 1))->tags())->toContain('ai:operation');
});

test('the operation job runs every command operation', function (): void {
    Queue::fake();

    foreach ([AiOperation::RunDueWork, AiOperation::ReconcileLanguage, AiOperation::SampleScores, AiOperation::ClearCaches, AiOperation::RestartWorker] as $operation) {
        $log = app(RunAiOperationAction::class)->handle($operation, $this->currentUserId);

        (new RunAiOperationJob($log->operation, $log->id))->handle();

        expect($log->refresh()->status)->toBe('completed');
    }
});

test('the retry operation retries failed jobs on the module lanes', function (): void {
    Queue::fake();

    $log = app(RunAiOperationAction::class)->handle(AiOperation::RetryFailedJobs, $this->currentUserId);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'sync',
        'queue' => 'ai',
        'payload' => json_encode([
            'uuid' => (string) Str::uuid(),
            'displayName' => RunAiOperationJob::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => null,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => null,
            'retryUntil' => null,
            'data' => [
                'commandName' => RunAiOperationJob::class,
                'command' => serialize(new RunAiOperationJob('prune', $log->id)),
            ],
        ]),
        'exception' => 'boom',
        'failed_at' => now(),
    ]);

    (new RunAiOperationJob($log->operation, $log->id))->handle();

    expect($log->refresh()->status)->toBe('completed')
        ->and($log->result)->toContain('Retried');
});
