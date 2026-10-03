<?php

namespace Modules\AI\Infrastructure\Cognition;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Modules\AI\Support\DriverCircuitBreaker;
use Modules\AI\Support\DriverResponseLimit;
use Throwable;

/**
 * Transport for the PsychSim sidecar.
 *
 * The sidecar owns the decision-theoretic step; the module owns the OGame-to-PsychSim
 * mapping and sends only a game-theoretic incentive, never a fleet, resource or promise.
 * Any transport failure, non-success status or contract deviation returns null so the
 * caller falls through to its native stance instead of acting on an invented answer.
 */
class PsychSimClient
{
    public function __construct(
        private readonly DriverCircuitBreaker $circuit,
        private readonly Factory $http,
        private readonly DriverResponseLimit $limit,
    ) {
    }

    /**
     * The account's depth-one stance for one counterparty incentive.
     *
     * @return 'cooperate'|'defect'|null
     */
    public function decide(float $temptation): string|null
    {
        // The world is rebuilt from the temptation alone, so the same incentive always gets the same
        // stance: an answer already given is reused instead of another round trip.
        $key = 'ai:psychsim:' . md5((string) config('ai.cognition.psychsim.base_url', '') . '|' . $temptation);
        $known = Cache::get($key);
        if ($known === 'cooperate' || $known === 'defect') {
            return $known;
        }

        $decision = $this->ask($temptation);

        if ($decision !== null) {
            Cache::put($key, $decision, now()->addHour());
        }

        return $decision;
    }

    private function ask(float $temptation): string|null
    {
        if (!$this->circuit->allowsRequest()) {
            return null;
        }

        try {
            $response = $this->http
                ->baseUrl(rtrim((string) config('ai.cognition.psychsim.base_url', 'http://host.docker.internal:8094'), '/'))
                ->connectTimeout((int) config('ai.cognition.psychsim.connect_timeout_seconds', 2))
                ->timeout((int) config('ai.cognition.psychsim.timeout_seconds', 5))
                ->acceptJson()
                ->post('/evaluate', ['temptation' => $temptation]);
        } catch (Throwable) {
            $this->circuit->recordFailure();

            return null;
        }

        if (!$response->successful() || !$this->limit->withinLimit($response->body())) {
            $this->circuit->recordFailure();

            return null;
        }

        $decision = $response->json('decision');

        if ($decision !== 'cooperate' && $decision !== 'defect') {
            $this->circuit->recordFailure();

            return null;
        }

        $this->circuit->recordSuccess();

        return $decision;
    }
}
