<?php

namespace Modules\AI\Support;

use Illuminate\Support\Facades\Log;
use Modules\AI\Enums\AiQueueName;

/**
 * Applies the module's Horizon plan to the host's Horizon configuration at runtime.
 *
 * Horizon reads config('horizon.*') when the master supervisor starts, which happens
 * after every provider has booted, so the plan takes effect without editing the host
 * horizon file. The module provider only runs while the module is enabled, so a
 * disabled module leaves no AI supervisor, wait or queue in the host.
 */
class HorizonConfiguration
{
    /**
     * The Horizon connection the module's lanes run on.
     */
    private const CONNECTION = 'redis';

    public function contribute(): void
    {
        // Announce the module's queue names first: the host's Horizon guard treats a
        // supervisor queue as known only when it is an enum value or a module name.
        $this->announceQueueNames();

        $plan = $this->plan();

        if (!(bool) ($plan['enabled'] ?? true) || !is_array(config('horizon'))) {
            return;
        }

        $supervisors = is_array($plan['supervisors'] ?? null) ? $plan['supervisors'] : [];

        foreach ($supervisors as $name => $options) {
            config(['horizon.defaults.'.$name => $options]);
        }

        $this->applyWaits(is_array($plan['waits'] ?? null) ? $plan['waits'] : []);
        $this->provisionEnvironments($supervisors, is_array($plan['processes'] ?? null) ? $plan['processes'] : []);
    }

    /**
     * Register this module's queue names so the host can distinguish module-owned
     * queues from unknown ones without the module editing the host enum.
     */
    private function announceQueueNames(): void
    {
        config(['queue.module_queue_names' => array_values(array_unique(array_merge(
            (array) config('queue.module_queue_names', []),
            AiQueueName::values(),
        )))]);
    }

    /** @param array<string, mixed> $waits */
    private function applyWaits(array $waits): void
    {
        foreach ($waits as $queue => $seconds) {
            config(['horizon.waits.'.self::CONNECTION.':'.$queue => $seconds]);
        }
    }

    /**
     * A supervisor is provisioned only when it appears in an environment block; the
     * shared defaults supply the rest of its options. Cover production, staging, local
     * and the "*" fallback generically so the plan works for every APP_ENV.
     *
     * @param array<string, mixed> $supervisors
     * @param array<string, mixed> $processes
     */
    private function provisionEnvironments(array $supervisors, array $processes): void
    {
        $environments = config('horizon.environments');

        if (!is_array($environments)) {
            return;
        }

        foreach (array_keys($environments) as $environment) {
            foreach (array_keys($supervisors) as $name) {
                config(['horizon.environments.'.$environment.'.'.$name => [
                    'maxProcesses' => max(1, (int) ($processes[$name] ?? 1)),
                ]]);
            }
        }
    }

    /**
     * Read the module plan, falling back to the config file directly so the lanes are
     * still registered when config('ai.horizon') is absent — for example when the
     * module was enabled after `php artisan config:cache` had already run.
     *
     * @return array<string, mixed>
     */
    private function plan(): array
    {
        $plan = config('ai.horizon');

        if (is_array($plan)) {
            return $plan;
        }

        // The module was enabled after config:cache had already run, so its config was
        // never merged. Read the file directly rather than shipping no lanes.
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'horizon.php';

        if (!is_file($path) || !is_readable($path)) {
            Log::warning('AI Horizon plan config is missing or unreadable; no AI lanes were registered.', [
                'path' => $path,
            ]);

            return [];
        }

        $fallback = require $path;

        return is_array($fallback) ? $fallback : [];
    }
}
