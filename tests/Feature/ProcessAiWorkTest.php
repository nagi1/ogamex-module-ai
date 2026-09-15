<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\AI\Actions\QueueAiBuildingAction;
use Modules\AI\Actions\RecordAiBuildingCompletionExperienceAction;
use Modules\AI\Contracts\QueueAiBuilding;
use Modules\AI\Contracts\QueueAiResearch;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
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
use Modules\AI\Contracts\RunAiSession;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Listeners\RecordAiBuildingCompletionExperience;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiExperienceCase;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSchedule;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\SystemAiClock;
use Modules\AI\Tests\Support\ThrowingQueueableBuildingPlanner;
use OGame\Events\Game\BuildingCompleted;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\Ban;
use OGame\Models\BuildingQueue;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\PlayerGameStateService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/ThrowingQueueableBuildingPlanner.php';

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiBuilding::class, QueueAiBuildingAction::class);
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

    $job->handle();
    $work->update(['state' => AiWorkState::Pending]);
    $job->handle();

    expect(BuildingQueue::query()->where('planet_id', $this->currentPlanetId)->count())->toBe(1)
        ->and(AiActionReceipt::query()->where('player_id', $this->currentUserId)->count())->toBe(1);
});

// The building a session approved travels with the intent. Re-deciding here would let the schedule
// and the queued building name two different objectives -- an account that says it is reaching for
// a shipyard while it quietly upgrades a mine.
test('it queues the building its intent carries rather than re-deciding', function (): void {
    $this->planetAddResources(app()->makeWith(Resources::class, [
        'metal' => 1_000_000,
        'crystal' => 1_000_000,
        'deuterium' => 1_000_000,
    ]));
    $profile = aiWorkProfile($this->currentUserId);

    $work = aiBuildingWork($this->currentUserId, 'scheduled-building', [
        'planet_id' => $this->currentPlanetId,
        'building_id' => hostObjectId('deuterium_synthesizer'),
        'reason' => 'chain:shipyard',
    ]);

    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();

    $receipt = AiActionReceipt::query()->where('idempotency_key', $work->idempotency_key)->sole();

    expect(BuildingQueue::query()
        ->where('planet_id', $this->currentPlanetId)
        ->where('object_id', hostObjectId('deuterium_synthesizer'))
        ->count())->toBe(1)
        ->and($receipt->fresh()?->state)->toBe(AiReceiptState::Accepted)
        ->and($receipt->result[AiActionReceiptResultKey::Decision->value])->toBe([
            'building_id' => hostObjectId('deuterium_synthesizer'),
            'build_reason' => 'chain:shipyard',
        ]);
});

// An intent written without a label still says which building it wants; the label is for whoever
// reads the receipt afterwards, not a precondition for the work.
test('an intent without a build label still queues its building', function (): void {
    $this->planetAddResources(app()->makeWith(Resources::class, [
        'metal' => 1_000_000,
        'crystal' => 1_000_000,
        'deuterium' => 1_000_000,
    ]));
    aiWorkProfile($this->currentUserId);

    $work = aiBuildingWork($this->currentUserId, 'unlabelled-binding', [
        'planet_id' => $this->currentPlanetId,
        'building_id' => hostObjectId('metal_mine'),
    ]);

    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();

    $receipt = AiActionReceipt::query()->where('idempotency_key', $work->idempotency_key)->sole();

    expect(BuildingQueue::query()
        ->where('planet_id', $this->currentPlanetId)
        ->where('object_id', hostObjectId('metal_mine'))
        ->count())->toBe(1)
        ->and($receipt->result[AiActionReceiptResultKey::Decision->value])->toBe([
            'building_id' => hostObjectId('metal_mine'),
            'build_reason' => 'scheduled',
        ]);
});

test('a completed host building queue retains one correlated upgrade experience', function (): void {
    $this->planetAddResources(app()->makeWith(Resources::class, [
            'metal' => 1_000_000,
            'crystal' => 1_000_000,
            'deuterium' => 1_000_000,
        ]));
    aiWorkProfile($this->currentUserId);
    $work = aiBuildingWork($this->currentUserId, 'completed-outcome');

    app()->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();

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

    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();

    expect(AiActionReceipt::query()
        ->where('idempotency_key', $work->idempotency_key)
        ->where('state', AiReceiptState::Rejected)
        ->exists())->toBeTrue();
});

test('the real queue action refuses a planet the account does not own', function (): void {
    $result = app(QueueAiBuilding::class)->handle($this->currentUserId, PHP_INT_MAX, hostObjectId('metal_mine'));

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe(AiQueueActionReason::PlanetNotOwned->value);
});

test('the real queue action rejects banned and vacation players', function (): void {
    Ban::create(['user_id' => $this->currentUserId, 'reason' => 'test ban', 'banned_until' => now()->addHour(), 'canceled' => false]);

    $banned = app(QueueAiBuilding::class)->handle($this->currentUserId, $this->currentPlanetId, hostObjectId('metal_mine'));

    expect($banned->successful)->toBeFalse()
        ->and($banned->reason)->toBe(AiQueueActionReason::PlayerBanned->value);

    Ban::query()->where('user_id', $this->currentUserId)->update(['canceled' => true]);
    User::query()->whereKey($this->currentUserId)->update(['vacation_mode' => true]);

    $vacation = app(QueueAiBuilding::class)->handle($this->currentUserId, $this->currentPlanetId, hostObjectId('metal_mine'));

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

test('the real research action queues a technology the host accepts and refuses what it must', function (): void {
    $this->planetSetObjectLevel('research_lab', 1);
    $this->planetAddResources(app()->makeWith(Resources::class, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]));

    $queued = app(QueueAiResearch::class)->handle($this->currentUserId, $this->currentPlanetId, hostObjectId('energy_technology'));
    $notResearch = app(QueueAiResearch::class)->handle($this->currentUserId, $this->currentPlanetId, hostObjectId('metal_mine'));
    $notOwned = app(QueueAiResearch::class)->handle($this->currentUserId, PHP_INT_MAX, hostObjectId('energy_technology'));
    $invalidObject = app(QueueAiResearch::class)->handle($this->currentUserId, $this->currentPlanetId, PHP_INT_MAX);

    expect($queued->successful)->toBeTrue()
        ->and(DB::table('research_queues')->where('planet_id', $this->currentPlanetId)->where('object_id', hostObjectId('energy_technology'))->exists())->toBeTrue()
        ->and($notResearch->reason)->toBe(AiQueueActionReason::NotAResearch->value)
        ->and($notOwned->reason)->toBe(AiQueueActionReason::PlanetNotOwned->value)
        ->and($invalidObject->successful)->toBeFalse()
        ->and($invalidObject->reason)->toBeString()->not->toBeEmpty();
});

test('the real research action rejects banned and vacation players', function (): void {
    Ban::create(['user_id' => $this->currentUserId, 'reason' => 'test ban', 'banned_until' => now()->addHour(), 'canceled' => false]);

    $banned = app(QueueAiResearch::class)->handle($this->currentUserId, $this->currentPlanetId, hostObjectId('energy_technology'));

    expect($banned->reason)->toBe(AiQueueActionReason::PlayerBanned->value);

    Ban::query()->where('user_id', $this->currentUserId)->update(['canceled' => true]);
    User::query()->whereKey($this->currentUserId)->update(['vacation_mode' => true]);

    $vacation = app(QueueAiResearch::class)->handle($this->currentUserId, $this->currentPlanetId, hostObjectId('energy_technology'));

    expect($vacation->reason)->toBe(AiQueueActionReason::VacationMode->value);
});

// An intent created before the payload carried its technology has no such binding, so the account
// decides again -- which is what the session that scheduled it would have done.
test('a research intent without a payload decides again', function (): void {
    aiWorkProfile($this->currentUserId);
    $this->planetSetObjectLevel('solar_plant', 20);
    $this->planetSetObjectLevel('metal_store', 10);
    $this->planetSetObjectLevel('crystal_store', 10);
    $this->planetSetObjectLevel('deuterium_store', 10);
    $this->planetAddResources(app()->makeWith(Resources::class, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]));

    foreach (aiResearchChainFacilities() as $machineName => $level) {
        $this->planetSetObjectLevel($machineName, $level);
    }

    $work = AiWorkItem::create([
        'player_id' => $this->currentUserId,
        'kind' => AiWorkKind::QueueResearch,
        'due_at' => now(),
        'idempotency_key' => 'research-replan:' . $this->currentUserId,
        'state' => AiWorkState::Pending,
    ]);

    app()->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();

    expect(DB::table('research_queues')->where('planet_id', $this->currentPlanetId)->count())->toBe(1)
        ->and(AiActionReceipt::query()->where('idempotency_key', $work->idempotency_key)->value('state'))->toBe(AiReceiptState::Accepted);
});

/** @return array<string, int> every facility the host's graph asks to stand on a planet */
function aiResearchChainFacilities(): array
{
    $levels = [];
    foreach ([...ObjectService::getResearchObjects(), ...ObjectService::getUnitObjects()] as $object) {
        foreach (ObjectService::getRecursiveRequirements($object->machine_name) as $machineName => $level) {
            $type = ObjectService::getObjectByMachineName($machineName)->type;
            if (in_array($type, [GameObjectType::Building, GameObjectType::Station], true)) {
                $levels[$machineName] = max($levels[$machineName] ?? $level, $level);
            }
        }
    }

    return $levels;
}

test('lock contention retries work without an action', function (): void {
    $work = aiBuildingWork($this->currentUserId, 'locked');
    $lock = Cache::lock('ai:player:' . $this->currentUserId, 300);
    $lock->get();

    try {
        $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();
    } finally {
        $lock->release();
    }

    expect($work->fresh()?->state)->toBe(AiWorkState::Retry)
        ->and($work->fresh()?->attempts)->toBe(0)
        ->and(AiActionReceipt::query()->where('idempotency_key', $work->idempotency_key)->count())->toBe(0);
});

test('repeated lock contention does not poison a work item', function (): void {
    $work = aiBuildingWork($this->currentUserId, 'locked-repeated', attempts: 2);
    $lock = Cache::lock('ai:player:' . $this->currentUserId, 300);
    $lock->get();

    try {
        $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();
        $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();
    } finally {
        $lock->release();
    }

    expect($work->fresh()?->state)->toBe(AiWorkState::Retry)
        ->and($work->fresh()?->attempts)->toBe(0);
});

test('a disabled profile completes work without a game action', function (): void {
    aiWorkProfile($this->currentUserId, false);
    $work = aiBuildingWork($this->currentUserId, 'disabled');

    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();

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

    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $future->id])->handle();
    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $terminal->id])->handle();

    expect($future->fresh()?->state)->toBe(AiWorkState::Pending)
        ->and($terminal->fresh()?->state)->toBe(AiWorkState::Completed)
        ->and(BuildingQueue::query()->where('planet_id', $this->currentPlanetId)->count())->toBe(0);
});

test('accelerated future sessions are claimable by the worker', function (): void {
    config(['ai.population.session_interval_seconds' => 5]);
    aiWorkProfile($this->currentUserId);
    $work = AiWorkItem::create([
        'player_id' => $this->currentUserId,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now()->addMinute(),
        'schedule_generation' => 1,
        'idempotency_key' => 'session:accelerated:' . $this->currentUserId,
        'state' => AiWorkState::Pending,
    ]);

    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();

    expect($work->fresh()?->state)->toBe(AiWorkState::Completed)
        ->and(AiWorkItem::query()
            ->where('player_id', $this->currentUserId)
            ->where('kind', AiWorkKind::RunSession)
            ->where('id', '!=', $work->id)
            ->exists())->toBeTrue();
});

test('an expired lease is reclaimed so a queue retry can finish the work', function (): void {
    $this->planetAddResources(app()->makeWith(Resources::class, [
        'metal' => 1_000_000,
        'crystal' => 1_000_000,
        'deuterium' => 1_000_000,
    ]));
    aiWorkProfile($this->currentUserId);
    $work = aiBuildingWork($this->currentUserId, 'reclaim');
    // A worker killed mid-handle leaves the item Leased with an expired lease.
    $work->update([
        'state' => AiWorkState::Leased,
        'lease_token' => 'dead-worker',
        'lease_until' => now()->subMinute(),
    ]);

    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])
        ->handle();

    expect($work->fresh()?->state)->toBe(AiWorkState::Completed)
        ->and(AiActionReceipt::query()->where('idempotency_key', $work->idempotency_key)->count())->toBe(1);
});

test('a live lease is not reclaimed by another worker', function (): void {
    aiWorkProfile($this->currentUserId);
    $work = aiBuildingWork($this->currentUserId, 'live-lease');
    $work->update([
        'state' => AiWorkState::Leased,
        'lease_token' => 'live-worker',
        'lease_until' => now()->addMinute(),
    ]);

    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])
        ->handle();

    expect($work->fresh()?->state)->toBe(AiWorkState::Leased)
        ->and($work->fresh()?->lease_token)->toBe('live-worker')
        ->and(AiActionReceipt::query()->where('idempotency_key', $work->idempotency_key)->count())->toBe(0);
});

test('a profile without a planet receives a safe rejection', function (): void {
    $playerId = $this->currentUserId + 100_000;
    aiWorkProfile($playerId);
    $work = aiBuildingWork($playerId, 'no-planet');

    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();

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
    // This container-bound failure seam isolates retry limits; normal tests use the
    // production planner, which the job consults when an intent carries no building.
    $this->app->bind(QueueableBuildingPlanner::class, ThrowingQueueableBuildingPlanner::class);

    expect(fn () => $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $retry->id])->handle())->toThrow(RuntimeException::class, 'test decision failure');
    expect(fn () => $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $failed->id])->handle())->toThrow(RuntimeException::class, 'test decision failure');

    expect($retry->fresh()?->state)->toBe(AiWorkState::Retry)
        ->and($failed->fresh()?->state)->toBe(AiWorkState::Failed);
});

test('an exhausted session recovers when the job itself marks the work failed', function (): void {
    aiWorkProfile($this->currentUserId);
    $schedule = AiSchedule::create([
        'player_id' => $this->currentUserId,
        'timezone' => 'UTC',
        'next_due_at' => now(),
        'generation' => 3,
    ]);
    $workItem = AiWorkItem::create([
        'player_id' => $this->currentUserId,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now(),
        'schedule_generation' => 3,
        'idempotency_key' => 'session:failed-in-handle:' . $this->currentUserId,
        'state' => AiWorkState::Pending,
        'attempts' => 2,
    ]);
    $this->app->bind(RunAiSession::class, static fn (): RunAiSession => new class implements RunAiSession
    {
        public function handle(AiProfile $profile, AiWorkItem $workItem): void
        {
            throw new RuntimeException('session failure');
        }
    });

    expect(fn () => $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $workItem->id])->handle())
        ->toThrow(RuntimeException::class, 'session failure');

    $successor = AiWorkItem::query()
        ->where('idempotency_key', 'session:' . $this->currentUserId . ':4')
        ->sole();

    expect($workItem->fresh()?->state)->toBe(AiWorkState::Failed)
        ->and($schedule->fresh()?->generation)->toBe(4)
        ->and($successor->state)->toBe(AiWorkState::Pending)
        ->and($successor->schedule_generation)->toBe(4);
});

test('a job that exhausted its attempts leaves the work item for lease reclaim', function (): void {
    $workItem = aiBuildingWork($this->currentUserId, 'lease-reclaim', attempts: 2);

    (new ProcessAiWork($workItem->id))->failed(new RuntimeException('worker died before the lease expired'));

    $unchanged = $workItem->fresh();

    expect($unchanged?->state)->toBe(AiWorkState::Pending)
        ->and($unchanged?->attempts)->toBe(2)
        ->and($unchanged?->lease_token)->toBeNull();
});

test('an exhausted session schedules one delayed successor without reopening the failed item', function (): void {
    $schedule = AiSchedule::create([
        'player_id' => $this->currentUserId,
        'timezone' => 'UTC',
        'next_due_at' => now(),
        'generation' => 3,
    ]);
    $workItem = AiWorkItem::create([
        'player_id' => $this->currentUserId,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now(),
        'schedule_generation' => 3,
        'idempotency_key' => 'session:' . $this->currentUserId . ':3',
        'state' => AiWorkState::Failed,
        'attempts' => 3,
    ]);
    $job = new ProcessAiWork($workItem->id);

    $job->failed(new RuntimeException('session failed'));
    $job->failed(new RuntimeException('duplicate failure callback'));

    $successor = AiWorkItem::query()
        ->where('idempotency_key', 'session:' . $this->currentUserId . ':4')
        ->sole();

    expect($workItem->fresh()?->state)->toBe(AiWorkState::Failed)
        ->and($schedule->fresh()?->generation)->toBe(4)
        ->and($successor->state)->toBe(AiWorkState::Pending)
        ->and($successor->schedule_generation)->toBe(4)
        ->and($successor->due_at->greaterThan(now()))->toBeTrue()
        ->and(AiWorkItem::query()->where('idempotency_key', 'session:' . $this->currentUserId . ':4')->count())->toBe(1);
});

/** The host's own identifier for an object, so a test never states an id itself. */
function hostObjectId(string $machineName): int
{
    return (int) ObjectService::getObjectByMachineName($machineName)->id;
}

// An account that cannot afford anything decides nothing, and says so in the receipt rather than
// inventing a building it cannot pay for: the same outcome a player has while saving. The planet is
// left with nothing to spend and nothing accrued, so the planner has no legal answer at all.
test('an intent with nothing affordable is refused with a reason', function (): void {
    Planet::query()->where('user_id', $this->currentUserId)->update([
        'metal' => 0,
        'crystal' => 0,
        'deuterium' => 0,
        'time_last_update' => now()->getTimestamp(),
    ]);
    aiWorkProfile($this->currentUserId);
    $work = aiBuildingWork($this->currentUserId, 'nothing-affordable');

    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();

    $receipt = AiActionReceipt::query()->where('idempotency_key', $work->idempotency_key)->sole();

    expect($receipt->state)->toBe(AiReceiptState::Rejected)
        ->and($receipt->result[AiActionReceiptResultKey::Reason->value])->toBe(AiQueueActionReason::NothingQueueable->value)
        ->and(BuildingQueue::query()->where('planet_id', $this->currentPlanetId)->count())->toBe(0);
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
