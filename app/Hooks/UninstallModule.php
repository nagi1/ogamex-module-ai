<?php

namespace Modules\AI\Hooks;

use OGame\Modules\Contracts\ModuleHook;
use OGame\Modules\ModuleHookContext;

/**
 * Explains what disabling the AI module does and does not remove.
 *
 * Runs before the module is disabled (and before any optional data rollback), so it
 * never has to touch module state itself.
 */
class UninstallModule implements ModuleHook
{
    public function handle(ModuleHookContext $context): void
    {
        $context->line('AI module: Horizon lanes and container contributions stop as soon as the module is disabled.');
        $context->line('Module tables (memory, commitments, language requests, experience cases) are retained unless --drop-data is given.');
    }
}
