<?php

require_once __DIR__ . '/../Support/FixturePlayerPerceptionBuilder.php';

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Date;
use Modules\AI\Actions\RunAiSessionAction;
use Modules\AI\Contracts\ArchetypePolicyResolver;
use Modules\AI\Contracts\RunAiSession;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicy;
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
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use Modules\AI\Support\SystemAiClock;
use Modules\AI\Tests\Support\FixturePlayerPerceptionBuilder;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    Date::setTestNow(aiPersonaNow());
    $this->app->bind(AiClock::class, SystemAiClock::class);
    $this->app->bind(RandomSource::class, SeededRandomSource::class);
    $this->app->bind(RunAiSession::class, RunAiSessionAction::class);
    aiPersonaRegisterPolicies($this->app);
});

afterEach(fn (): null => Date::setTestNow());

dataset('persona policy mechanics', [
    'miner builds and declines raids' => [AiArchetype::Miner, [AiCapability::Build->value => true], false, true, AiCandidateActionType::Build, false],
    'turtle queues units and declines raids' => [AiArchetype::Turtle, [AiCapability::QueueUnits->value => true], false, true, AiCandidateActionType::QueueUnits, false],
    'fleeter saves fleet before raid' => [AiArchetype::Fleeter, [], true, true, AiCandidateActionType::FleetSave, true],
    'trader colonizes and declines raids' => [AiArchetype::Trader, [AiCapability::Colonize->value => true], false, true, AiCandidateActionType::Colonize, false],
]);

test('each persona applies its published policy through a persisted session', function (AiArchetype $archetype, array $actions, bool $fleetsaveEligible, bool $attackPermitted, AiCandidateActionType $expected, bool $expectsRaid): void {
    $trace = aiPersonaRun($this->app, $this->currentUserId, $this->currentPlanetId, $archetype, $actions, $fleetsaveEligible, $attackPermitted);
    $candidateActions = array_column($trace->candidates, 'action');

    expect($trace->selected_action)->toBe($expected);
    if (!$expectsRaid) {
        expect($candidateActions)->not->toContain(AiCandidateActionType::Raid->name);

        return;
    }

    expect($candidateActions)->toContain(AiCandidateActionType::Raid->name);
})->with('persona policy mechanics');

test('casual safely does nothing when no permitted action is available', function (): void {
    $trace = aiPersonaRun($this->app, $this->currentUserId, $this->currentPlanetId, AiArchetype::Casual, [], false, false);

    expect($trace->selected_action)->toBe(AiCandidateActionType::DoNothing)
        ->and($trace->score_components['rejections']['report:1'])->toBe(AiCandidateRejectionReason::AttackNotPermitted->value);
});

test('skill bands vary selection only within their defined deterministic margins', function (): void {
    $actions = [AiCapability::Build->value => true, AiCapability::Research->value => true];
    $novice = aiPersonaRun($this->app, $this->currentUserId + 10, $this->currentPlanetId, AiArchetype::Miner, $actions, false, false, AiSkillBand::Novice);
    $veteran = aiPersonaRun($this->app, $this->currentUserId + 20, $this->currentPlanetId, AiArchetype::Miner, $actions, false, false, AiSkillBand::Veteran);

    expect($novice->selected_action)->toBeInstanceOf(AiCandidateActionType::class)
        ->and($veteran->selected_action)->toBe(AiCandidateActionType::Build);
});

function aiPersonaRegisterPolicies(Container $app): void
{
    $app->tag([MinerPolicy::class, TurtlePolicy::class, FleeterPolicy::class, TraderPolicy::class, CasualPolicy::class], ArchetypePolicy::class);
    $app->singleton(ArchetypePolicyResolver::class, static fn (Container $container): ArchetypePolicyRegistry => $container->makeWith(ArchetypePolicyRegistry::class, [
        'policies' => $container->tagged(ArchetypePolicy::class),
    ]));
}

/** @param array<string, bool> $actions */
function aiPersonaRun(Container $app, int $playerId, int $planetId, AiArchetype $archetype, array $actions, bool $fleetsaveEligible, bool $attackPermitted, AiSkillBand $skillBand = AiSkillBand::Standard): AiDecisionTrace
{
    $app->instance(PlayerPerceptionBuilder::class, $app->makeWith(FixturePlayerPerceptionBuilder::class, ['snapshot' => aiPersonaSnapshot($playerId, $planetId, $actions, $fleetsaveEligible, $attackPermitted)]));
    $profile = AiProfile::create(['player_id' => $playerId, 'archetype' => $archetype, 'skill_band' => $skillBand, 'random_seed' => 7_171]);
    $work = AiWorkItem::create(['player_id' => $profile->player_id, 'kind' => AiWorkKind::RunSession, 'due_at' => now(), 'idempotency_key' => "persona:{$archetype->value}:{$skillBand->value}:{$playerId}", 'state' => AiWorkState::Pending]);

    $app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();

    // No successor schedule is asserted here: these sessions belong to profile
    // rows whose host account is a fixture, and an account the host does not
    // have stops rather than scheduling (L2). `DeterministicSessionLoopTest`
    // covers the successor on real accounts.
    expect($work->fresh()?->state)->toBe(AiWorkState::Completed);

    return AiDecisionTrace::query()->where('work_item_id', $work->id)->firstOrFail();
}

/** @param array<string, bool> $actions */
function aiPersonaSnapshot(int $playerId, int $planetId, array $actions, bool $fleetsaveEligible, bool $attackPermitted): PerceptionSnapshot
{
    $now = aiPersonaNow();

    return app()->makeWith(PerceptionSnapshot::class, [
        'playerId' => $playerId,
        'observedAt' => $now,
        'planets' => [['id' => $planetId, 'resources' => ['metal' => 5_000, 'crystal' => 5_000, 'deuterium' => 5_000]]],
        'targetReports' => [['report_id' => 1, 'observed_at' => $now->getTimestamp(), 'expires_at' => $now->addHour()->getTimestamp(), 'confidence' => 0.8, 'travel_cost' => 0.2, 'attack_permitted' => $attackPermitted]],
        'availableActions' => $actions + array_fill_keys(array_map(static fn (AiCapability $capability): string => $capability->value, AiCapability::cases()), false),
        'fleetsaveEligible' => $fleetsaveEligible,
        'recoveryFactor' => 0.1,
        'sourceTimestamps' => ['owned_state' => $now->toIso8601String()],
    ]);
}

function aiPersonaNow(): CarbonImmutable
{
    return CarbonImmutable::create(2026, 9, 11, 8, 0, 0, 'UTC') ?? throw app()->makeWith(LogicException::class, ['message' => 'Unable to create frozen clock time.']);
}
