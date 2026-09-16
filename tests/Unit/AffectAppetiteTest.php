<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\UpdateAiAffectStateAction;
use Modules\AI\Contracts\ArchetypePolicyResolver;
use Modules\AI\Domain\Decision\CandidateAction;
use Modules\AI\Domain\Decision\CandidateGeneration;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicy;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicyRegistry;
use Modules\AI\Domain\Decision\Policies\CasualPolicy;
use Modules\AI\Domain\Decision\Policies\FleeterPolicy;
use Modules\AI\Domain\Decision\Policies\MinerPolicy;
use Modules\AI\Domain\Decision\Policies\TraderPolicy;
use Modules\AI\Domain\Decision\Policies\TurtlePolicy;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Decision\UtilityScorer;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use Modules\AI\Tests\Support\FixtureAiClock;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/FixtureAiClock.php';

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    $this->app->bind(RandomSource::class, SeededRandomSource::class);
    $this->app->tag([MinerPolicy::class, TurtlePolicy::class, FleeterPolicy::class, TraderPolicy::class, CasualPolicy::class], ArchetypePolicy::class);
    $this->app->singleton(ArchetypePolicyResolver::class, fn ($app): ArchetypePolicyRegistry => $app->makeWith(ArchetypePolicyRegistry::class, [
        'policies' => $app->tagged(ArchetypePolicy::class),
    ]));
    $this->app->instance(AiClock::class, $this->app->makeWith(FixtureAiClock::class, ['now' => affectNow()]));
});

test('the default weight leaves every score untouched and performs no affect read', function (): void {
    config(['ai.cognition.affect.decision_weight' => 0]);

    $scored = scoreAffect(AiSkillBand::Novice, [AiCandidateActionType::Raid, AiCandidateActionType::FleetSave, AiCandidateActionType::Build]);

    expect(affectOf($scored, AiCandidateActionType::Raid))->toBe(0.0)
        ->and(affectOf($scored, AiCandidateActionType::FleetSave))->toBe(0.0)
        ->and(affectOf($scored, AiCandidateActionType::Build))->toBe(0.0);
});

test('an angry novice presses the attack and cools the save', function (): void {
    config(['ai.cognition.affect.decision_weight' => 10]);
    seedAffect(AiAffectEmotion::Anger, 1.0);

    $scored = scoreAffect(AiSkillBand::Novice, [AiCandidateActionType::Raid, AiCandidateActionType::FleetSave, AiCandidateActionType::Build]);

    expect(affectOf($scored, AiCandidateActionType::Raid))->toBe(10.0)
        ->and(affectOf($scored, AiCandidateActionType::FleetSave))->toBe(-10.0)
        ->and(affectOf($scored, AiCandidateActionType::Build))->toBe(0.0);
});

test('a frightened novice saves and avoids the raid', function (): void {
    config(['ai.cognition.affect.decision_weight' => 10]);
    seedAffect(AiAffectEmotion::Fear, 1.0);

    $scored = scoreAffect(AiSkillBand::Novice, [AiCandidateActionType::Raid, AiCandidateActionType::FleetSave, AiCandidateActionType::Build]);

    expect(affectOf($scored, AiCandidateActionType::Raid))->toBe(-10.0)
        ->and(affectOf($scored, AiCandidateActionType::FleetSave))->toBe(10.0)
        ->and(affectOf($scored, AiCandidateActionType::Build))->toBe(0.0);
});

test('a veteran reacts to the same mood with a fraction of the novice shift', function (): void {
    config(['ai.cognition.affect.decision_weight' => 10]);
    seedAffect(AiAffectEmotion::Anger, 1.0);

    $scored = scoreAffect(AiSkillBand::Veteran, [AiCandidateActionType::Raid]);

    expect(affectOf($scored, AiCandidateActionType::Raid))->toBe(2.0);
});

test('the mood shift is bounded by the opt-in weight alone', function (): void {
    config(['ai.cognition.affect.decision_weight' => 7]);
    seedAffect(AiAffectEmotion::Anger, 5.0);

    $scored = scoreAffect(AiSkillBand::Novice, [AiCandidateActionType::Raid]);

    expect(affectOf($scored, AiCandidateActionType::Raid))->toBe(7.0);
});

function affectNow(): CarbonImmutable
{
    // Pinned to the transaction clock IsolatedAccountTestCase travels to, so decay reads a
    // stable instant and a fixture written "now" is never already stale.
    return CarbonImmutable::create(2024, 1, 1, 0, 0, 0);
}

function seedAffect(AiAffectEmotion $emotion, float $intensity): void
{
    app(UpdateAiAffectStateAction::class)->handle(1, $emotion, $intensity, affectNow());
}

/** @param list<AiCandidateActionType> $types @return array<int, ScoredCandidate> */
function scoreAffect(AiSkillBand $band, array $types): array
{
    $profile = app()->makeWith(AiProfile::class, ['attributes' => [
        'id' => 1,
        'player_id' => 1,
        'archetype' => AiArchetype::Fleeter,
        'skill_band' => $band,
        'random_seed' => 42,
    ]]);

    $candidates = array_map(static fn (AiCandidateActionType $type): CandidateAction => app()->makeWith(CandidateAction::class, [
        'type' => $type,
        'reason' => 'affect-fixture',
        'parameters' => [],
        'features' => ['resource_need' => 0.5, 'safety' => 0.2, 'target_confidence' => 0.0, 'travel_cost' => 0.0, 'recovery' => 0.0],
        'sourceTimestamps' => [],
    ]), $types);

    return app(UtilityScorer::class)->score($profile, app()->makeWith(CandidateGeneration::class, [
        'candidates' => $candidates,
        'rejections' => [],
    ]), 'affect');
}

/** @param array<int, ScoredCandidate> $scored */
function affectOf(array $scored, AiCandidateActionType $type): float
{
    foreach ($scored as $candidate) {
        if ($candidate->candidate->type === $type) {
            return $candidate->components['affect'];
        }
    }

    throw new RuntimeException('No scored candidate of type ' . $type->name);
}
