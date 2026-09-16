<?php

namespace Modules\AI\Console\Commands;

use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Actions\RecordAiExperienceOutcomeAction;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Domain\Cognition\NativeAffectEngine;
use Modules\AI\Domain\Cognition\ObservedStimulus;
use Modules\AI\Domain\Experience\ExperienceQuery;
use Modules\AI\Domain\Experience\NativeExperienceEngine;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiBuildingExperienceFeature;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceFeatureVersion;
use Modules\AI\Enums\AiExperienceOutcome;
use Modules\AI\Enums\AiExperienceRulesetVersion;
use Modules\AI\Support\AiClock;

/**
 * The opt-in real-driver measurement the acceptance gates require: p50/p95 latency,
 * request and response bytes, and observed failure modes for the actual adapters, never
 * for a claim about them (gate A7).
 *
 * It is the only module code that may contact a real cognition sidecar, it refuses to run
 * without --confirm, and it never runs in CI. Its fixture casebase is written inside a
 * transaction that is rolled back, so an operator's own records are left untouched.
 *
 * CPU and memory are deliberately not reported here: the application container has no
 * access to the sidecar's cgroup, so those figures are captured on the host with
 * `docker stats --no-stream` and recorded next to this artifact by hand. Reporting a
 * substitute would be a measured claim this process cannot make.
 */
#[Description('Measure the real cognition drivers: latency percentiles, payload bytes and observed failure modes.')]
#[Signature('ai:cognition-conformance {--only= : Measure only one driver (cbrkit or fatima).} {--iterations=20 : Measured calls per driver.} {--mode= : The cognition mode to measure under (native, external or hybrid).} {--confirm : Confirm that this run contacts the real cognition sidecars.}')]
class RunCognitionConformance extends Command
{
    private const ARTIFACT_DIRECTORY = 'ai-cognition-conformance';

    /** @var list<string> */
    private const SUPPORTED_DRIVERS = ['cbrkit', 'fatima'];

    /**
     * A fixture owner that no real account uses, so a rolled-back measurement can never
     * collide with a live AI's casebase.
     */
    private const FIXTURE_PLAYER_ID = 999_999_991;

    public function handle(): int
    {
        if (!$this->option('confirm')) {
            $this->error('This run contacts the real cognition sidecars. Re-run with --confirm.');

            return self::FAILURE;
        }

        $requested = $this->requestedDrivers((string) ($this->option('only') ?? ''));

        if ($requested === []) {
            $this->error('Unknown driver. Use --only=cbrkit or --only=fatima.');

            return self::FAILURE;
        }

        $mode = trim((string) ($this->option('mode') ?? ''));

        if ($mode !== '') {
            config(['ai.cognition.mode' => $mode]);
        }

        $iterations = max(1, (int) ($this->option('iterations') ?? 20));
        $measurements = [];

        foreach ($requested as $driver) {
            $measurements[] = $driver === 'cbrkit'
                ? $this->measureCbrKit($iterations)
                : $this->measureFatima($iterations);
        }

        $measured = array_values(array_filter($measurements, static fn (array $entry): bool => $entry['measured']));

        if ($measured === []) {
            $this->error('Neither driver is selected. Set AI_EXPERIENCE_DRIVER=cbrkit or AI_COGNITION_DRIVER=fatima, then re-run.');

            return self::FAILURE;
        }

        $report = [
            'generated_at' => app(AiClock::class)->now()->toIso8601String(),
            'iterations' => $iterations,
            'mode' => (string) config('ai.cognition.mode', 'external'),
            'payload_limit_bytes' => (int) config('ai.cognition.payload.maximum_response_bytes', 262_144),
            'measurements' => $measurements,
        ];

        $path = self::ARTIFACT_DIRECTORY . '/' . app(AiClock::class)->now()->format('Ymd-His') . '.json';
        Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->table(
            ['driver', 'contract', 'calls', 'http', 'p50 ms', 'p95 ms', 'max ms', 'req bytes', 'resp bytes', 'correct'],
            array_map(static fn (array $entry): array => [
                $entry['driver'],
                $entry['contract'],
                $entry['measured'] ? $entry['calls'] : 'not selected',
                $entry['http_calls'] ?? '-',
                $entry['latency_ms']['p50'] ?? '-',
                $entry['latency_ms']['p95'] ?? '-',
                $entry['latency_ms']['max'] ?? '-',
                $entry['request_bytes']['total'] ?? '-',
                $entry['response_bytes']['total'] ?? '-',
                $entry['measured'] ? ($entry['correct'] ? 'yes' : 'no') : '-',
            ], $measurements),
        );

        foreach ($measured as $entry) {
            foreach ($entry['observed_failure_modes'] as $mode) {
                $this->line(sprintf(
                    '  %s failure probe: %s -> HTTP %s (%s)',
                    $entry['driver'],
                    $mode['probe'],
                    $mode['status'] ?? 'no response',
                    $mode['refused'] ? 'refused by the module or reported by the driver' : 'accepted',
                ));
            }
        }

        $this->info('Measured evidence written to ' . $path . '. Capture CPU/RAM with `docker stats --no-stream` and record both together.');

        return count(array_filter($measured, static fn (array $entry): bool => $entry['correct'])) === count($measured)
            ? self::SUCCESS
            : self::FAILURE;
    }

    /** @return list<string> */
    private function requestedDrivers(string $only): array
    {
        if ($only === '') {
            return self::SUPPORTED_DRIVERS;
        }

        return in_array($only, self::SUPPORTED_DRIVERS, true) ? [$only] : [];
    }

    /** @return array<string, mixed> */
    private function measureCbrKit(int $iterations): array
    {
        $selected = (string) config('ai.cognition.experience.driver') === 'cbrkit';

        if (!$selected) {
            return $this->notSelected('cbrkit', 'ExperienceEngine');
        }

        DB::beginTransaction();

        try {
            $caseIds = $this->seedBuildingCases();
            $query = $this->buildingQuery();
            $readBytes = $this->collectBytes();

            $ranked = [];
            $durations = [];

            for ($iteration = 0; $iteration < $iterations; $iteration++) {
                $startedAt = hrtime(true);
                $ranked = app(ExperienceEngine::class)->rankSimilarExperiences($query);
                $durations[] = intdiv(hrtime(true) - $startedAt, 1_000_000);
            }

            $native = app(NativeExperienceEngine::class)->rankSimilarExperiences($query);
            $driverOrder = array_map(static fn ($experience): int => $experience->caseId, $ranked);
            $nativeOrder = array_map(static fn ($experience): int => $experience->caseId, $native);
            // Read after the measured calls, and before the failure probe below.
            $totals = $readBytes();
        } finally {
            DB::rollBack();
        }

        $differentiation = $this->cbrkitDifferentiationProbe();

        return [
            'driver' => 'cbrkit',
            'contract' => ExperienceEngine::class,
            'base_url' => (string) config('ai.cognition.experience.cbrkit.base_url'),
            'measured' => true,
            'calls' => $iterations,
            'http_calls' => $totals['calls'],
            'casebase_size' => count($caseIds),
            'latency_ms' => $this->percentiles($durations),
            'request_bytes' => $totals['request'],
            'response_bytes' => $totals['response'],
            // Equivalence alone cannot distinguish a driver answer from a native fallback,
            // so the call count must also prove the driver was actually reached, and the
            // differentiation probe must prove its own weighted measure was the one used.
            'correct' => $totals['calls'] >= $iterations && $driverOrder === $nativeOrder && $differentiation['correct'],
            'ranking' => ['driver' => $driverOrder, 'native' => $nativeOrder],
            'differentiation' => $differentiation,
            'observed_failure_modes' => [$this->cbrkitFailureProbe()],
        ];
    }

    /** @return array<string, mixed> */
    private function measureFatima(int $iterations): array
    {
        $selected = (string) config('ai.cognition.driver') === 'fatima';

        if (!$selected) {
            return $this->notSelected('fatima', AffectEngine::class);
        }

        $readBytes = $this->collectBytes();
        $stimulus = $this->buildingStimulus();

        $appraisal = null;
        $durations = [];

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $startedAt = hrtime(true);
            $appraisal = app(AffectEngine::class)->appraiseObservedEvent($stimulus);
            $durations[] = intdiv(hrtime(true) - $startedAt, 1_000_000);
        }

        // Read after the measured calls, and before the failure probe below.
        $totals = $readBytes();

        // The driver must actually have contributed. The module's own engine answers this
        // stimulus with the same emotion, so an emotion match alone cannot tell a driver
        // answer from a silent fallback — the run that exposed the scenario-path defect
        // returned the native 0.08 while looking correct. The fixture is chosen so the
        // authored rule and the native engine disagree, which is what makes participation
        // observable at all.
        $native = app(NativeAffectEngine::class)->appraiseObservedEvent($stimulus);

        $hybrid = (string) config('ai.cognition.mode', 'external') === 'hybrid';

        return [
            'driver' => 'fatima',
            'contract' => AffectEngine::class,
            'base_url' => (string) config('ai.cognition.fatima.base_url'),
            'measured' => true,
            'calls' => $iterations,
            'http_calls' => $totals['calls'],
            'latency_ms' => $this->percentiles($durations),
            'request_bytes' => $totals['request'],
            'response_bytes' => $totals['response'],
            // In hybrid the native emotion stays canonical and the driver contributes its
            // own mapped emotion, mood and intensity as evidence; in external the driver's
            // emotion is the answer. Either way the driver must be distinguishable from a
            // silent native fallback.
            'correct' => $totals['calls'] >= $iterations
                && $appraisal !== null
                && ($hybrid
                    ? $appraisal->emotion === $native->emotion
                        && $appraisal->driverEmotion !== null
                        && $appraisal->driverIntensity !== $native->intensity
                        && $appraisal->mood !== null
                    : $appraisal->emotion === AiAffectEmotion::Anger
                        && $appraisal->intensity !== $native->intensity),
            'observed' => [
                'emotion' => $appraisal?->emotion->name,
                'intensity' => $appraisal?->intensity,
                'driver_emotion' => $appraisal?->driverEmotion?->name,
                'mood' => $appraisal?->mood,
            ],
            'native' => ['emotion' => $native->emotion->name, 'intensity' => $native->intensity],
            'observed_failure_modes' => [$this->fatimaFailureProbe()],
        ];
    }

    /** @return array<string, mixed> */
    private function notSelected(string $driver, string $contract): array
    {
        return [
            'driver' => $driver,
            'contract' => $contract,
            'base_url' => null,
            'measured' => false,
            'calls' => 0,
            'http_calls' => 0,
            'observed_failure_modes' => [],
        ];
    }

    /**
     * Installs counters on every outgoing request and returns a reader.
     *
     * The reader is a closure capturing by reference on purpose: an arrow function or a
     * returned array would copy the totals before the measured requests ran, and every
     * figure would read back as zero. The call count is what makes the measurement
     * decisive — without it a driver that silently degraded to the native path would
     * still look equivalent.
     *
     * @return Closure(): array{calls: int, request: array{total: int, max: int}, response: array{total: int, max: int}}
     */
    private function collectBytes(): Closure
    {
        $bytes = [
            'calls' => 0,
            'request' => ['total' => 0, 'max' => 0],
            'response' => ['total' => 0, 'max' => 0],
        ];

        Http::globalRequestMiddleware(function ($request) use (&$bytes) {
            $size = (int) ($request->getBody()->getSize() ?? 0);
            $bytes['calls']++;
            $bytes['request']['total'] += $size;
            $bytes['request']['max'] = max($bytes['request']['max'], $size);

            return $request;
        });

        Http::globalResponseMiddleware(function ($response) use (&$bytes) {
            $size = (int) ($response->getBody()->getSize() ?? 0);
            $bytes['response']['total'] += $size;
            $bytes['response']['max'] = max($bytes['response']['max'], $size);

            return $response;
        });

        return static function () use (&$bytes): array {
            return $bytes;
        };
    }

    /**
     * A small owner-scoped casebase over the module's own building-upgrade family.
     *
     * The cases differ only by planet, which the query never asks about, so every case
     * scores the same. That is deliberate: this fixture exists to measure the adapter's
     * latency, bytes and failure behaviour, and a tied casebase keeps the equivalence check
     * well defined without the command re-deriving the module's own similarity formula.
     *
     * @return list<int>
     */
    private function seedBuildingCases(): array
    {
        $cases = [];

        foreach ([1, 2, 3, 4] as $index => $planetId) {
            $case = app(RecordAiExperienceOutcomeAction::class)->handle(
                self::FIXTURE_PLAYER_ID,
                self::FIXTURE_PLAYER_ID + $index + 1,
                AiExperienceCaseFamily::BuildingUpgrade,
                AiExperienceOutcome::Succeeded,
                AiExperienceFeatureVersion::BuildingUpgradeV1->value,
                AiExperienceRulesetVersion::HostBuildingCompletionV1->value,
                [
                    AiBuildingExperienceFeature::PlanetId->value => $planetId,
                    AiBuildingExperienceFeature::ObjectId->value => 2,
                    AiBuildingExperienceFeature::TargetLevel->value => 6,
                ],
                1.0,
                0.1,
            );

            $cases[] = $case->id;
        }

        return $cases;
    }

    private function buildingQuery(): ExperienceQuery
    {
        return app()->makeWith(ExperienceQuery::class, [
            'playerId' => self::FIXTURE_PLAYER_ID,
            'family' => AiExperienceCaseFamily::BuildingUpgrade,
            'featureVersion' => AiExperienceFeatureVersion::BuildingUpgradeV1->value,
            'rulesetVersion' => AiExperienceRulesetVersion::HostBuildingCompletionV1->value,
            'features' => [
                AiBuildingExperienceFeature::ObjectId->value => 2,
                AiBuildingExperienceFeature::TargetLevel->value => 6,
            ],
            'limit' => 4,
        ]);
    }

    private function buildingStimulus(): ObservedStimulus
    {
        return app()->makeWith(ObservedStimulus::class, [
            'archetype' => AiArchetype::Miner,
            'harm' => 0.4,
            'aid' => 0.0,
            'threat' => 0.0,
            'relationshipTrust' => 0.0,
        ]);
    }

    /** @return array{probe: string, status: int|null, refused: bool} */
    private function cbrkitFailureProbe(): array
    {
        $response = Http::connectTimeout(2)
            ->timeout(5)
            ->acceptJson()
            ->post(
                rtrim((string) config('ai.cognition.experience.cbrkit.base_url'), '/') . '/retrieve',
                ['casebase' => 'not-an-object', 'queries' => ['current' => ['object_id' => 1]]],
            );

        return [
            'probe' => 'non-object casebase',
            'status' => $response->status(),
            'refused' => !$response->successful(),
        ];
    }

    /**
     * The driver's own measure is per-feature weighted with categorical identity, so two
     * cases for different objects score equally even when their ids are numerically near.
     * The numeric port this replaced ranked object 3 above object 9 (0.667 vs 0.222) for a
     * query about object 2; the categorical measure ties them at 0. This probe pins that
     * tie — it is what makes "the driver used its own measure" observable rather than assumed.
     *
     * @return array{probe: string, correct: bool, similarities: array<string, float>}
     */
    private function cbrkitDifferentiationProbe(): array
    {
        $response = Http::connectTimeout(2)
            ->timeout(5)
            ->acceptJson()
            ->post(
                rtrim((string) config('ai.cognition.experience.cbrkit.base_url'), '/') . '/retrieve',
                [
                    'casebase' => [
                        9001 => ['object_id' => 9, 'target_level' => 6],
                        9002 => ['object_id' => 3, 'target_level' => 6],
                        9003 => ['object_id' => 2, 'target_level' => 6],
                    ],
                    'queries' => ['current' => ['object_id' => 2, 'target_level' => 6]],
                ],
            );

        $scores = $response->json()['steps'][0]['queries']['current']['similarities'] ?? null;

        $match = is_array($scores) ? (float) ($scores[9003] ?? 0.0) : 0.0;
        $far = is_array($scores) ? (float) ($scores[9001] ?? -1.0) : -1.0;
        $near = is_array($scores) ? (float) ($scores[9002] ?? -1.0) : -1.0;

        return [
            'probe' => 'per-feature weighted measure',
            'correct' => $match === 1.0 && $far === $near && $near < 1.0,
            'similarities' => ['object_match' => $match, 'object_9' => $far, 'object_3' => $near],
        ];
    }

    /** @return array{probe: string, status: int|null, refused: bool} */
    private function fatimaFailureProbe(): array
    {
        $response = Http::connectTimeout(2)
            ->timeout(5)
            ->acceptJson()
            ->get(rtrim((string) config('ai.cognition.fatima.base_url'), '/')
                . '/scenarios/OgameCognition/instances/1/characters/Miner/emotions');

        // The driver answers HTTP 200 with a JSON string when state is unknown, so a
        // success status alone is not evidence that it returned an emotional pool.
        $payload = $response->json();

        return [
            'probe' => 'emotions requested before any perception',
            'status' => $response->status(),
            'refused' => !is_array($payload) || !array_key_exists('Emotions', $payload),
        ];
    }

    /**
     * @param  list<int>  $durations
     * @return array{p50: int, p95: int, min: int, max: int, samples: int}
     */
    private function percentiles(array $durations): array
    {
        sort($durations);

        return [
            'p50' => $this->percentile($durations, 0.50),
            'p95' => $this->percentile($durations, 0.95),
            'min' => $durations[0],
            'max' => $durations[count($durations) - 1],
            'samples' => count($durations),
        ];
    }

    /** @param list<int> $sorted */
    private function percentile(array $sorted, float $fraction): int
    {
        $index = max(0, min((int) ceil($fraction * count($sorted)) - 1, count($sorted) - 1));

        return $sorted[$index];
    }
}
