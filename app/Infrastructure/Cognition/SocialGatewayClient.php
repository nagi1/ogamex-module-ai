<?php

namespace Modules\AI\Infrastructure\Cognition;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transport for the social gateway (docker/cognition/gateway): one front door for FAtiMA, PsychSim, CBRKit and AgentOS.
 * It only carries the request; every answer is an engine's. A failed batch or item comes back as null, so the caller
 * acts on no invented answer. Each failure is counted and logged, at most once a minute, so a run that played on the
 * YAML rules alone says so instead of reading like one the engines agreed with.
 */
class SocialGatewayClient
{
    private const WARN_EVERY_SECONDS = 60;

    private static int $failures = 0;

    private static float $lastWarned = 0.0;

    public function __construct(private readonly Factory $http)
    {
    }

    public function answers(): bool
    {
        try {
            $response = $this->request()->get('/health');
        } catch (Throwable $exception) {
            $this->fail('health', 1, $exception->getMessage());

            return false;
        }

        if (!$response->successful()) {
            $this->fail('health', 1, 'HTTP ' . $response->status());
        }

        return $response->successful();
    }

    /** Requests and batch items that came back without an engine's answer in this process. */
    public static function failures(): int
    {
        return self::$failures;
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
        } catch (Throwable $exception) {
            $this->fail('batch', count($items), $exception->getMessage());

            return $failed;
        }

        $results = $response->successful() ? $response->json('results') : null;
        if (!is_array($results)) {
            $this->fail('batch', count($items), 'HTTP ' . $response->status());

            return $failed;
        }

        $answers = array_map(static fn (int $i): array|null => is_array($results[$i] ?? null) && !isset($results[$i]['error']) ? $results[$i] : null, array_keys($items));
        $missing = array_keys($answers, null, true);
        if ($missing !== []) {
            $this->fail('item', count($missing), json_encode($results[$missing[0]] ?? null) ?: '');
        }

        return $answers;
    }

    /** The wall clock paces the warning: a simulation freezes Carbon, and a frozen minute would never pass. */
    private function fail(string $what, int $count, string $detail): void
    {
        self::$failures += $count;
        $now = microtime(true);
        if ($now - self::$lastWarned < self::WARN_EVERY_SECONDS) {
            return;
        }

        self::$lastWarned = $now;
        Log::warning('The social gateway did not answer; the alliance rules decide without the engines.', [
            'what' => $what,
            'count' => $count,
            'failures' => self::$failures,
            'detail' => mb_substr($detail, 0, 200),
        ]);
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
