<?php

namespace Modules\AI\Infrastructure\Memory;

use Illuminate\Http\Client\Factory;
use Modules\AI\Support\DriverCircuitBreaker;
use Modules\AI\Support\DriverResponseLimit;
use Throwable;

/**
 * Transport for the AgentOS memory sidecar.
 *
 * The driver keeps no store: the module sends the memories it has authorised for this recall
 * with every request and keeps the canonical records, so the sidecar can never become a second
 * copy of memory that has to be kept in step, backed up or deleted. Any transport failure,
 * non-success status or contract deviation returns null so the caller uses its native recall
 * instead of a partial or invented ranking.
 */
class AgentOsClient
{
    public function __construct(private readonly DriverCircuitBreaker $circuit, private readonly Factory $http, private readonly DriverResponseLimit $limit)
    {
    }

    /**
     * @param  list<array{id:int,text:string,tags:list<string>}>  $memories
     * @return list<int>|null
     */
    public function recall(int $playerId, string $query, int $limit, array $memories): array|null
    {
        if ($query === '' || $memories === []) {
            return null;
        }

        if (!$this->circuit->allowsRequest()) {
            return null;
        }

        try {
            $response = $this->http
                ->baseUrl(rtrim((string) config('ai.cognition.memory.agentos.base_url', 'http://host.docker.internal:8093'), '/'))
                ->connectTimeout((int) config('ai.cognition.memory.agentos.connect_timeout_seconds', 2))
                ->timeout((int) config('ai.cognition.memory.agentos.timeout_seconds', 5))
                ->acceptJson()
                ->post('/recall', [
                    'scopeId' => 'player-' . $playerId,
                    'query' => $query,
                    'limit' => $limit,
                    'memories' => $memories,
                ]);
        } catch (Throwable) {
            // Any transport failure degrades to native; the driver is never allowed to
            // surface a partially retrieved or invented ordering.
            $this->circuit->recordFailure();

            return null;
        }

        // An oversized body is charged exactly like a malformed one: the driver answered
        // with more than the module agreed to read, so its answer is not interpreted.
        $ranking = $response->successful() && $this->limit->withinLimit($response->body())
            ? $this->ranking($response->json(), $memories)
            : null;

        if ($ranking === null) {
            $this->circuit->recordFailure();

            return null;
        }

        $this->circuit->recordSuccess();

        return $ranking;
    }

    /**
     * The driver may only reorder evidence the module sent: an id it was never given is a
     * scope leak and a repeated id is not a ranking, so either one discards the whole answer.
     *
     * An empty ranking is not a deviation — a recall engine that finds nothing relevant says
     * so — and is returned as-is for the caller to decide what an empty answer means.
     *
     * @param  list<array{id:int,text:string,tags:list<string>}>  $memories
     * @return list<int>|null
     */
    private function ranking(mixed $payload, array $memories): array|null
    {
        $ranking = is_array($payload) ? ($payload['ranking'] ?? null) : null;

        if (!is_array($ranking)) {
            return null;
        }

        $authorised = [];

        foreach ($memories as $memory) {
            $authorised[$memory['id']] = true;
        }

        $ids = [];

        foreach ($ranking as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;

            if (!is_int($id) || !isset($authorised[$id]) || in_array($id, $ids, true)) {
                return null;
            }

            $ids[] = $id;
        }

        return $ids;
    }
}
