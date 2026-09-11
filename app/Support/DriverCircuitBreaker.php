<?php

namespace Modules\AI\Support;

use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Bounds how often a failing external driver is contacted.
 *
 * Without this, a stopped sidecar would make every decision pay a connection
 * timeout. Once the driver trips, requests are skipped for the cooldown and the
 * caller falls straight through to its native implementation.
 */
class DriverCircuitBreaker
{
    public function __construct(private readonly string $driver, private readonly AiClock $clock)
    {
    }

    public function allowsRequest(): bool
    {
        return Cache::get($this->openKey()) === null;
    }

    public function recordSuccess(): void
    {
        Cache::forget($this->failuresKey());
        Cache::forget($this->openKey());
    }

    public function recordFailure(): void
    {
        $threshold = max(1, (int) config('ai.cognition.circuit.failures', 3));
        $failures = (int) Cache::get($this->failuresKey(), 0) + 1;

        if ($failures < $threshold) {
            Cache::put($this->failuresKey(), $failures, $this->cooldown());

            return;
        }

        Cache::forget($this->failuresKey());
        Cache::put($this->openKey(), true, $this->cooldown());
    }

    private function cooldown(): DateTimeInterface
    {
        return $this->clock->now()->addSeconds(max(1, (int) config('ai.cognition.circuit.cooldown_seconds', 60)));
    }

    private function failuresKey(): string
    {
        return 'ai:cognition:' . $this->driver . ':failures';
    }

    private function openKey(): string
    {
        return 'ai:cognition:' . $this->driver . ':open';
    }
}
