<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;

require_once __DIR__ . '/../Support/AiQueueModuleTestCase.php';

uses(AiQueueModuleTestCase::class);

/**
 * The opt-in measurement run is module code, so it is covered like any other: every refusal,
 * both driver paths and the failure probes.
 *
 * With the sidecars faked the run is instant, which is what keeps it inside the fast suite
 * while the real measurement stays an operator command. The module is booted here rather than
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

/** Both sidecars answer in the shape the real ones do, including the failure probe. */
function fakeCognitionSidecars(): void
{
    Http::fake([
        '*/scenarios' => Http::response('"Scenario created"'),
        '*/beliefs' => Http::response('"Belief updated."'),
        '*/perceptions' => Http::response('"perceived"'),
        '*/emotions' => Http::response([
            'Name' => 'Miner',
            'Mood' => 0.0,
            'Emotions' => [[
                'Type' => 'Anger',
                'Intensity' => 5.0,
                'Target' => 'Other',
                'CauseEventId' => 1,
                'CauseEventName' => 'Event(Action-End, Other, Harm, Miner)',
            ]],
        ]),
        '*/retrieve' => function ($request) {
            $casebase = $request->data()['casebase'];

            // The real driver refuses a casebase whose values are not objects, which is the
            // failure mode the probe records.
            if (!is_array($casebase)) {
                return Http::response('not an object', 500);
            }

            $similarities = [];

            foreach (array_keys($casebase) as $id) {
                $similarities[(string) $id] = 1.0;
            }

            return Http::response([
                'steps' => [['queries' => ['current' => ['similarities' => $similarities]]]],
            ]);
        },
    ]);
}

/** Only the retrieval endpoint answers, for the runs that measure the experience driver alone. */
function fakeRetrievalSidecar(): void
{
    Http::fake([
        '*/retrieve' => function ($request) {
            $casebase = $request->data()['casebase'];

            if (!is_array($casebase)) {
                return Http::response('not an object', 500);
            }

            $similarities = [];

            foreach (array_keys($casebase) as $id) {
                $similarities[(string) $id] = 1.0;
            }

            return Http::response([
                'steps' => [['queries' => ['current' => ['similarities' => $similarities]]]],
            ]);
        },
    ]);
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
    fakeCognitionSidecars();

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
    fakeCognitionSidecars();

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
    fakeRetrievalSidecar();

    $this->artisan('ai:cognition-conformance --confirm --only=cbrkit')->assertExitCode(0);
    expect(cognitionConformanceEvidence()['report']['iterations'])->toBe(20);

    $this->artisan('ai:cognition-conformance --confirm --only=cbrkit --iterations=0')->assertExitCode(0);
    expect(cognitionConformanceEvidence()['report']['iterations'])->toBe(1);
});

test('a ranking that disagrees with the module fails the run', function (): void {
    config(['ai.cognition.experience.driver' => 'cbrkit', 'ai.cognition.driver' => 'native']);

    // Ascending scores invert the module's own ordering for a tied casebase.
    Http::fake([
        '*/retrieve' => function ($request) {
            $casebase = $request->data()['casebase'];

            if (!is_array($casebase)) {
                return Http::response('not an object', 500);
            }

            $similarities = [];

            foreach (array_keys($casebase) as $position => $id) {
                $similarities[(string) $id] = (float) $position;
            }

            return Http::response([
                'steps' => [['queries' => ['current' => ['similarities' => $similarities]]]],
            ]);
        },
    ]);

    $this->artisan('ai:cognition-conformance --confirm --only=cbrkit --iterations=1')->assertExitCode(1);

    $measurement = cognitionConformanceEvidence()['report']['measurements'][0];

    expect($measurement['correct'])->toBeFalse()
        ->and($measurement['ranking']['driver'])->not->toBe($measurement['ranking']['native']);
});

test('a cognition driver that cannot answer fails the run', function (): void {
    config(['ai.cognition.driver' => 'fatima']);

    Http::fake([
        '*/scenarios' => Http::response('"created"'),
        '*/beliefs' => Http::response('"ok"'),
        '*/perceptions' => Http::response('"ok"'),
        // The driver reports failures as a plain JSON string while still answering 200.
        '*/emotions' => Http::response('"There are already stored property values that will collide"'),
    ]);

    $this->artisan('ai:cognition-conformance --confirm --only=fatima --iterations=1')->assertExitCode(1);

    $measurement = cognitionConformanceEvidence()['report']['measurements'][0];

    expect($measurement['correct'])->toBeFalse()
        // The module still answers, because a failed driver degrades to its native engine.
        // The proof that the driver contributed nothing is that the two figures are equal.
        ->and($measurement['observed']['emotion'])->toBe($measurement['native']['emotion'])
        ->and($measurement['observed']['intensity'])->toBe($measurement['native']['intensity'])
        ->and($measurement['observed_failure_modes'][0]['refused'])->toBeTrue();
});
