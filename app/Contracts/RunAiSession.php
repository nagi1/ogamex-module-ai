<?php

namespace Modules\AI\Contracts;

use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;

/**
 * Application action boundary for one already-claimed AI session.
 *
 * Jobs resolve this contract through Laravel's container so production, tests,
 * and future host integrations can replace its implementation without
 * changing queue orchestration.
 */
interface RunAiSession
{
    public function handle(AiProfile $profile, AiWorkItem $workItem): void;
}
