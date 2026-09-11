<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\AI\Actions\QueueAiBuildingAction;
use Modules\AI\Actions\RecordAiBuildingCompletionExperienceAction;
use Modules\AI\Contracts\QueueAiBuilding;
use Modules\AI\Domain\Decision\BuildFirstBuilding;
use Modules\AI\Domain\Decision\BuildingScoringPolicy;
use Modules\AI\Domain\Decision\SeededBuildingScoringPolicy;
use Modules\AI\Enums\AiActionReceiptResultKey;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiBuildingExperienceFeature;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceOutcome;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Enums\FirstBuildingTarget;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Listeners\RecordAiBuildingCompletionExperience;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiExperienceCase;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\SystemAiClock;
use Modules\AI\Tests\Support\ThrowingBuildFirstBuilding;
use OGame\Events\Game\BuildingCompleted;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Ban;
use OGame\Models\BuildingQueue;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\PlayerGameStateService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/ThrowingBuildFirstBuilding.php';

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiBuilding::class, QueueAiBuildingAction::class);
    app()->bind(BuildingScoringPolicy::class, SeededBuildingScoringPolicy::class);
    app()->bind(AiClock::class, SystemAiClock::class);
});

test('due building work is idempotent', function (): void {
    $this->planetAddResources(app()->makeWith(Resources::class, [
        'metal' => 1_000_000,
        'crystal' => 1_000_000,
        'deuterium' => 1_000_000,
    ]));
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

test('a completed host building queue retains one correlated upgrade experience', function (): void {
    $this->planetAddResources(app()->makeWith(Resources::class, [
        'metal' => 1_000_000,
        'crystal' => 1_000_000,
        'deuterium' => 1_000_000,
    ]));
    aiWorkProfile($this->currentUserId);
    $work = aiBuildingWork($this->currentUserId, 'completed-outcome');

    app()->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle(app(BuildFirstBuilding::class));

    $receipt = AiActionReceipt::query()->where('player_id', $this->currentUserId)->sole();
    $queue = BuildingQueue::query()->where('planet_id', $this->currentPlanetId)->sole();

    expect($receipt->state)->toBe(AiReceiptState::Accepted)
        ->and(AiExperienceCase::query()->where('player_id', $this->currentUserId)->exists())->toBeFalse();

    Event::listen(BuildingCompleted::class, RecordAiBuildingCompletionExperience::class);
    BuildingQueue::query()->whereKey($queue->id)->update(['time_end' => now()->subSecond()->getTimestamp()]);
    $player = app(PlayerGameStateService::class)->advance($this->currentUserId, $this->currentPlanetId);
    $planet = app(PlanetServiceFactory::class)->makeForPlayer($player, $this->currentPlanetId, false);
    $planet->updateBuildingQueue();

    $observation = AiObservation::query()
        ->where('player_id', $this->currentUserId)
        ->where('source_type', AiObservationSource::BuildingQueue)
        ->where('source_id', $queue->id)
        ->sole();
    $case = AiExperienceCase::query()
        ->where('player_id', $this->currentUserId)
        ->where('outcome_observation_id', $observation->id)
        ->sole();

    expect($observation->kind)->toBe(AiObservationKind::BuildingCompleted)
        ->and($case->family)->toBe(AiExperienceCaseFamily::BuildingUpgrade)
        ->and($case->outcome)->toBe(AiExperienceOutcome::Succeeded)
        ->and($case->features)->toMatchArray([
            AiBuildingExperienceFeature::PlanetId->value => $this->currentPlanetId,
            AiBuildingExperienceFeature::ObjectId->value => $queue->object_id,
            AiBuildingExperienceFeature::TargetLevel->value => $queue->object_level_target,
        ]);

    $planet->updateBuildingQueue();

    expect(AiExperienceCase::query()->where('player_id', $this->currentUserId)->count())->toBe(1);

    AiActionReceipt::create([
        'player_id' => $this->currentUserId,
        'idempotency_key' => 'ambiguous-completion:' . $this->currentUserId,
        'action_type' => AiActionType::QueueBuilding,
        'state' => AiReceiptState::Accepted,
        'result' => [
            AiActionReceiptResultKey::PlanetId->value => $this->currentPlanetId,
            AiActionReceiptResultKey::QueueId->value => $queue->id,
        ],
    ]);

    expect(app(RecordAiBuildingCompletionExperienceAction::class)->handle(
        $this->currentPlanetId,
        'metal_mine',
        $queue->object_level_target,
    ))->toBeNull()
        ->and(AiExperienceCase::query()->where('player_id', $this->currentUserId)->count())->toBe(1);
});

test('building completion correlation rejects absent and incomplete receipt evidence', function (): void {
    $record = app(RecordAiBuildingCompletionExperienceAction::class);
    $machineName = 'metal_mine';

    expect($record->handle(PHP_INT_MAX, $machineName, 1))->toBeNull()
        ->and($record->handle($this->currentPlanetId, $machineName, 1))->toBeNull();

    AiActionReceipt::create([
        'player_id' => $this->currentUserId,
        'idempotency_key' => 'missing-queue-id:' . $this->currentUserId,
        'action_type' => AiActionType::QueueBuilding,
        'state' => AiReceiptState::Accepted,
        'result' => [AiActionReceiptResultKey::PlanetId->value => $this->currentPlanetId],
    ]);
    AiActionReceipt::create([
        'player_id' => $this->currentUserId,
        'idempotency_key' => 'missing-queue:' . $this->currentUserId,
        'action_type' => AiActionType::QueueBuilding,
        'state' => AiReceiptState::Accepted,
        'result' => [
            AiActionReceiptResultKey::PlanetId->value => $this->currentPlanetId,
            AiActionReceiptResultKey::QueueId->value => PHP_INT_MAX,
        ],
    ]);

    expect($record->handle($this->currentPlanetId, $machineName, 1))->toBeNull()
        ->and(AiExperienceCase::query()->where('player_id', $this->currentUserId)->exists())->toBeFalse();
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
        'state' => AiReceiptState::Accepted,
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
    // This container-bound failure seam isolates retry limits; normal tests use
    // the production decision.
    $this->app->bind(BuildFirstBuilding::class, ThrowingBuildFirstBuilding::class);
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
