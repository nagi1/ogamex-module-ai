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

    expect(array_column($trace->candidates, 'action'))->toEqualCanonicalizing([
        AiCandidateActionType::DoNothing->name,
        AiCandidateActionType::SaveResources->name,
        AiCandidateActionType::Build->name,
        AiCandidateActionType::Research->name,
        AiCandidateActionType::QueueUnits->name,
        AiCandidateActionType::FleetSave->name,
        AiCandidateActionType::Spy->name,
        AiCandidateActionType::Raid->name,
        AiCandidateActionType::Colonize->name,
    ]);
    expect($trace->score_components)->toHaveKeys([
        'resource_need', 'safety', 'target_confidence', 'travel_cost', 'recovery', 'archetype_preference', 'seeded_variation',
    ]);
    expect(json_encode($trace->candidates, JSON_THROW_ON_ERROR))->not->toContain('unpublished_defender_fleet');
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

/** @param array<string, bool> $actions @param array<int, array<string, mixed>> $reports */
function aiDeterministicSnapshot(int $playerId, int $planetId, CarbonImmutable $now, array $actions, bool $fleetsaveEligible, array $reports = []): PerceptionSnapshot
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
        'recoveryFactor' => 0.1,
        'sourceTimestamps' => ['owned_state' => $now->toIso8601String()],
    ]);
}
