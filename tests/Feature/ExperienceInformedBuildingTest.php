<?php

use Illuminate\Support\Facades\Http;
use Modules\AI\Actions\RecordAiExperienceOutcomeAction;
use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Domain\Decision\BuildFirstBuilding;
use Modules\AI\Domain\Decision\BuildingScoringPolicy;
use Modules\AI\Domain\Decision\ExperienceInformedBuildingScoringPolicy;
use Modules\AI\Domain\Decision\SeededBuildingScoringPolicy;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiBuildingExperienceFeature;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceFeatureVersion;
use Modules\AI\Enums\AiExperienceOutcome;
use Modules\AI\Enums\AiExperienceRulesetVersion;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\FirstBuildingTarget;
use Modules\AI\Models\AiExperienceCase;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\AiProfileSettings;
use Modules\AI\Support\ExperienceEngineSelector;
use Modules\AI\Support\SystemAiClock;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * Outcome evidence must reach a real decision, otherwise the module records what it
 * learned and never uses it, and the ablation's experience configuration cannot measure
 * anything.
 *
 * The module keeps the weighting; a driver only ranks. The seeded policy answers what
 * this persona would like to build, and the decorator adds what actually happened when
 * it built it. A cold start therefore has to be indistinguishable from the old behaviour.
 *
 * The module's own bindings are not active in this suite, so they are wired here exactly
 * as AIServiceProvider::register() wires them.
 */
beforeEach(function (): void {
    // The circuit breaker behind an optional driver reads the module clock.
    app()->bind(AiClock::class, SystemAiClock::class);
    app()->bind(ExperienceEngine::class, fn (): ExperienceEngine => app(ExperienceEngineSelector::class)->resolve());
    app()->bind(BuildingScoringPolicy::class, fn (): BuildingScoringPolicy => app()->makeWith(
        ExperienceInformedBuildingScoringPolicy::class,
        ['seeded' => app(SeededBuildingScoringPolicy::class)],
    ));
    config(['ai.cognition.experience.driver' => 'native']);
});

/** @param array<string, int> $weights */
function informedProfile(int $playerId, array $weights = []): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'enabled' => true,
        'settings' => [AiProfileSettings::BUILDING_WEIGHTS => $weights],
    ]);
}

function seedInformedOutcome(int $playerId, int $sourceId, int $objectId, float $utility, float $uncertainty, AiExperienceOutcome $outcome = AiExperienceOutcome::Succeeded): int
{
    return app(RecordAiExperienceOutcomeAction::class)->handle(
        $playerId,
        $sourceId,
        AiExperienceCaseFamily::BuildingUpgrade,
        $outcome,
        AiExperienceFeatureVersion::BuildingUpgradeV1->value,
        AiExperienceRulesetVersion::HostBuildingCompletionV1->value,
        [
            AiBuildingExperienceFeature::PlanetId->value => 1,
            AiBuildingExperienceFeature::ObjectId->value => $objectId,
            AiBuildingExperienceFeature::TargetLevel->value => 5,
        ],
        $utility,
        $uncertainty,
    )->id;
}

function informedScore(AiProfile $profile, FirstBuildingTarget $target): int
{
    return app(BuildingScoringPolicy::class)->score($profile, $target);
}

function seededScore(AiProfile $profile, FirstBuildingTarget $target): int
{
    return app(SeededBuildingScoringPolicy::class)->score($profile, $target);
}

test('a cold start is indistinguishable from the seeded preference', function (): void {
    $profile = informedProfile($this->currentUserId);

    foreach (FirstBuildingTarget::cases() as $target) {
        expect(informedScore($profile, $target))->toBe(seededScore($profile, $target));
    }
});

test('a finalized matching success raises that object by the configured weight', function (): void {
    $profile = informedProfile($this->currentUserId);
    $baseline = informedScore($profile, FirstBuildingTarget::CrystalMine);

    seedInformedOutcome($this->currentUserId, 7001, FirstBuildingTarget::CrystalMine->value, 1.0, 0.0);

    expect(informedScore($profile, FirstBuildingTarget::CrystalMine))->toBe($baseline + 20);
});

test('an outcome for a different object is not evidence about this one', function (): void {
    $profile = informedProfile($this->currentUserId);
    seedInformedOutcome($this->currentUserId, 7002, FirstBuildingTarget::MetalMine->value, 1.0, 0.0);

    // Object identifiers are compared numerically by the engine, so a neighbouring
    // building must not read as a weaker precedent for this one.
    expect(informedScore($profile, FirstBuildingTarget::SolarPlant))
        ->toBe(seededScore($profile, FirstBuildingTarget::SolarPlant))
        ->and(informedScore($profile, FirstBuildingTarget::DeuteriumSynthesizer))
        ->toBe(seededScore($profile, FirstBuildingTarget::DeuteriumSynthesizer));
});

test('a finalized matching failure lowers that object', function (): void {
    $profile = informedProfile($this->currentUserId);
    $baseline = informedScore($profile, FirstBuildingTarget::MetalMine);

    seedInformedOutcome($this->currentUserId, 7003, FirstBuildingTarget::MetalMine->value, -1.0, 0.0, AiExperienceOutcome::Failed);

    expect(informedScore($profile, FirstBuildingTarget::MetalMine))->toBe($baseline - 20);
});

test('an uncertain outcome counts for proportionally less', function (): void {
    $profile = informedProfile($this->currentUserId);
    $baseline = informedScore($profile, FirstBuildingTarget::DeuteriumSynthesizer);

    seedInformedOutcome($this->currentUserId, 7004, FirstBuildingTarget::DeuteriumSynthesizer->value, 1.0, 0.5);

    expect(informedScore($profile, FirstBuildingTarget::DeuteriumSynthesizer))->toBe($baseline + 10);
});

test('a zero decision weight disables the enrichment without deleting the evidence', function (): void {
    config(['ai.cognition.experience.decision_weight' => 0]);
    $profile = informedProfile($this->currentUserId);
    seedInformedOutcome($this->currentUserId, 7005, FirstBuildingTarget::CrystalMine->value, 1.0, 0.0);

    expect(informedScore($profile, FirstBuildingTarget::CrystalMine))
        ->toBe(seededScore($profile, FirstBuildingTarget::CrystalMine))
        ->and(AiExperienceCase::query()->where('player_id', $this->currentUserId)->count())->toBe(1);
});

test('the enrichment ranks through the configured driver rather than the query alone', function (): void {
    config(['ai.cognition.experience.driver' => 'cbrkit']);
    $profile = informedProfile($this->currentUserId);
    $caseId = seedInformedOutcome($this->currentUserId, 7006, FirstBuildingTarget::SolarPlant->value, 1.0, 0.0);

    // The driver scores the same case below a full match, so it stops being evidence.
    Http::fake(['*' => Http::response([
        'steps' => [['queries' => ['current' => ['similarities' => [(string) $caseId => 0.5]]]]],
    ])]);

    expect(informedScore($profile, FirstBuildingTarget::SolarPlant))
        ->toBe(seededScore($profile, FirstBuildingTarget::SolarPlant));

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/retrieve'));
});

test('a remembered outcome can change which building the AI chooses', function (): void {
    $profile = informedProfile($this->currentUserId, [FirstBuildingTarget::MetalMine->name => 10]);

    expect(app(BuildFirstBuilding::class)->choose($profile)['building_id'])
        ->toBe(FirstBuildingTarget::MetalMine->value);

    // A settled preference for the crystal mine outweighs the small seeded preference.
    seedInformedOutcome($this->currentUserId, 7007, FirstBuildingTarget::CrystalMine->value, 1.0, 0.0);

    expect(app(BuildFirstBuilding::class)->choose($profile)['building_id'])
        ->toBe(FirstBuildingTarget::CrystalMine->value);
});
