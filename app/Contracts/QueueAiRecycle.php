<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

/**
 * Translates a module decision to recycle into the host's normal harvest path.
 *
 * The host remains the authority on whether the field exists, the harvest hull
 * is present and the mission can start.
 */
interface QueueAiRecycle
{
    public function handle(int $playerId, int $planetId, int $targetGalaxy, int $targetSystem, int $targetPosition, int $targetType): AiActionResult;
}
