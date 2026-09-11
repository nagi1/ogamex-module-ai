<?php

namespace Modules\AI\Hooks;

use OGame\Modules\Contracts\ModuleHook;
use OGame\Modules\ModuleHookContext;

/**
 * Reports AI module prerequisites after install.
 *
 * Hooks run outside the module provider, so this only reads host configuration and
 * reports a note instead of failing the install for an optional capability.
 */
class InstallModule implements ModuleHook
{
    public function handle(ModuleHookContext $context): void
    {
        $context->line('AI module: Horizon lanes "ai" and "ai-language" are registered while the module is enabled.');

        if (config('queue.default') !== 'redis') {
            $context->line('Note: QUEUE_CONNECTION is not "redis", so Horizon is not provisioned. AI asynchronous work needs Horizon; see Modules/AI/docs/architecture.md.');
        }

        if (config('cache.default') === 'array') {
            $context->line('Note: the array cache store cannot share locks or usage budgets between processes; use a shared cache store in production.');
        }
    }
}
