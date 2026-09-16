<?php

use Illuminate\Support\Facades\Date;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicy;

require_once __DIR__ . '/../Support/FixturePlayerPerceptionBuilder.php';

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Application;
use Modules\AI\Actions\RunAiSessionAction;
use Modules\AI\Contracts\ArchetypePolicyResolver;
use Modules\AI\Contracts\RunAiSession;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicyRegistry;
use Modules\AI\Domain\Decision\Policies\CasualPolicy;
use Modules\AI\Domain\Decision\Policies\FleeterPolicy;
use Modules\AI\Domain\Decision\Policies\MinerPolicy;
use Modules\AI\Domain\Decision\Policies\TraderPolicy;
use Modules\AI\Domain\Decision\Policies\TurtlePolicy;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Domain\Perception\PlayerPerceptionBuilder;
use Modules\AI\Domain\Routine\SessionPlan;
use Modules\AI\Domain\Scheduling\NextDueTimeCalculator;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiCandidateRejectionReason;
use Modules\AI\Enums\AiCapability;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiDecisionTrace;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSchedule;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use Modules\AI\Support\SystemAiClock;
use Modules\AI\Tests\Support\FixturePlayerPerceptionBuilder;
use OGame\Models\BuildingQueue;
use OGame\Models\FleetMission;
use OGame\Models\ResearchQueue;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    $now = aiDeterministicNow();
    Date::setTestNow($now);
    $this->app->bind(AiClock::class, SystemAiClock::class);
    $this->app->bind(RandomSource::class, SeededRandomSource::class);
    $this->app->bind(RunAiSession::class, RunAiSessionAction::class);
    $this->app->tag([MinerPolicy::class, TurtlePolicy::class, FleeterPolicy::class, TraderPolicy::class, CasualPolicy::class], ArchetypePolicy::class);
    $this->app->singleton(ArchetypePolicyResolver::class, fn ($app): ArchetypePolicyRegistry => $app->makeWith(ArchetypePolicyRegistry::class, [
        'policies' => $app->tagged(ArchetypePolicy::class),
    ]));
});

test('a full published candidate set is traced without unpublished target state', function (): void {
    $now = aiDeterministicNow();
    aiDeterministicInstallPerception($this->app, aiDeterministicSnapshot($this->currentUserId, $this->currentPlanetId, $now, [
        AiCapability::SaveResources->value => true,
        AiCapability::Build->value => true,
        AiCapability::Research->value => true,
        AiCapability::QueueUnits->value => true,
        AiCapability::Spy->value => true,
        AiCapability::Colonize->value => true,
    ], true));
    $work = aiDeterministicRun(aiDeterministicProfile(AiArchetype::Casual, $this->currentUserId));
    $trace = AiDecisionTrace::query()->where('work_item_id', $work->id)->firstOrFail();

    // Raid is absent: the fixture report points at a report the host never issued, so the raid
    // planner declines it as not viable -- the same gate the stale/forbidden test below asserts.
    expect(array_column($trace->candidates, 'action'))->toEqualCanonicalizing([
        AiCandidateActionType::DoNothing->name,
        AiCandidateActionType::SaveResources->name,
        AiCandidateActionType::Build->name,
        AiCandidateActionType::Research->name,
        AiCandidateActionType::QueueUnits->name,
        AiCandidateActionType::FleetSave->name,
        AiCandidateActionType::Spy->name,
        AiCandidateActionType::Colonize->name,
    ]);
    expect($trace->score_components)->toHaveKeys([
        'resource_need', 'safety', 'target_confidence', 'travel_cost', 'recovery', 'archetype_preference', 'seeded_variation',
    ]);
    expect(json_encode($trace->candidates, JSON_THROW_ON_ERROR))->not->toContain('unpublished_defender_fleet');
});

test('an explicit session interval accelerates only the successor schedule', function (): void {
    config(['ai.population.session_interval_seconds' => 5]);
    $now = aiDeterministicNow();
    $plan = app()->makeWith(SessionPlan::class, [
        'sessionEndsAt' => $now,
        'nextDueAt' => $now->addDay(),
    ]);

    expect(app(NextDueTimeCalculator::class)->fromSession($plan, $now)->equalTo($now->addSeconds(5)))->toBeTrue();
});

test('a hostile reaction wake pulls the next session earlier (V2)', function (): void {
    config(['ai.population.session_interval_seconds' => 3600]);
    $now = CarbonImmutable::create(2026, 9, 11, 12, 0, 0, 'UTC');
    Date::setTestNow($now);

    $profile = aiDeterministicProfile(AiArchetype::Miner, $this->currentUserId);
    $profile->update(['settings' => ['timezone' => 'UTC']]);
    $profile->refresh();

    $reactionWakeAt = $now->addSeconds(90)->getTimestamp();
    aiDeterministicInstallPerception($this->app, aiDeterministicSnapshot(
        $this->currentUserId,
        $this->currentPlanetId,
        $now,
        [],
        false,
        [],
        [],
        $reactionWakeAt,
    ));

    aiDeterministicRun($profile);
    $schedule = AiSchedule::query()->where('player_id', $this->currentUserId)->firstOrFail();

    expect($schedule->next_due_at->getTimestamp())->toBe($reactionWakeAt);
});

test('a material event inside the waking window pulls the next session earlier (SP3)', function (): void {
    config(['ai.population.session_interval_seconds' => 3600]);
    $now = CarbonImmutable::create(2026, 9, 11, 12, 0, 0, 'UTC');
    Date::setTestNow($now);

    $profile = aiDeterministicProfile(AiArchetype::Miner, $this->currentUserId);
    $profile->update(['settings' => ['timezone' => 'UTC']]);
    $profile->refresh();

    aiDeterministicInstallPerception($this->app, aiDeterministicSnapshot(
        $this->currentUserId,
        $this->currentPlanetId,
        $now,
        [],
        false,
        [],
        [['mission_id' => 9, 'mission_type' => 3, 'time_arrival' => $now->addSeconds(90)->getTimestamp(), 'planet_id_to' => $this->currentPlanetId]],
    ));

    BuildingQueue::query()->forceCreate([
        'planet_id' => $this->currentPlanetId,
        'object_id' => 1,
        'object_level_target' => 2,
        'time_end' => $now->addMinute()->getTimestamp(),
    ]);
    ResearchQueue::query()->forceCreate([
        'planet_id' => $this->currentPlanetId,
        'object_id' => 1,
        'object_level_target' => 2,
        'time_end' => $now->addSeconds(120)->getTimestamp(),
    ]);
    FleetMission::query()->forceCreate([
        'user_id' => $this->currentUserId,
        'planet_id_from' => $this->currentPlanetId,
        'planet_id_to' => $this->currentPlanetId,
        'mission_type' => 3,
        'time_departure' => $now->getTimestamp(),
        'time_arrival' => $now->addSeconds(180)->getTimestamp(),
        'time_arrival_ms' => 0,
        'processed' => 0,
        'canceled' => 0,
    ]);

    aiDeterministicRun($profile);
    $schedule = AiSchedule::query()->where('player_id', $this->currentUserId)->firstOrFail();

    // The earliest material event (the build at +60 s) wins; the wake is at most the event plus
    // the arrival delay's ceiling, and well before the fixed one-hour interval it replaced.
    expect($schedule->next_due_at->getTimestamp())
        ->toBeLessThan($now->addSeconds(3600)->getTimestamp())
        ->and($schedule->next_due_at->getTimestamp())->toBeLessThanOrEqual($now->addMinute()->addSeconds(300)->getTimestamp());
});

test('stale and forbidden reports are rejected before raid scoring', function (): void {
    $now = aiDeterministicNow();
    aiDeterministicInstallPerception($this->app, aiDeterministicSnapshot($this->currentUserId, $this->currentPlanetId, $now, [], false, [
        ['report_id' => 10, 'observed_at' => $now->subHour()->getTimestamp(), 'expires_at' => $now->subSecond()->getTimestamp(), 'confidence' => 1, 'travel_cost' => 0.1, 'attack_permitted' => true],
        ['report_id' => 11, 'observed_at' => $now->getTimestamp(), 'expires_at' => $now->addHour()->getTimestamp(), 'confidence' => 1, 'travel_cost' => 0.1, 'attack_permitted' => false],
    ]));
    $work = aiDeterministicRun(aiDeterministicProfile(AiArchetype::Fleeter, $this->currentUserId));
    $trace = AiDecisionTrace::query()->where('work_item_id', $work->id)->firstOrFail();

    expect(array_column($trace->candidates, 'action'))->not->toContain(AiCandidateActionType::Raid->name)
        ->and($trace->score_components['rejections'])->toMatchArray([
            'report:10' => AiCandidateRejectionReason::StaleTargetIntel->value,
            'report:11' => AiCandidateRejectionReason::AttackNotPermitted->value,
        ]);
});

test('every persona completes its session and receives one successor schedule', function (): void {
    $now = aiDeterministicNow();
    aiDeterministicInstallPerception($this->app, aiDeterministicSnapshot($this->currentUserId, $this->currentPlanetId, $now, [], true));

    foreach (AiArchetype::cases() as $archetype) {
        // A real host account per persona: a profile whose account the host does
        // not have is a final account, and a final account stops (L2).
        $playerId = $this->createUser()->id;
        $work = aiDeterministicRun(aiDeterministicProfile($archetype, $playerId));
        $schedule = AiSchedule::query()->where('player_id', $playerId)->firstOrFail();

        expect($work->fresh()?->state)->toBe(AiWorkState::Completed)
            ->and($schedule->generation)->toBe(2)
            ->and(AiWorkItem::query()->where('player_id', $playerId)->where('kind', AiWorkKind::RunSession->value)->where('state', AiWorkState::Pending->value)->count())->toBe(1);
    }
});

function aiDeterministicNow(): CarbonImmutable
{
    return CarbonImmutable::create(2026, 9, 11, 8, 0, 0, 'UTC') ?? throw app()->makeWith(LogicException::class, ['message' => 'Unable to create frozen clock time.']);
}

function aiDeterministicRun(AiProfile $profile): AiWorkItem
{
    $work = AiWorkItem::create([
        'player_id' => $profile->player_id,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now(),
        'idempotency_key' => 'deterministic-session:' . $profile->player_id,
        'state' => AiWorkState::Pending,
    ]);
    app()->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();

    return $work;
}

function aiDeterministicProfile(AiArchetype $archetype, int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => $archetype,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42 + $playerId,
    ]);
}

function aiDeterministicInstallPerception(Application $app, PerceptionSnapshot $snapshot): void
{
    $app->instance(PlayerPerceptionBuilder::class, $app->makeWith(FixturePlayerPerceptionBuilder::class, ['snapshot' => $snapshot]));
}

/** @param array<string, bool> $actions @param array<int, array<string, mixed>> $reports @param list<array{mission_id:int, mission_type:int, time_arrival:int, planet_id_to:int}> $inboundFleets */
function aiDeterministicSnapshot(int $playerId, int $planetId, CarbonImmutable $now, array $actions, bool $fleetsaveEligible, array $reports = [], array $inboundFleets = [], ?int $reactionWakeAt = null): PerceptionSnapshot
{
    return app()->makeWith(PerceptionSnapshot::class, [
        'playerId' => $playerId,
        'observedAt' => $now,
        'planets' => [['id' => $planetId, 'resources' => ['metal' => 5_000, 'crystal' => 5_000, 'deuterium' => 5_000]]],
        'targetReports' => $reports ?: [[
            'report_id' => 8,
            'observed_at' => $now->getTimestamp(),
            'expires_at' => $now->addHour()->getTimestamp(),
            'confidence' => 0.8,
            'travel_cost' => 0.2,
            'attack_permitted' => true,
        ]],
        'availableActions' => $actions + array_fill_keys(array_map(static fn (AiCapability $capability): string => $capability->value, AiCapability::cases()), false),
        'fleetsaveEligible' => $fleetsaveEligible,
        'inboundFleets' => $inboundFleets,
        'reactionWakeAt' => $reactionWakeAt,
        'recoveryFactor' => 0.1,
        'sourceTimestamps' => ['owned_state' => $now->toIso8601String()],
        'fleetSlotsFree' => 2,
        'colonizeEligible' => true,
    ]);
}
