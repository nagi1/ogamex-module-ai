<?php

use Illuminate\Support\Facades\Queue;
use Modules\AI\Actions\RunAiOperationAction;
use Modules\AI\Enums\AiOperation;
use Modules\AI\Jobs\RunAiOperationJob;
use Modules\AI\Models\AiOperationLog;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;

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
