<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

/**
 * This translates a module decision to espionage into the host's normal fleet path.
 *
 * The host remains the authority on whether the target is legal, the probe exists
 * and the mission can start.
 */
interface QueueAiSpy
{
    public function handle(int $playerId, int $planetId, int $targetGalaxy, int $targetSystem, int $targetPosition, int $targetType, int $probeCount = 1): AiActionResult;
}
