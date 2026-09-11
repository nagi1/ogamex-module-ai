<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\AI\Actions\RecordAiExperienceOutcomeAction;
use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Domain\Experience\ExperienceQuery;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceOutcome;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\ExperienceEngineSelector;
use Modules\AI\Support\SystemAiClock;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The CBRKit driver is an implementation swap, not a new source of truth. Every
 * failure branch must leave the native ranking in place, because experience evidence
 * feeds ordinary decisions.
 */
beforeEach(function (): void {
    config(['ai.cognition.experience.driver' => 'cbrkit']);
    app()->bind(AiClock::class, SystemAiClock::class);

    // The module's own bindings are not active in this suite. Routing through the
    // selector keeps the real driver wiring under test instead of a test-local copy.
    app()->bind(ExperienceEngine::class, fn (): ExperienceEngine => app(ExperienceEngineSelector::class)->resolve());
});

/** @param array<string, float> $similarities */
function fakeCbrKit(array $similarities)
{
    Http::fake([
        '*' => Http::response([
            'steps' => [['queries' => ['current' => ['similarities' => $similarities]]]],
        ]),
    ]);
}

function seedCase(int $playerId, int $sourceId, array $features, AiExperienceOutcome $outcome = AiExperienceOutcome::Succeeded, string $featureVersion = 'v1'): int
{
    return app(RecordAiExperienceOutcomeAction::class)
        ->handle($playerId, $sourceId, AiExperienceCaseFamily::SocialAssistance, $outcome, $featureVersion, 'v1', $features, 0.5, 0.25)
        ->id;
}

function queryFor(int $playerId, array $features, int $limit = 5): ExperienceQuery
{
    return app()->makeWith(ExperienceQuery::class, [
        'playerId' => $playerId,
        'family' => AiExperienceCaseFamily::SocialAssistance,
        'featureVersion' => 'v1',
        'rulesetVersion' => 'v1',
        'features' => $features,
        'limit' => $limit,
    ]);
}

test('the native engine remains the default when no driver is configured', function (): void {
    Http::fake();
    config(['ai.cognition.experience.driver' => 'native']);
    seedCase($this->currentUserId, 2001, ['amount' => 100]);

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId, ['amount' => 100]));

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->similarity)->toBe(1.0);

    Http::assertNothingSent();
});

test('an unrecognised driver is reported and falls back to native', function (): void {
    Log::spy();
    Http::fake();
    config(['ai.cognition.experience.driver' => 'not-a-driver']);
    seedCase($this->currentUserId, 2002, ['amount' => 100]);

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId, ['amount' => 100]));

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->similarity)->toBe(1.0);

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});

test('the driver scores the owner scoped casebase the module sends', function (): void {
    $first = seedCase($this->currentUserId, 2003, ['amount' => 100, 'resource' => 'crystal']);
    $second = seedCase($this->currentUserId, 2004, ['amount' => 50, 'resource' => 'metal']);
    seedCase($this->currentUserId + 1, 2005, ['amount' => 100]);
    // A finalized case from an incompatible feature version is not evidence for this query.
    seedCase($this->currentUserId, 2006, ['amount' => 100], AiExperienceOutcome::Succeeded, 'v2');

    fakeCbrKit([(string) $first => 0.9, (string) $second => 0.4]);

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId, ['amount' => 100, 'resource' => 'crystal']));

    expect($ranked)->toHaveCount(2)
        ->and($ranked[0]->caseId)->toBe($first)
        ->and($ranked[0]->similarity)->toBe(0.9)
        ->and($ranked[0]->utility)->toBe(0.5)
        ->and($ranked[0]->uncertainty)->toBe(0.25)
        ->and($ranked[1]->caseId)->toBe($second);

    Http::assertSent(function ($request) use ($first, $second): bool {
        $data = $request->data();
        $casebase = $data['casebase'];

        return count($casebase) === 2
            && array_key_exists((string) $first, $casebase)
            && array_key_exists((string) $second, $casebase)
            && $data['queries']['current'] === ['amount' => 100, 'resource' => 'crystal'];
    });
});

test('module ordering resolves driver ties by ascending case id', function (): void {
    $older = seedCase($this->currentUserId, 2007, ['amount' => 100]);
    $newer = seedCase($this->currentUserId, 2008, ['amount' => 100]);

    // The driver reports the tie in the opposite order on purpose.
    fakeCbrKit([(string) $older => 0.5, (string) $newer => 0.5]);

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId, ['amount' => 100]));

    expect($ranked)->toHaveCount(2)
        ->and($ranked[0]->caseId)->toBe($older)
        ->and($ranked[1]->caseId)->toBe($newer);
});

test('the request honours the configured case bound', function (): void {
    config(['ai.cognition.experience.cbrkit.maximum_cases' => 1]);
    $first = seedCase($this->currentUserId, 2009, ['amount' => 100]);
    $second = seedCase($this->currentUserId, 2010, ['amount' => 100]);

    fakeCbrKit([(string) $first => 1.0, (string) $second => 1.0]);

    app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId, ['amount' => 100]));

    Http::assertSent(fn ($request): bool => count($request->data()['casebase']) === 1);
});

test('a cold start never contacts the driver', function (): void {
    fakeCbrKit([]);

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId, ['amount' => 100]));

    expect($ranked)->toBe([]);
    Http::assertNothingSent();
});

test('a response that omits a sent case is treated as a contract deviation', function (): void {
    $only = seedCase($this->currentUserId, 2011, ['amount' => 100]);
    seedCase($this->currentUserId, 2012, ['amount' => 50]);

    fakeCbrKit([(string) $only => 0.9]);

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId, ['amount' => 100]));

    expect($ranked)->toHaveCount(2)
        ->and($ranked[0]->similarity)->toBe(1.0);
});

test('a non-successful driver response degrades to the native ranking', function (): void {
    seedCase($this->currentUserId, 2013, ['amount' => 100]);
    Http::fake(['*' => Http::response('boom', 500)]);

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId, ['amount' => 100]));

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->similarity)->toBe(1.0);
});

test('a malformed driver payload degrades to the native ranking', function (): void {
    seedCase($this->currentUserId, 2014, ['amount' => 100]);
    Http::fake(['*' => Http::response(['unexpected' => true])]);

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId, ['amount' => 100]));

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->similarity)->toBe(1.0);
});

test('a non-object driver payload degrades to the native ranking', function (): void {
    seedCase($this->currentUserId, 2018, ['amount' => 100]);
    Http::fake(['*' => Http::response('ok', 200, ['Content-Type' => 'application/json'])]);

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId, ['amount' => 100]));

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->similarity)->toBe(1.0);
});

test('an unreachable driver degrades to the native ranking', function (): void {
    seedCase($this->currentUserId, 2015, ['amount' => 100]);
    Http::fake(fn () => throw new ConnectionException('connection refused'));

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId, ['amount' => 100]));

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->similarity)->toBe(1.0);
});

test('the circuit opens after the configured failures and stops contacting the driver', function (): void {
    config(['ai.cognition.circuit.failures' => 2]);
    seedCase($this->currentUserId, 2016, ['amount' => 100]);

    $calls = 0;
    Http::fake(function () use (&$calls): never {
        $calls++;
        throw new ConnectionException('connection refused');
    });

    app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId, ['amount' => 100]));
    app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId, ['amount' => 100]));
    expect($calls)->toBe(2);

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId, ['amount' => 100]));

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->similarity)->toBe(1.0)
        ->and($calls)->toBe(2);
});

test('a successful call resets the failure count', function (): void {
    config(['ai.cognition.circuit.failures' => 2]);
    $case = seedCase($this->currentUserId, 2017, ['amount' => 100]);

    $calls = 0;
    Http::fake(function () use (&$calls, $case) {
        $calls++;

        if ($calls === 2) {
            return Http::response([
                'steps' => [['queries' => ['current' => ['similarities' => [(string) $case => 0.75]]]]],
            ]);
        }

        throw new ConnectionException('connection refused');
    });

    foreach (range(1, 3) as $ignored) {
        app(ExperienceEngine::class)->rankSimilarExperiences(queryFor($this->currentUserId, ['amount' => 100]));
    }

    // failure, success (which resets the count), failure: the triplet must not trip
    // the breaker, so the driver was contacted all three times.
    expect($calls)->toBe(3);
});
