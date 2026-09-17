<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;
use Modules\AI\Tests\Support\InteractsWithCognitionFixtures;

require_once __DIR__ . '/../Support/AiQueueModuleTestCase.php';
require_once __DIR__ . '/../Support/InteractsWithCognitionFixtures.php';

uses(AiQueueModuleTestCase::class, InteractsWithCognitionFixtures::class);

/**
 * The opt-in measurement run is module code, so it is covered like any other: every refusal,
 * both driver paths and the failure probes.
 *
 * The sidecars are replayed from the bodies they really answered
 * (`tests/Fixtures/cognition/`), which keeps the run instant and inside the fast suite while the
 * real measurement stays an operator command. The module is booted here rather than
 * wired by hand, so this also proves the command is registered and its bindings resolve in a
 * real installation instead of only under a test-local copy of them.
 */
beforeEach(function (): void {
    Storage::fake('local');
});

/** @return array{path: string, report: array<string, mixed>} */
function cognitionConformanceEvidence(): array
{
    $files = Storage::disk('local')->allFiles('ai-cognition-conformance');
    $path = $files[0] ?? '';

    return [
        'path' => $path,
        'report' => json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR),
    ];
}

test('the conformance run refuses to contact a sidecar without explicit confirmation', function (): void {
    Http::fake();

    $this->artisan('ai:cognition-conformance')->assertExitCode(1);

    Http::assertNothingSent();
    expect(Storage::disk('local')->allFiles('ai-cognition-conformance'))->toBe([]);
});

test('an unknown driver name is rejected before anything is measured', function (): void {
    Http::fake();

    $this->artisan('ai:cognition-conformance --confirm --only=mem0')->assertExitCode(1);

    Http::assertNothingSent();
    expect(Storage::disk('local')->allFiles('ai-cognition-conformance'))->toBe([]);
});

test('a run with neither driver selected explains what to configure', function (): void {
    Http::fake();
    config(['ai.cognition.experience.driver' => 'native', 'ai.cognition.driver' => 'native']);

    $this->artisan('ai:cognition-conformance --confirm')
        ->expectsOutputToContain('Neither driver is selected')
        ->assertExitCode(1);

    Http::assertNothingSent();
    expect(Storage::disk('local')->allFiles('ai-cognition-conformance'))->toBe([]);
});

test('a measured run records latency, bytes and failure modes for both drivers', function (): void {
    config(['ai.cognition.experience.driver' => 'cbrkit', 'ai.cognition.driver' => 'fatima']);
    $this->fakeFatimaDriver();
    $this->fakeCbrKitDriver();

    $this->artisan('ai:cognition-conformance --confirm --iterations=2')
        ->expectsOutputToContain('Measured evidence written to')
        ->assertExitCode(0);

    $evidence = cognitionConformanceEvidence();

    expect($evidence['path'])->toEndWith('.json')
        ->and($evidence['report']['iterations'])->toBe(2)
        ->and($evidence['report']['payload_limit_bytes'])->toBe(262_144)
        ->and($evidence['report']['measurements'])->toHaveCount(2);

    $experience = $evidence['report']['measurements'][0];

    expect($experience['driver'])->toBe('cbrkit')
        ->and($experience['measured'])->toBeTrue()
        ->and($experience['http_calls'])->toBeGreaterThanOrEqual(2)
        ->and($experience['request_bytes']['total'])->toBeGreaterThan(0)
        ->and($experience['response_bytes']['total'])->toBeGreaterThan(0)
        ->and($experience['latency_ms']['samples'])->toBe(2)
        ->and($experience['correct'])->toBeTrue()
        ->and($experience['ranking']['driver'])->toBe($experience['ranking']['native'])
        // The weighted measure ties the two different-object cases, which is what tells the
        // driver's own measure from the numeric port it replaced.
        ->and($experience['differentiation']['probe'])->toBe('per-feature weighted measure')
        ->and($experience['differentiation']['correct'])->toBeTrue()
        ->and($experience['differentiation']['similarities']['object_match'])->toEqual(1.0)
        ->and($experience['differentiation']['similarities']['object_9'])->toBe($experience['differentiation']['similarities']['object_3'])
        ->and($experience['differentiation']['similarities']['object_9'])->toBeLessThan(1.0)
        // The probe proves a refused casebase is recorded, not assumed.
        ->and($experience['observed_failure_modes'][0]['status'])->toBe(500)
        ->and($experience['observed_failure_modes'][0]['refused'])->toBeTrue();

    $cognition = $evidence['report']['measurements'][1];

    expect($cognition['driver'])->toBe('fatima')
        ->and($cognition['measured'])->toBeTrue()
        ->and($cognition['http_calls'])->toBeGreaterThanOrEqual(2)
        ->and($cognition['correct'])->toBeTrue()
        ->and($cognition['observed']['emotion'])->toBe(AiAffectEmotion::Anger->name);
});

test('a hybrid run measures the driver contribution against the native floor', function (): void {
    config(['ai.cognition.driver' => 'fatima']);
    $this->fakeFatimaDriver();

    $this->artisan('ai:cognition-conformance --confirm --only=fatima --iterations=1 --mode=hybrid')
        ->expectsOutputToContain('Measured evidence written to')
        ->assertExitCode(0);

    $evidence = cognitionConformanceEvidence();

    expect($evidence['report']['mode'])->toBe('hybrid');

    $measurement = $evidence['report']['measurements'][0];

    expect($measurement['correct'])->toBeTrue()
        ->and($measurement['observed']['emotion'])->toBe($measurement['native']['emotion'])
        ->and($measurement['observed']['driver_emotion'])->toBe(AiAffectEmotion::Anger->name)
        ->and($measurement['observed']['mood'])->toEqual(0.0);
});

test('the iteration count defaults to twenty and is floored at one', function (): void {
    config(['ai.cognition.experience.driver' => 'cbrkit', 'ai.cognition.driver' => 'native']);
    $this->fakeCbrKitDriver();

    $this->artisan('ai:cognition-conformance --confirm --only=cbrkit')->assertExitCode(0);
    expect(cognitionConformanceEvidence()['report']['iterations'])->toBe(20);

    $this->artisan('ai:cognition-conformance --confirm --only=cbrkit --iterations=0')->assertExitCode(0);
    expect(cognitionConformanceEvidence()['report']['iterations'])->toBe(1);
});

test('a ranking that disagrees with the module fails the run', function (): void {
    config(['ai.cognition.experience.driver' => 'cbrkit', 'ai.cognition.driver' => 'native']);

    // The conformance casebase ties in the retriever's own measure, so a ranking that
    // disagrees with the module's ordering can only be authored.
    $this->fakeCbrKitDisagreeingDriver();

    $this->artisan('ai:cognition-conformance --confirm --only=cbrkit --iterations=1')->assertExitCode(1);

    $measurement = cognitionConformanceEvidence()['report']['measurements'][0];

    expect($measurement['correct'])->toBeFalse()
        ->and($measurement['ranking']['driver'])->not->toBe($measurement['ranking']['native']);
});

test('a cognition driver that cannot answer fails the run', function (): void {
    config(['ai.cognition.driver' => 'fatima']);

    // The driver reports failures as a plain JSON string while still answering 200.
    $this->fakeFatimaDriver(['*' => $this->driverProbe('fatima.emotions.plain_failure')]);

    $this->artisan('ai:cognition-conformance --confirm --only=fatima --iterations=1')->assertExitCode(1);

    $measurement = cognitionConformanceEvidence()['report']['measurements'][0];

    expect($measurement['correct'])->toBeFalse()
        // The module still answers, because a failed driver degrades to its native engine.
        // The proof that the driver contributed nothing is that the two figures are equal.
        ->and($measurement['observed']['emotion'])->toBe($measurement['native']['emotion'])
        ->and($measurement['observed']['intensity'])->toBe($measurement['native']['intensity'])
        ->and($measurement['observed_failure_modes'][0]['refused'])->toBeTrue();
});
