<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Modules\AI\Actions\RecordAiStopReasonAction;
use Modules\AI\Actions\ResolveAiAdmissionAction;
use Modules\AI\Actions\SetAiWorkSwitchAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiStopReason;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiOperabilitySwitch;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiStopCounter;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;
use Modules\AI\Tests\Support\FixtureAiClock;

require_once __DIR__ . '/../Support/AiQueueModuleTestCase.php';
require_once __DIR__ . '/../Support/FixtureAiClock.php';

// The module is enabled so its own configuration and bindings are the ones under test.
uses(AiQueueModuleTestCase::class);

// The module clock is pinned to the time the host test case travels to, so a work item's
// due time, a lease and the scheduler's own clock all agree.
const ADMISSION_NOW = '2024-01-01 00:00:00';
const ADMISSION_DAY = '2024-01-01';

beforeEach(function (): void {
    bindAdmissionClock(ADMISSION_NOW);
});

function bindAdmissionClock(string $now): void
{
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse($now),
    ]));
}

function admissionProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'enabled' => true,
    ]);
}

function admissionWorkItem(int $playerId, AiWorkKind $kind, string $suffix, AiWorkState $state = AiWorkState::Pending, CarbonImmutable|null $leaseUntil = null): AiWorkItem
{
    return AiWorkItem::create([
        'player_id' => $playerId,
        'kind' => $kind,
        'due_at' => CarbonImmutable::parse(ADMISSION_NOW),
        'idempotency_key' => 'admission:' . $suffix . ':' . $playerId,
        'state' => $state,
        'lease_token' => $state === AiWorkState::Leased ? 'admission-lease-' . $suffix : null,
        'lease_until' => $leaseUntil,
    ]);
}

function admissionStopCount(AiStopReason $reason, string $day = ADMISSION_DAY): int
{
    return (int) AiStopCounter::query()
        ->where('reason', $reason->value)
        ->where('observed_on', $day)
        ->sum('occurrences');
}

/**
 * @return array<string, mixed>
 */
function admissionStopContext(AiStopReason $reason, string $day = ADMISSION_DAY): array
{
    return AiStopCounter::query()
        ->where('reason', $reason->value)
        ->where('observed_on', $day)
        ->sole()
        ->last_context ?? [];
}

test('the module ships the pilot caps as configuration', function (): void {
    expect(config('ai.population.profile_cap'))->toBe(0)
        ->and(config('ai.population.active_session_cap'))->toBe(0)
        ->and(config('ai.population.dispatch_batch_size'))->toBe(100)
        ->and(config('ai.population.session_action_cap'))->toBe(1);
});

test('an installation that never recorded a switch decision runs', function (): void {
    admissionProfile($this->currentUserId);

    expect(AiOperabilitySwitch::query()->count())->toBe(0)
        ->and(app(ResolveAiAdmissionAction::class)->forWorkItem()->allowed)->toBeTrue()
        ->and(app(ResolveAiAdmissionAction::class)->forAction()->allowed)->toBeTrue();
});

test('the dispatcher dispatches nothing while staff have the population switched off', function (): void {
    Bus::fake();
    admissionProfile($this->currentUserId);
    $work = admissionWorkItem($this->currentUserId, AiWorkKind::RunSession, 'switched-off');
    app(SetAiWorkSwitchAction::class)->handle(false, 'pilot paused', $this->currentUserId);

    $this->artisan('ai:run-due-work')->assertExitCode(0);

    Bus::assertNothingDispatched();
    expect(admissionStopCount(AiStopReason::StaffSwitch))->toBe(1)
        ->and($work->refresh()->state)->toBe(AiWorkState::Pending);
});

test('a claimed session is left pending while the population is switched off', function (): void {
    admissionProfile($this->currentUserId);
    $work = admissionWorkItem($this->currentUserId, AiWorkKind::RunSession, 'held');
    app(SetAiWorkSwitchAction::class)->handle(false, 'pilot paused', $this->currentUserId);

    expect(app(ResolveAiAdmissionAction::class)->forWorkItem()->allowed)->toBeFalse()
        ->and(app(ResolveAiAdmissionAction::class)->forAction()->allowed)->toBeFalse();

    app()->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();

    expect($work->refresh()->state)->toBe(AiWorkState::Pending)
        ->and(admissionStopCount(AiStopReason::StaffSwitch))->toBe(3);
});

test('one dispatch pass stops at its batch size and records the throttle', function (): void {
    Bus::fake();
    config(['ai.population.dispatch_batch_size' => 1]);
    admissionProfile($this->currentUserId);
    $first = admissionWorkItem($this->currentUserId, AiWorkKind::RunSession, 'batch-first');
    // A later due time keeps the pass order deterministic while both items are already due.
    admissionWorkItem($this->currentUserId, AiWorkKind::RunSession, 'batch-second')
        ->update(['due_at' => CarbonImmutable::parse(ADMISSION_NOW)->addMinute()]);

    $this->artisan('ai:run-due-work')->assertExitCode(0);

    Bus::assertDispatched(ProcessAiWork::class, static fn (ProcessAiWork $job): bool => $job->workItemId === $first->id);
    Bus::assertDispatchedTimes(ProcessAiWork::class, 1);

    expect(AiStopCounter::query()->where('reason', AiStopReason::DispatchLimit->value)->where('observed_on', ADMISSION_DAY)->sole()->last_context)
        ->toEqual(['requested' => 100, 'cap' => 1])
        ->and(admissionStopCount(AiStopReason::DispatchLimit))->toBe(1);
});

test('a population larger than the universe cap stops new work', function (): void {
    Bus::fake();
    config(['ai.population.profile_cap' => 1]);
    $owner = $this->createUser();
    admissionProfile($this->currentUserId);
    admissionProfile($owner->id);
    admissionWorkItem($this->currentUserId, AiWorkKind::RunSession, 'over-cap');

    // The cap counts the whole universe, so the expected figure is read rather than assumed:
    // a shared development database may already hold accounts from an earlier run.
    $profiles = AiProfile::query()->where('enabled', true)->count();

    $this->artisan('ai:run-due-work')->assertExitCode(0);

    Bus::assertNothingDispatched();
    expect(admissionStopContext(AiStopReason::ProfileCap))
        ->toEqual(['profiles' => $profiles, 'cap' => 1]);
});

test('sessions in flight stop dispatch until their lease expires', function (): void {
    Bus::fake();
    config(['ai.population.active_session_cap' => 1]);
    admissionProfile($this->currentUserId);
    $leased = admissionWorkItem(
        $this->currentUserId,
        AiWorkKind::RunSession,
        'inflight',
        AiWorkState::Leased,
        CarbonImmutable::parse(ADMISSION_NOW)->addHour(),
    );
    admissionWorkItem($this->currentUserId, AiWorkKind::RunSession, 'waiting');

    $this->artisan('ai:run-due-work')->assertExitCode(0);

    Bus::assertNothingDispatched();
    expect($leased->refresh()->state)->toBe(AiWorkState::Leased)
        ->and(admissionStopContext(AiStopReason::ActiveSessionCap))
        ->toEqual(['active_sessions' => 1, 'cap' => 1]);

    // A lease that already expired is work the scheduler will retry, not a session running now, so
    // the stranded item is admitted again alongside the one that was waiting all along.
    $leased->update(['lease_until' => CarbonImmutable::parse(ADMISSION_NOW)->subMinute()]);

    $this->artisan('ai:run-due-work')->assertExitCode(0);

    Bus::assertDispatched(ProcessAiWork::class, static fn (ProcessAiWork $job): bool => $job->workItemId === $leased->id);
    Bus::assertDispatchedTimes(ProcessAiWork::class, 2);
});

test('a session action cap of zero lets a session decide and touch nothing', function (): void {
    config(['ai.population.session_action_cap' => 0]);
    admissionProfile($this->currentUserId);
    $work = admissionWorkItem($this->currentUserId, AiWorkKind::BuildFirstBuilding, 'no-action');

    app()->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();

    expect($work->refresh()->state)->toBe(AiWorkState::Completed)
        ->and(AiActionReceipt::query()->where('idempotency_key', $work->idempotency_key)->count())->toBe(0)
        ->and(admissionStopContext(AiStopReason::SessionActionCap))->toEqual(['cap' => 0]);
});

test('the switch records who changed it and the newest decision wins', function (): void {
    app(SetAiWorkSwitchAction::class)->handle(false, 'pilot paused', $this->currentUserId);
    expect(app(ResolveAiAdmissionAction::class)->forWorkItem()->allowed)->toBeFalse();

    bindAdmissionClock('2024-01-01 01:00:00');
    app(SetAiWorkSwitchAction::class)->handle(true, 'pilot resumed', $this->currentUserId);

    $switches = AiOperabilitySwitch::query()->orderBy('id')->get();

    expect($switches)->toHaveCount(2)
        ->and($switches->first()->reason)->toBe('pilot paused')
        ->and($switches->first()->enabled)->toBeFalse()
        ->and($switches->last()->reason)->toBe('pilot resumed')
        ->and($switches->last()->enabled)->toBeTrue()
        ->and($switches->last()->actor_player_id)->toBe($this->currentUserId)
        ->and($switches->last()->changed_at->toDateTimeString())->toBe('2024-01-01 01:00:00')
        ->and(app(ResolveAiAdmissionAction::class)->forWorkItem()->allowed)->toBeTrue();
});

test('a stop reason is counted once per day and starts a fresh count tomorrow', function (): void {
    $today = app(RecordAiStopReasonAction::class)->handle(AiStopReason::ActiveSessionCap, ['active_sessions' => 4]);
    app(RecordAiStopReasonAction::class)->handle(AiStopReason::ActiveSessionCap, ['active_sessions' => 5]);

    $counter = AiStopCounter::query()->where('observed_on', ADMISSION_DAY)->sole();

    expect($counter->occurrences)->toBe(2)
        ->and($counter->scope)->toBe(RecordAiStopReasonAction::SCOPE_UNIVERSE)
        ->and($counter->last_context)->toBe(['active_sessions' => 5])
        ->and($counter->first_seen_at->toDateTimeString())->toBe('2024-01-01 00:00:00')
        ->and($today->id)->toBe($counter->id);

    bindAdmissionClock('2024-01-02 09:00:00');
    app(RecordAiStopReasonAction::class)->handle(AiStopReason::ActiveSessionCap, ['active_sessions' => 6]);

    expect(admissionStopCount(AiStopReason::ActiveSessionCap, '2024-01-01'))->toBe(2)
        ->and(admissionStopCount(AiStopReason::ActiveSessionCap, '2024-01-02'))->toBe(1)
        ->and(admissionStopContext(AiStopReason::ActiveSessionCap, '2024-01-02'))->toBe(['active_sessions' => 6]);
});
