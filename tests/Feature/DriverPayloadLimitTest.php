<?php

use Illuminate\Support\Facades\Http;
use Modules\AI\Actions\RecordAiExperienceOutcomeAction;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Domain\Cognition\ObservedStimulus;
use Modules\AI\Domain\Experience\ExperienceQuery;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiBuildingExperienceFeature;
use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceFeatureVersion;
use Modules\AI\Enums\AiExperienceOutcome;
use Modules\AI\Enums\AiExperienceRulesetVersion;
use Modules\AI\Infrastructure\Cognition\FatimaClient;
use Modules\AI\Infrastructure\Cognition\FatimaCognitionSession;
use Modules\AI\Support\AffectEngineSelector;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\DriverCircuitBreaker;
use Modules\AI\Support\DriverResponseLimit;
use Modules\AI\Support\ExperienceEngineSelector;
use Modules\AI\Support\FatimaScenarioTemplate;
use Modules\AI\Support\SystemAiClock;
use Modules\AI\Tests\Support\InteractsWithCognitionFixtures;
use Tests\IsolatedAccountTestCase;

require_once __DIR__.'/../Support/InteractsWithCognitionFixtures.php';

uses(IsolatedAccountTestCase::class, InteractsWithCognitionFixtures::class);

/**
 * Bounded input (gate A4). The module decides how much a driver may say back, because a
 * driver must not be able to enlarge the input surface the module agreed to accept. An
 * oversized body is refused exactly like a malformed one and the native answer stands.
 */
beforeEach(function (): void {
    config(['ai.cognition.mode' => 'external']);
    app()->bind(AiClock::class, SystemAiClock::class);
    app()->bind(AffectEngine::class, fn (): AffectEngine => app(AffectEngineSelector::class)->resolve());
    app()->bind(ExperienceEngine::class, fn (): ExperienceEngine => app(ExperienceEngineSelector::class)->resolve());
    app()->singleton(FatimaScenarioTemplate::class);
    app()->singleton(FatimaCognitionSession::class);
    app()->when(FatimaClient::class)
        ->needs(DriverCircuitBreaker::class)
        ->give(fn (): DriverCircuitBreaker => app()->makeWith(DriverCircuitBreaker::class, [
            'driver' => AiCognitionDriver::Fatima->value,
        ]));
});

function boundedStimulus(AiArchetype $archetype, float $aid): ObservedStimulus
{
    return app()->makeWith(ObservedStimulus::class, [
        'archetype' => $archetype,
        'harm' => 0.0,
        'aid' => $aid,
        'threat' => 0.0,
        'relationshipTrust' => 0.0,
    ]);
}

function boundedExperienceCase(int $playerId, int $sourceId, int $objectId): int
{
    return app(RecordAiExperienceOutcomeAction::class)->handle(
        $playerId,
        $sourceId,
        AiExperienceCaseFamily::BuildingUpgrade,
        AiExperienceOutcome::Succeeded,
        AiExperienceFeatureVersion::BuildingUpgradeV1->value,
        AiExperienceRulesetVersion::HostBuildingCompletionV1->value,
        [
            AiBuildingExperienceFeature::PlanetId->value => 1,
            AiBuildingExperienceFeature::ObjectId->value => $objectId,
            AiBuildingExperienceFeature::TargetLevel->value => 5,
        ],
        1.0,
        0.0,
    )->id;
}

function boundedExperienceQuery(int $playerId, int $objectId): ExperienceQuery
{
    return app()->makeWith(ExperienceQuery::class, [
        'playerId' => $playerId,
        'family' => AiExperienceCaseFamily::BuildingUpgrade,
        'featureVersion' => AiExperienceFeatureVersion::BuildingUpgradeV1->value,
        'rulesetVersion' => AiExperienceRulesetVersion::HostBuildingCompletionV1->value,
        'features' => [AiBuildingExperienceFeature::ObjectId->value => $objectId],
        'limit' => 5,
    ]);
}

test('the response bound is module policy with a usable floor', function (): void {
    expect(app(DriverResponseLimit::class)->maximumBytes())->toBe(262_144);

    config(['ai.cognition.payload.maximum_response_bytes' => 0]);

    // A zero or negative setting cannot disable the bound; it collapses to the minimum.
    expect(app(DriverResponseLimit::class)->maximumBytes())->toBe(1)
        ->and(app(DriverResponseLimit::class)->withinLimit(''))->toBeTrue()
        ->and(app(DriverResponseLimit::class)->withinLimit('ab'))->toBeFalse();
});

test('an oversized experience-driven response is refused and the native ranking stands', function (): void {
    config([
        'ai.cognition.experience.driver' => 'cbrkit',
        'ai.cognition.payload.maximum_response_bytes' => 64,
    ]);
    boundedExperienceCase($this->currentUserId, 4001, 2);
    $this->fakeCbrKitDriver($this->driverProbe('cbrkit.oversized'));

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(boundedExperienceQuery($this->currentUserId, 2));

    // The module's own record answers instead of a body it never agreed to read.
    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->similarity)->toBe(1.0);
});

test('a response within the bound is still interpreted', function (): void {
    config([
        'ai.cognition.experience.driver' => 'cbrkit',
        'ai.cognition.payload.maximum_response_bytes' => 4_096,
    ]);
    boundedExperienceCase($this->currentUserId, 4002, 2);

    $this->fakeCbrKitDriver();

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(boundedExperienceQuery($this->currentUserId, 2));

    // The retriever scores a matching object at 1.0, and the driver was the one asked.
    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->similarity)->toBe(1.0);

    Http::assertSentCount(1);
});

test('an oversized cognition-driven response is refused and the native appraisal stands', function (): void {
    config([
        'ai.cognition.driver' => 'fatima',
        'ai.cognition.payload.maximum_response_bytes' => 64,
    ]);
    $this->fakeFatimaDriver(['*' => $this->driverProbe('fatima.emotions.oversized')]);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(boundedStimulus(AiArchetype::Miner, 0.5));

    expect($appraisal->emotion)->toBe(AiAffectEmotion::Gratitude)
        ->and($appraisal->intensity)->toBe(0.5);
});
