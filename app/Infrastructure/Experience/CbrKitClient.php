<?php

namespace Modules\AI\Infrastructure\Experience;

use Illuminate\Http\Client\Factory;
use Modules\AI\Support\DriverCircuitBreaker;
use Modules\AI\Support\DriverResponseLimit;
use Throwable;

/**
 * Transport for the CBRKit sidecar.
 *
 * CBRKit exposes no case storage: the module sends the owner-scoped casebase with
 * every request and keeps the canonical records, so the driver can never become the
 * case database. Any transport failure, non-success status or contract deviation
 * returns null so the caller uses its native implementation instead of a partial
 * or fabricated ranking.
 */
class CbrKitClient
{
    public function __construct(private readonly DriverCircuitBreaker $circuit, private readonly Factory $http, private readonly DriverResponseLimit $limit)
    {
    }

    /**
     * @param  array<int, array<string, int|float|string|null>>  $casebase
     * @param  array<string, int|float|string|null>  $query
     * @return array<int, float>|null
     */
    public function rank(array $casebase, array $query): array|null
    {
        if (!$this->circuit->allowsRequest()) {
            return null;
        }

        try {
            $response = $this->http
                ->baseUrl(rtrim((string) config('ai.cognition.experience.cbrkit.base_url', 'http://host.docker.internal:8091'), '/'))
                ->connectTimeout((int) config('ai.cognition.experience.cbrkit.connect_timeout_seconds', 2))
                ->timeout((int) config('ai.cognition.experience.cbrkit.timeout_seconds', 5))
                ->acceptJson()
                ->post('/retrieve', [
                    'casebase' => $casebase,
                    'queries' => ['current' => $query],
                ]);
        } catch (Throwable) {
            // Any transport failure degrades to native; the driver is never allowed to
            // surface a partially retrieved or invented ranking.
            $this->circuit->recordFailure();

            return null;
        }

        // An oversized body is charged exactly like a malformed one: the driver answered
        // with more than the module agreed to read, so its answer is not interpreted.
        $similarities = $response->successful() && $this->limit->withinLimit($response->body())
            ? $this->similarities($response->json(), array_keys($casebase))
            : null;

        if ($similarities === null) {
            $this->circuit->recordFailure();

            return null;
        }

        $this->circuit->recordSuccess();

        return $similarities;
    }

    /**
     * Requires a score for every case that was sent. A missing entry means the driver
     * dropped evidence the module owns, which is a contract deviation rather than a
     * low score.
     *
     * @param  list<int>  $expected
     * @return array<int, float>|null
     */
    private function similarities(mixed $payload, array $expected): array|null
    {
        if (!is_array($payload)) {
            return null;
        }

        $scores = $payload['steps'][0]['queries']['current']['similarities'] ?? null;

        if (!is_array($scores)) {
            return null;
        }

        $similarities = [];

        foreach ($expected as $key) {
            $score = $scores[$key] ?? null;

            if (!is_int($score) && !is_float($score)) {
                return null;
            }

            $similarities[$key] = (float) $score;
        }

        return $similarities;
    }
}
