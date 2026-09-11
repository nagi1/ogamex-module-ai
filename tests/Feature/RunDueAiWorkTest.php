<?php

use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Modules\AI\Console\Commands\RunDueAiWork;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiWorkItem;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

test('dispatcher limits to due pending or retry work', function () {
    $oldest = aiDueWork($this->currentUserId, AiWorkState::Pending, now()->subMinute(), 'oldest');
    aiDueWork($this->currentUserId, AiWorkState::Retry, now(), 'retry');
    aiDueWork($this->currentUserId, AiWorkState::Pending, now()->addMinute(), 'future');
    aiDueWork($this->currentUserId, AiWorkState::Completed, now()->subMinute(), 'completed');
    Bus::fake();
    $command = app(RunDueAiWork::class);
    $command->setLaravel($this->app);

    $exitCode = $command->run(
        app()->makeWith(ArrayInput::class, ['parameters' => ['--limit' => 0]]),
        app(NullOutput::class),
    );

    expect($exitCode)->toBe(0);
    Bus::assertDispatched(ProcessAiWork::class, static fn (ProcessAiWork $job): bool => $job->workItemId === $oldest->id);
    Bus::assertDispatchedTimes(ProcessAiWork::class, 1);
});

function aiDueWork(int $playerId, AiWorkState $state, Carbon $dueAt, string $suffix): AiWorkItem
{
    return AiWorkItem::create([
        'player_id' => $playerId,
        'kind' => AiWorkKind::BuildFirstBuilding,
        'due_at' => $dueAt,
        'idempotency_key' => 'command-' . $suffix . '-' . $playerId,
        'state' => $state,
    ]);
}
