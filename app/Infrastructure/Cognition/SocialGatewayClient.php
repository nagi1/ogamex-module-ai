<?php

namespace Modules\AI\Infrastructure\Cognition;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

/**
 * Transport for the social gateway (docker/cognition/gateway): one front door for FAtiMA, PsychSim, CBRKit and AgentOS.
 * It only carries the request; every answer is an engine's. A failed batch or item comes back as null, so the caller
 * acts on no invented answer.
 */
class SocialGatewayClient
{
    public function __construct(private readonly Factory $http)
    {
    }

    public function answers(): bool
    {
        try {
            return $this->request()->get('/health')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * One batch of `{engine, route, body}` items; one answer per item, in order, null where it failed.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>|null>
     */
    public function batch(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $failed = array_fill(0, count($items), null);
        try {
            $response = $this->request()->post('/v1/batch', ['items' => $items]);
        } catch (Throwable) {
            return $failed;
        }

        $results = $response->successful() ? $response->json('results') : null;
        if (!is_array($results)) {
            return $failed;
        }

        return array_map(static fn (int $i): array|null => is_array($results[$i] ?? null) && !isset($results[$i]['error']) ? $results[$i] : null, array_keys($items));
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim((string) config('ai.cognition.social.gateway_url', 'http://host.docker.internal:8099'), '/'))
            ->connectTimeout((int) config('ai.cognition.social.connect_timeout_seconds', 2))
            ->timeout((int) config('ai.cognition.social.timeout_seconds', 60))
            ->acceptJson();
    }
}
