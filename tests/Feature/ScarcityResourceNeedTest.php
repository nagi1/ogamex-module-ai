<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Modules\AI\Contracts\ArchetypePolicyResolver;
use Modules\AI\Domain\Decision\CandidateActionFactory;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicy;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicyRegistry;
use Modules\AI\Domain\Decision\Policies\FleeterPolicy;
use Modules\AI\Domain\Decision\UtilityScorer;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiCapability;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * A scarce resource ranks above the persona's habit: the surplus the account cannot spend on what it
 * is short of is the one situation where a fleeter's Build outranks its ship habit. The boost is a
 * bounded function of the abundant-to-scarce ratio, so balanced accounts keep their habit unchanged.
 */
beforeEach(function (): void {
    $this->app->bind(RandomSource::class, SeededRandomSource::class);
    scarcityRegisterPolicies($this->app);
});

function scarcityRegisterPolicies(Container $app): void
{
    $app->tag([FleeterPolicy::class], ArchetypePolicy::class);
    $app->singleton(ArchetypePolicyResolver::class, static fn (Container $container): ArchetypePolicyRegistry => $container->makeWith(ArchetypePolicyRegistry::class, [
        'policies' => $container->tagged(ArchetypePolicy::class),
    ]));
}

/** @param array<string, float|int> $resources */
function scarcitySnapshot(array $resources): PerceptionSnapshot
{
    $actions = [AiCapability::Build->value => true, AiCapability::QueueUnits->value => true];

    return app()->makeWith(PerceptionSnapshot::class, [
        'playerId' => 0,
        'observedAt' => CarbonImmutable::create(2026, 9, 11, 8, 0, 0, 'UTC'),
        'planets' => [['id' => 1, 'resources' => $resources]],
        'targetReports' => [],
        'availableActions' => $actions + array_fill_keys(array_map(static fn (AiCapability $capability): string => $capability->value, AiCapability::cases()), false),
        'fleetsaveEligible' => false,
        'recoveryFactor' => 0.1,
        'sourceTimestamps' => [],
    ]);
}

/** @return array<int, array{type: AiCandidateActionType, need: float}> */
function scarcityFeatures(array $resources): array
{
    $candidates = app(CandidateActionFactory::class)->create(scarcitySnapshot($resources))->candidates;

    return array_map(
        static fn ($candidate): array => ['type' => $candidate->type, 'need' => $candidate->features['resource_need']],
        $candidates,
    );
}

function scarcityNeed(array $features, AiCandidateActionType $type): float
{
    foreach ($features as $feature) {
        if ($feature['type'] === $type) {
            return $feature['need'];
        }
    }

    throw new RuntimeException('candidate not found');
}

test('a scarce resource boosts only the build need', function (): void {
    $features = scarcityFeatures(['metal' => 100_000, 'crystal' => 1_000, 'deuterium' => 1_000]);

    expect(scarcityNeed($features, AiCandidateActionType::Build))->toBeGreaterThan(1.0)
        ->and(scarcityNeed($features, AiCandidateActionType::QueueUnits))->toBe(1.0);
});

test('a balanced account keeps the habit unchanged', function (): void {
    $features = scarcityFeatures(['metal' => 100_000, 'crystal' => 100_000, 'deuterium' => 100_000]);

    expect(scarcityNeed($features, AiCandidateActionType::Build))->toBe(1.0)
        ->and(scarcityNeed($features, AiCandidateActionType::QueueUnits))->toBe(1.0);
});

test('an account with no surplus gets no scarcity boost', function (): void {
    $features = scarcityFeatures(['metal' => 500, 'crystal' => 100, 'deuterium' => 100]);

    expect(scarcityNeed($features, AiCandidateActionType::Build))->toBe(0.2)
        ->and(scarcityNeed($features, AiCandidateActionType::QueueUnits))->toBe(0.2);
});

test('a severe imbalance makes the fleeter build the mine instead of ships', function (): void {
    $profile = AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Fleeter, 'skill_band' => AiSkillBand::Veteran, 'random_seed' => 1]);
    $generation = app(CandidateActionFactory::class)->create(scarcitySnapshot(['metal' => 1_000_000, 'crystal' => 1_000, 'deuterium' => 1_000]));

    $best = app(UtilityScorer::class)->score($profile, $generation, 'scarcity')[0];

    expect($best->candidate->type)->toBe(AiCandidateActionType::Build);
});

test('a balanced fleeter keeps its ship habit', function (): void {
    $profile = AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Fleeter, 'skill_band' => AiSkillBand::Veteran, 'random_seed' => 1]);
    $generation = app(CandidateActionFactory::class)->create(scarcitySnapshot(['metal' => 100_000, 'crystal' => 100_000, 'deuterium' => 100_000]));

    $best = app(UtilityScorer::class)->score($profile, $generation, 'scarcity')[0];

    expect($best->candidate->type)->toBe(AiCandidateActionType::QueueUnits);
});
