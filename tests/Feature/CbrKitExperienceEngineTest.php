<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\AI\Actions\RecordAiExperienceOutcomeAction;
use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Domain\Experience\ExperienceQuery;
use Modules\AI\Enums\AiBuildingExperienceFeature;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceFeatureVersion;
use Modules\AI\Enums\AiExperienceOutcome;
use Modules\AI\Enums\AiExperienceRulesetVersion;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\ExperienceEngineSelector;
use Modules\AI\Support\SystemAiClock;
use Modules\AI\Tests\Support\InteractsWithCognitionFixtures;
use Tests\IsolatedAccountTestCase;

require_once __DIR__.'/../Support/InteractsWithCognitionFixtures.php';

uses(IsolatedAccountTestCase::class, InteractsWithCognitionFixtures::class);

/**
 * The CBRKit driver is an implementation swap, not a new source of truth. Every failure
 * branch must leave the native ranking in place, because experience evidence feeds ordinary
 * decisions.
 *
 * The successful paths replay what the running sidecar really answered, captured into
 * `tests/Fixtures/cognition/cbrkit.json`. That matters here more than anywhere else: an
 * earlier version of this suite scored cases with feature names the retriever cannot even
 * represent, so its literals proved nothing about the driver it claimed to test. The cases
 * below therefore carry the module's real building-upgrade features, and the retriever's real
 * per-feature weights decide the scores.
 */
beforeEach(function (): void {
    // Swap semantics: the CBRKit engine replaces native, with native as the per-call fallback.
    config(['ai.cognition.mode' => 'external']);
    config(['ai.cognition.experience.driver' => 'cbrkit']);
    app()->bind(AiClock::class, SystemAiClock::class);

    // The module's own bindings are not active in this suite. Routing through the
    // selector keeps the real driver wiring under test instead of a test-local copy.
    app()->bind(ExperienceEngine::class, fn (): ExperienceEngine => app(ExperienceEngineSelector::class)->resolve());
});

/** The query `EconomyUpgrades` asks with, extended the way the conformance trial extends it. */
function cbrKitQueryFeatures(): array
{
    return [
        AiBuildingExperienceFeature::ObjectId->value => 2,
        AiBuildingExperienceFeature::TargetLevel->value => 6,
    ];
}

/** @return array<string, int> the three features a building-upgrade case carries */
function cbrKitCaseFeatures(int $objectId, int $targetLevel, int $planetId = 1): array
{
    return [
        AiBuildingExperienceFeature::PlanetId->value => $planetId,
        AiBuildingExperienceFeature::ObjectId->value => $objectId,
        AiBuildingExperienceFeature::TargetLevel->value => $targetLevel,
    ];
}

function seedCase(int $playerId, int $sourceId, array $features, AiExperienceOutcome $outcome = AiExperienceOutcome::Succeeded, string|null $featureVersion = null): int
{
    return app(RecordAiExperienceOutcomeAction::class)
        ->handle(
            $playerId,
            $sourceId,
            AiExperienceCaseFamily::BuildingUpgrade,
            $outcome,
            $featureVersion ?? AiExperienceFeatureVersion::BuildingUpgradeV1->value,
            AiExperienceRulesetVersion::HostBuildingCompletionV1->value,
            $features,
            0.5,
            0.25,
        )
        ->id;
}

function queryFor(int $playerId, array|null $features = null, int $limit = 5): ExperienceQuery
{
    return app()->makeWith(ExperienceQuery::class, [
        'playerId' => $playerId,
        'family' => AiExperienceCaseFamily::BuildingUpgrade,
        'featureVersion' => AiExperienceFeatureVersion::BuildingUpgradeV1->value,
        'rulesetVersion' => AiExperienceRulesetVersion::HostBuildingCompletionV1->value,
        'features' => $features ?? cbrKitQueryFeatures(),
        'limit' => $limit,
    ]);
}

test('the native engine remains the default when no driver is configured', function (): void {
    Http::fake();
    config(['ai.cognition.experience.driver' => 'native']);
    seedCase($this->currentUserId, 2001, cbrKitCaseFeatures(2, 6));

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId));

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->similarity)->toBe(1.0);

    Http::assertNothingSent();
});

test('an unrecognised driver is reported and falls back to native', function (): void {
    Log::spy();
    Http::fake();
    config(['ai.cognition.experience.driver' => 'not-a-driver']);
    seedCase($this->currentUserId, 2002, cbrKitCaseFeatures(2, 6));

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId));

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->similarity)->toBe(1.0);

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});

test('the driver scores the owner scoped casebase the module sends', function (): void {
    $exact = seedCase($this->currentUserId, 2003, cbrKitCaseFeatures(2, 6));
    $neighbour = seedCase($this->currentUserId, 2004, cbrKitCaseFeatures(3, 6));
    seedCase($this->currentUserId + 1, 2005, cbrKitCaseFeatures(2, 6));
    // A finalized case from an incompatible feature version is not evidence for this query.
    seedCase($this->currentUserId, 2006, cbrKitCaseFeatures(2, 6), AiExperienceOutcome::Succeeded, 'building-upgrade-v2');

    $this->fakeCbrKitDriver();

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId));

    // The retriever weights a mismatched object id as a categorical zero while the module's own
    // uniform mean scores the same pair at 0.833, so these figures are the driver's answer.
    expect($ranked)->toHaveCount(2)
        ->and($ranked[0]->caseId)->toBe($exact)
        ->and($ranked[0]->similarity)->toBe(1.0)
        ->and($ranked[0]->utility)->toBe(0.5)
        ->and($ranked[0]->uncertainty)->toBe(0.25)
        ->and($ranked[1]->caseId)->toBe($neighbour)
        ->and($ranked[1]->similarity)->toEqualWithDelta(0.3333333333333333, 1e-9);

    Http::assertSent(function (Request $request) use ($exact, $neighbour): bool {
        $data = $request->data();
        $casebase = $data['casebase'];

        return count($casebase) === 2
            && array_key_exists((string) $exact, $casebase)
            && array_key_exists((string) $neighbour, $casebase)
            && $data['queries']['current'] === cbrKitQueryFeatures();
    });
});

test('module ordering resolves driver ties by ascending case id', function (): void {
    $older = seedCase($this->currentUserId, 2007, cbrKitCaseFeatures(2, 6));
    $newer = seedCase($this->currentUserId, 2008, cbrKitCaseFeatures(2, 6));

    // Two identical cases tie in the driver's own measure, and that measure has no tie-break to
    // offer, so the module re-applies its own.
    $this->fakeCbrKitDriver();

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId));

    expect($ranked)->toHaveCount(2)
        ->and($ranked[0]->similarity)->toBe(1.0)
        ->and($ranked[1]->similarity)->toBe(1.0)
        ->and($ranked[0]->caseId)->toBe($older)
        ->and($ranked[1]->caseId)->toBe($newer);
});

test('the request honours the configured case bound', function (): void {
    config(['ai.cognition.experience.cbrkit.maximum_cases' => 1]);
    seedCase($this->currentUserId, 2009, cbrKitCaseFeatures(2, 6));
    seedCase($this->currentUserId, 2010, cbrKitCaseFeatures(3, 6));

    $this->fakeCbrKitDriver();

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId));

    expect($ranked)->toHaveCount(1);

    Http::assertSent(fn (Request $request): bool => count($request->data()['casebase']) === 1);
});

test('a cold start never contacts the driver', function (): void {
    Http::fake();

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId));

    expect($ranked)->toBe([]);
    Http::assertNothingSent();
});

test('a response that omits a sent case is treated as a contract deviation', function (): void {
    seedCase($this->currentUserId, 2011, cbrKitCaseFeatures(2, 6));
    seedCase($this->currentUserId, 2012, cbrKitCaseFeatures(3, 6));

    $this->fakeCbrKitDriver($this->driverProbe('cbrkit.omits_a_sent_case'));

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId));

    expect($ranked)->toHaveCount(2)
        ->and($ranked[0]->similarity)->toBe(1.0);
});

test('a non-successful driver response degrades to the native ranking', function (): void {
    seedCase($this->currentUserId, 2013, cbrKitCaseFeatures(2, 6));

    $this->fakeCbrKitDriver($this->driverProbe('cbrkit.server_error'));

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId));

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->similarity)->toBe(1.0);
});

test('a malformed driver payload degrades to the native ranking', function (): void {
    seedCase($this->currentUserId, 2014, cbrKitCaseFeatures(2, 6));

    $this->fakeCbrKitDriver($this->driverProbe('cbrkit.malformed_payload'));

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId));

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->similarity)->toBe(1.0);
});

test('a non-object driver payload degrades to the native ranking', function (): void {
    seedCase($this->currentUserId, 2018, cbrKitCaseFeatures(2, 6));

    $this->fakeCbrKitDriver($this->driverProbe('cbrkit.non_object_payload'));

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId));

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->similarity)->toBe(1.0);
});

test('an unreachable driver degrades to the native ranking', function (): void {
    seedCase($this->currentUserId, 2015, cbrKitCaseFeatures(2, 6));
    // A transport failure is not a response, so a driver fixture has nothing to replay here: the
    // request never arrives.
    Http::fake(fn () => throw new ConnectionException('connection refused'));

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId));

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->similarity)->toBe(1.0);
});

test('the circuit opens after the configured failures and stops contacting the driver', function (): void {
    config(['ai.cognition.circuit.failures' => 2]);
    seedCase($this->currentUserId, 2016, cbrKitCaseFeatures(2, 6));
    $query = queryFor($this->currentUserId);

    $calls = 0;
    Http::fake(function () use (&$calls): never {
        $calls++;
        throw new ConnectionException('connection refused');
    });

    app(ExperienceEngine::class)->rankSimilarExperiences($query);
    app(ExperienceEngine::class)->rankSimilarExperiences($query);
    expect($calls)->toBe(2);

    // The third call is answered natively because the circuit is open, so the driver never sees it.
    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences($query);

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->similarity)->toBe(1.0)
        ->and($calls)->toBe(2);
});

test('a successful call resets the failure count', function (): void {
    config(['ai.cognition.circuit.failures' => 2]);
    seedCase($this->currentUserId, 2017, cbrKitCaseFeatures(2, 6));
    $query = queryFor($this->currentUserId);

    // One fake, because the HTTP fake keeps its first matching stub: the second call is answered
    // by the recorded driver, which must clear the count the first call charged.
    $calls = 0;
    Http::fake(['*' => function (Request $request) use (&$calls): PromiseInterface|Response {
        $calls++;

        return $calls === 2
            ? $this->cbrKitFixtureResponse($request)
            : throw new ConnectionException('connection refused');
    }]);

    foreach (range(1, 4) as $ignored) {
        app(ExperienceEngine::class)->rankSimilarExperiences($query);
    }

    // failure, success, failure, failure: the count reached two, so the circuit is open and the
    // driver is skipped rather than contacted a fifth time.
    expect($calls)->toBe(4);

    app(ExperienceEngine::class)->rankSimilarExperiences($query);
    expect($calls)->toBe(4);
});
