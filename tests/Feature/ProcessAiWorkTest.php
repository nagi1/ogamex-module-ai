<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\QueueAiBuildingAction;
use Modules\AI\Contracts\QueueAiBuilding;
use Modules\AI\Domain\Decision\BuildFirstBuilding;
use Modules\AI\Domain\Decision\BuildingScoringPolicy;
use Modules\AI\Domain\Decision\SeededBuildingScoringPolicy;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Enums\FirstBuildingTarget;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Models\Ban;
use OGame\Models\BuildingQueue;
use OGame\Models\Resources;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiBuilding::class, QueueAiBuildingAction::class);
    app()->bind(BuildingScoringPolicy::class, SeededBuildingScoringPolicy::class);
});

test('due building work is idempotent', function (): void {
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    aiWorkProfile($this->currentUserId);
    $work = aiBuildingWork($this->currentUserId, 'idempotent');
    $job = $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id]);
    $decision = $this->app->make(BuildFirstBuilding::class);

    $job->handle($decision);
    $work->update(['state' => AiWorkState::Pending]);
    $job->handle($decision);

    expect(BuildingQueue::query()->where('planet_id', $this->currentPlanetId)->count())->toBe(1)
        ->and(AiActionReceipt::query()->where('player_id', $this->currentUserId)->count())->toBe(1);
});

test('an invalid owned planet is recorded as a rejected real action', function (): void {
    aiWorkProfile($this->currentUserId);
    $work = aiBuildingWork($this->currentUserId, 'invalid-planet', ['planet_id' => PHP_INT_MAX]);

    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle($this->app->make(BuildFirstBuilding::class));

    expect(AiActionReceipt::query()
        ->where('idempotency_key', $work->idempotency_key)
        ->where('state', AiReceiptState::Rejected)
        ->exists())->toBeTrue();
});

test('the real queue action rejects banned and vacation players', function (): void {
    Ban::create(['user_id' => $this->currentUserId, 'reason' => 'test ban', 'banned_until' => now()->addHour(), 'canceled' => false]);

    $banned = app(QueueAiBuilding::class)->handle($this->currentUserId, $this->currentPlanetId, FirstBuildingTarget::MetalMine->value);

    expect($banned->successful)->toBeFalse()
        ->and($banned->reason)->toBe(AiQueueActionReason::PlayerBanned->value);

    Ban::query()->where('user_id', $this->currentUserId)->update(['canceled' => true]);
    User::query()->whereKey($this->currentUserId)->update(['vacation_mode' => true]);

    $vacation = app(QueueAiBuilding::class)->handle($this->currentUserId, $this->currentPlanetId, FirstBuildingTarget::MetalMine->value);

    expect($vacation->successful)->toBeFalse()
        ->and($vacation->reason)->toBe(AiQueueActionReason::VacationMode->value);
});

test('the real queue action rejects a non-building and catches an invalid game object', function (): void {
    $nonBuilding = app(QueueAiBuilding::class)->handle($this->currentUserId, $this->currentPlanetId, 202);
    $invalidObject = app(QueueAiBuilding::class)->handle($this->currentUserId, $this->currentPlanetId, PHP_INT_MAX);

    expect($nonBuilding->successful)->toBeFalse()
        ->and($nonBuilding->reason)->toBe(AiQueueActionReason::NotABuilding->value)
        ->and($invalidObject->successful)->toBeFalse()
        ->and($invalidObject->reason)->toBeString()->not->toBeEmpty();
});

test('the real queue action preserves the shipyard safety rule while units are building', function (): void {
    DB::table('unit_queues')->insert([
        'planet_id' => $this->currentPlanetId,
        'object_id' => 204,
        'object_amount' => 1,
        'time_duration' => 3_600,
        'time_start' => now()->getTimestamp(),
        'time_end' => now()->addHour()->getTimestamp(),
        'processed' => false,
    ]);

    $result = app(QueueAiBuilding::class)->handle($this->currentUserId, $this->currentPlanetId, 21);

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe(AiQueueActionReason::ShipyardBusy->value);
});

test('lock contention retries work without an action', function (): void {
    $work = aiBuildingWork($this->currentUserId, 'locked');
    $lock = Cache::lock('ai:player:' . $this->currentUserId, 300);
    $lock->get();

    try {
        $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle($this->app->make(BuildFirstBuilding::class));
    } finally {
        $lock->release();
    }

    expect($work->fresh()?->state)->toBe(AiWorkState::Retry)
        ->and(AiActionReceipt::query()->where('idempotency_key', $work->idempotency_key)->count())->toBe(0);
});

test('a disabled profile completes work without a game action', function (): void {
    aiWorkProfile($this->currentUserId, false);
    $work = aiBuildingWork($this->currentUserId, 'disabled');

    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle($this->app->make(BuildFirstBuilding::class));

    expect($work->fresh()?->state)->toBe(AiWorkState::Completed);
    expect(AiActionReceipt::query()->where('idempotency_key', $work->idempotency_key)->exists())->toBeFalse();
});

test('future work stays pending and terminal receipts prevent a second action', function (): void {
    $future = AiWorkItem::create([
        'player_id' => $this->currentUserId,
        'kind' => AiWorkKind::BuildFirstBuilding,
        'due_at' => now()->addMinute(),
        'idempotency_key' => 'future-' . $this->currentUserId,
        'state' => AiWorkState::Pending,
    ]);
    aiWorkProfile($this->currentUserId);
    $terminal = aiBuildingWork($this->currentUserId, 'terminal');
    AiActionReceipt::create([
        'player_id' => $this->currentUserId,
        'idempotency_key' => $terminal->idempotency_key,
        'action_type' => AiActionType::QueueBuilding,
        'state' => AiReceiptState::Completed,
    ]);

    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $future->id])->handle($this->app->make(BuildFirstBuilding::class));
    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $terminal->id])->handle($this->app->make(BuildFirstBuilding::class));

    expect($future->fresh()?->state)->toBe(AiWorkState::Pending)
        ->and($terminal->fresh()?->state)->toBe(AiWorkState::Completed)
        ->and(BuildingQueue::query()->where('planet_id', $this->currentPlanetId)->count())->toBe(0);
});

test('a profile without a planet receives a safe rejection', function (): void {
    $playerId = $this->currentUserId + 100_000;
    aiWorkProfile($playerId);
    $work = aiBuildingWork($playerId, 'no-planet');

    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle($this->app->make(BuildFirstBuilding::class));

    expect(AiActionReceipt::query()
        ->where('idempotency_key', $work->idempotency_key)
        ->where('state', AiReceiptState::Rejected)
        ->exists())->toBeTrue();
    expect($work->fresh()?->state)->toBe(AiWorkState::Completed);
});

test('a thrown decision retries and then fails at the configured attempt limit', function (): void {
    aiWorkProfile($this->currentUserId);
    $retry = aiBuildingWork($this->currentUserId, 'throw-retry');
    $failed = aiBuildingWork($this->currentUserId, 'throw-failed', attempts: 2);
    // This is the narrowly scoped, container-resolved failure seam that proves
    // queue retry limits. All normal action tests use the production decision.
    $this->app->bind(BuildFirstBuilding::class, static function (): BuildFirstBuilding {
        return new class () extends BuildFirstBuilding {
            public function __construct()
            {
            }

            public function choose(AiProfile $profile): array
            {
                throw new RuntimeException('test decision failure');
            }
        };
    });
    $decision = $this->app->make(BuildFirstBuilding::class);

    expect(fn () => $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $retry->id])->handle($decision))->toThrow(RuntimeException::class, 'test decision failure');
    expect(fn () => $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $failed->id])->handle($decision))->toThrow(RuntimeException::class, 'test decision failure');

    expect($retry->fresh()?->state)->toBe(AiWorkState::Retry)
        ->and($failed->fresh()?->state)->toBe(AiWorkState::Failed);
});

function aiWorkProfile(int $playerId, bool $enabled = true): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'enabled' => $enabled,
    ]);
}

/** @param array<string, mixed> $payload */
function aiBuildingWork(int $playerId, string $suffix, array $payload = [], int $attempts = 0): AiWorkItem
{
    return AiWorkItem::create([
        'player_id' => $playerId,
        'kind' => AiWorkKind::BuildFirstBuilding,
        'due_at' => now(),
        'payload' => $payload,
        'idempotency_key' => 'work:' . $suffix . ':' . $playerId,
        'state' => AiWorkState::Pending,
        'attempts' => $attempts,
    ]);
}
