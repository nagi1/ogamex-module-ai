<?php

namespace Modules\AI\Infrastructure\Cognition;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Modules\AI\Support\DriverCircuitBreaker;
use Modules\AI\Support\DriverResponseLimit;
use Modules\AI\Support\FatimaScenarioTemplate;
use Throwable;

/**
 * Transport for the FAtiMA/CiF sidecar.
 *
 * The driver answers every request with HTTP 200 and serializes failures as a plain
 * JSON string, so a status code carries no meaning here. Failures are therefore
 * detected by reading a structured result back and checking its shape, never by
 * trusting the acknowledgement text of a mutating call.
 *
 * The driver is a calculator, not a store: the module re-sends its authored scenario
 * before each appraisal, which both discards any accumulated emotional state and
 * keeps the module's own tables authoritative.
 */
class FatimaClient
{
    public function __construct(
        private readonly DriverCircuitBreaker $circuit,
        private readonly FatimaScenarioTemplate $template,
        private readonly Factory $http,
        private readonly DriverResponseLimit $limit,
    ) {
    }

    /**
     * Re-sends the authored scenario, resetting the driver to a clean baseline.
     *
     * Failure is not reported here: it surfaces as an unreadable structured result on
     * the next read, which is also where the circuit breaker accounts for it.
     */
    public function loadScenario(string $scenario): void
    {
        $this->post('/scenarios', [
            'scenario' => $this->template->scenarioJson(),
            'assets' => $this->template->assetsJson(),
        ]);
    }

    public function setBelief(string $scenario, int $instance, string $character, string $name, string $value): void
    {
        $this->post($this->characterPath($scenario, $instance, $character) . '/beliefs', [
            'name' => $name,
            'value' => $value,
        ]);
    }

    public function perceive(string $scenario, int $instance, string $character, string $event): void
    {
        $this->post($this->characterPath($scenario, $instance, $character) . '/perceptions', [$event]);
    }

    /**
     * @return array{mood: float, emotions: list<FatimaEmotion>}|null
     */
    public function emotions(string $scenario, int $instance, string $character): array|null
    {
        $payload = $this->structured('GET', $this->characterPath($scenario, $instance, $character) . '/emotions');

        if ($payload === null) {
            return null;
        }

        $emotions = $payload['Emotions'] ?? null;
        $mood = $payload['Mood'] ?? 0.0;

        // A malformed entry means the driver changed its contract, which must not be
        // papered over by skipping the record or reading a neutral mood.
        if (!is_array($emotions) || !is_numeric($mood)) {
            $this->circuit->recordFailure();

            return null;
        }

        $parsed = [];

        foreach ($emotions as $emotion) {
            $type = $emotion['Type'] ?? null;
            $intensity = $emotion['Intensity'] ?? null;
            $cause = $emotion['CauseEventName'] ?? null;

            // A malformed entry means the driver changed its contract, which must not be
            // papered over by skipping the record.
            if (!is_string($type) || !is_numeric($intensity) || !is_string($cause)) {
                $this->circuit->recordFailure();

                return null;
            }

            $parsed[] = app()->makeWith(FatimaEmotion::class, [
                'type' => $type,
                'intensity' => (float) $intensity,
                'causeEvent' => $cause,
            ]);
        }

        $this->circuit->recordSuccess();

        return ['mood' => (float) $mood, 'emotions' => $parsed];
    }

    /**
     * @return list<array{name: string, step: string, volitions: array<string, float>}>|null
     */
    public function evaluateSocialExchanges(string $scenario, int $instance, string $character, string $target): array|null
    {
        $payload = $this->structured(
            'POST',
            $this->characterPath($scenario, $instance, $character) . '/socialexchanges',
            ['target' => $target],
        );

        if ($payload === null) {
            return null;
        }

        // The endpoint answers with a JSON string when the scenario, instance or
        // character is unknown, so a non-list payload is a deviation rather than an
        // empty exchange set.
        if (!array_is_list($payload)) {
            $this->circuit->recordFailure();

            return null;
        }

        $exchanges = [];

        foreach ($payload as $exchange) {
            $name = $exchange['Name'] ?? null;
            $step = $exchange['Step'] ?? null;
            $volitions = $exchange['Volitions'] ?? null;

            if (!is_string($name) || !is_string($step) || !is_array($volitions)) {
                $this->circuit->recordFailure();

                return null;
            }

            $usable = [];

            foreach ($volitions as $mode => $volition) {
                if (!is_numeric($volition)) {
                    $this->circuit->recordFailure();

                    return null;
                }

                $usable[(string) $mode] = (float) $volition;
            }

            $exchanges[] = ['name' => $name, 'step' => $step, 'volitions' => $usable];
        }

        $this->circuit->recordSuccess();

        return $exchanges;
    }

    private function characterPath(string $scenario, int $instance, string $character): string
    {
        return sprintf('/scenarios/%s/instances/%d/characters/%s', $scenario, $instance, $character);
    }

    private function post(string $path, mixed $body): void
    {
        if (!$this->circuit->allowsRequest()) {
            return;
        }

        try {
            $this->pending()->post($path, $body);
        } catch (Throwable) {
            // A dropped mutation is not fatal on its own: the next structured read fails
            // too, and that is where the circuit breaker is charged.
        }
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function structured(string $method, string $path, mixed $body = null): array|null
    {
        if (!$this->circuit->allowsRequest()) {
            return null;
        }

        try {
            $pending = $this->pending();
            $response = $method === 'GET'
                ? $pending->get($path)
                : $pending->post($path, $body);
        } catch (Throwable) {
            $this->circuit->recordFailure();

            return null;
        }

        // An oversized body is charged exactly like a malformed one: the driver answered
        // with more than the module agreed to read, so its answer is not interpreted.
        if (!$response->successful() || !$this->limit->withinLimit($response->body())) {
            $this->circuit->recordFailure();

            return null;
        }

        $payload = $response->json();

        if (!is_array($payload)) {
            $this->circuit->recordFailure();

            return null;
        }

        return $payload;
    }

    private function pending(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim((string) config('ai.cognition.fatima.base_url', 'http://host.docker.internal:8092'), '/'))
            ->connectTimeout((int) config('ai.cognition.fatima.connect_timeout_seconds', 2))
            ->timeout((int) config('ai.cognition.fatima.timeout_seconds', 5))
            ->acceptJson();
    }
}
