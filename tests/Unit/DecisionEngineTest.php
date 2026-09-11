<?php

use Modules\AI\Domain\Decision\Policies\ArchetypePolicy;
use Modules\AI\Support\RandomSource;
use Modules\AI\Tests\Support\FixtureAiClock;

require_once __DIR__ . '/../Support/FixtureAiClock.php';

use Carbon\CarbonImmutable;
use Modules\AI\Contracts\ArchetypePolicyResolver;
use Modules\AI\Domain\Decision\DecisionEngine;
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
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\SeededRandomSource;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    $this->app->bind(RandomSource::class, SeededRandomSource::class);
    $this->app->tag([MinerPolicy::class, TurtlePolicy::class, FleeterPolicy::class, TraderPolicy::class, CasualPolicy::class], ArchetypePolicy::class);
    $this->app->singleton(ArchetypePolicyResolver::class, fn ($app): ArchetypePolicyRegistry => $app->makeWith(ArchetypePolicyRegistry::class, [
        'policies' => $app->tagged(ArchetypePolicy::class),
    ]));
});

test('frozen seed and observation reproduce the trace', function () {
    $profile = aiDecisionProfile(AiArchetype::Casual);
    $snapshot = aiDecisionSnapshot(['build' => true]);

    $first = app(DecisionEngine::class)->decide($profile, $snapshot, 'frozen-decision')->record();
    $second = app(DecisionEngine::class)->decide($profile, $snapshot, 'frozen-decision')->record();

    expect($second)->toBe($first);
});

test('miner never generates a raid choice', function () {
    $trace = app(DecisionEngine::class)->decide(aiDecisionProfile(AiArchetype::Miner), aiDecisionSnapshot([], false, [
        ['report_id' => 12, 'observed_at' => 1_789_012_345, 'expires_at' => 1_789_016_000, 'confidence' => 1, 'travel_cost' => 0.1, 'attack_permitted' => true],
    ]), 'miner');

    $types = array_map(static fn ($candidate): AiCandidateActionType => $candidate->candidate->type, $trace->candidates);

    expect($types)->not->toContain(AiCandidateActionType::Raid);
});

test('fleeter selects an eligible fleetsave', function () {
    $trace = app(DecisionEngine::class)->decide(aiDecisionProfile(AiArchetype::Fleeter), aiDecisionSnapshot([], true), 'save');

    expect($trace->selected->candidate->type)->toBe(AiCandidateActionType::FleetSave);
});

test('stale intel is rejected before scoring a raid', function () {
    $trace = app(DecisionEngine::class)->decide(aiDecisionProfile(AiArchetype::Fleeter), aiDecisionSnapshot([], false, [
        ['report_id' => 12, 'observed_at' => 1_789_012_345, 'expires_at' => 1_789_012_344, 'confidence' => 1, 'travel_cost' => 0.1, 'attack_permitted' => true],
    ]), 'stale');

    $types = array_map(static fn ($candidate): AiCandidateActionType => $candidate->candidate->type, $trace->candidates);

    expect($types)->not->toContain(AiCandidateActionType::Raid);
    expect($trace->rejections['report:12'])->toBe('stale_target_intel');
});

test('perception discards unpublished target fields', function () {
    $this->app->instance(AiClock::class, $this->app->makeWith(FixtureAiClock::class, ['now' => CarbonImmutable::createFromTimestamp(1_789_012_345)]));
    $builder = app(PlayerPerceptionBuilder::class);
    $snapshot = $builder->fromObservation([
        'player_id' => 3,
        'observed_at' => 1_789_012_345,
        'planets' => [null, ['resources' => ['metal' => 10]], ['id' => 4, 'resources' => ['metal' => 10]]],
        'target_reports' => [[
            'report_id' => 12,
            'observed_at' => 1_789_012_345,
            'expires_at' => 1_789_016_000,
            'confidence' => 0.5,
            'travel_cost' => 0.2,
            'attack_permitted' => true,
            'defender_fleet' => ['deathstar' => 99],
        ], null, ['report_id' => 13]],
        'source_timestamps' => ['valid' => 1_789_012_345, 'invalid' => 'not-a-timestamp', 4 => 1_789_012_345],
    ]);

    expect(json_encode($snapshot->traceInput(), JSON_THROW_ON_ERROR))->not->toContain('defender_fleet')
        ->and($snapshot->planets)->toHaveCount(1)
        ->and($snapshot->targetReports)->toHaveCount(1)
        ->and($snapshot->sourceTimestamps)->toHaveKey('valid')
        ->and($snapshot->sourceTimestamps)->not->toHaveKey('invalid');
});

test('recovery input is bounded and recorded in the deterministic score', function () {
    $this->app->instance(AiClock::class, $this->app->makeWith(FixtureAiClock::class, ['now' => CarbonImmutable::createFromTimestamp(1_789_012_345)]));
    $snapshot = app(PlayerPerceptionBuilder::class)->fromObservation([
        'player_id' => 3,
        'observed_at' => 1_789_012_345,
        'recovery_factor' => 99,
    ]);

    $trace = app(DecisionEngine::class)->decide(aiDecisionProfile(AiArchetype::Miner), $snapshot, 'bounded-recovery');

    expect($snapshot->recoveryFactor)->toBe(1.0);
    expect($trace->selected->components['recovery'])->toBe(20.0);
});

/** @param array<string, bool> $actions @param array<int, array<string, mixed>> $reports */
function aiDecisionSnapshot(array $actions, bool $fleetsaveEligible = false, array $reports = []): PerceptionSnapshot
{
    return app()->makeWith(PerceptionSnapshot::class, [
        'playerId' => 1,
        'observedAt' => CarbonImmutable::createFromTimestamp(1_789_012_345),
        'planets' => [['id' => 1, 'resources' => ['metal' => 2_000, 'crystal' => 2_000, 'deuterium' => 2_000]]],
        'targetReports' => $reports,
        'availableActions' => $actions + ['save_resources' => false, 'build' => false, 'research' => false, 'queue_units' => false, 'spy' => false, 'colonize' => false],
        'fleetsaveEligible' => $fleetsaveEligible,
        'recoveryFactor' => 0.0,
        'sourceTimestamps' => ['owned_state' => '2026-09-11T00:00:00+00:00'],
    ]);
}

function aiDecisionProfile(AiArchetype $archetype): AiProfile
{
    return app()->makeWith(AiProfile::class, ['attributes' => [
        'id' => 1,
        'player_id' => 1,
        'archetype' => $archetype,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
    ]]);
}
