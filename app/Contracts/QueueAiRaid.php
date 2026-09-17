<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

/**
 * This translates a module decision to raid into the host's normal attack path.
 *
 * The host remains the authority on whether the target is legal, the fleet exists
 * and the attack can start.
 */
interface QueueAiRaid
{
    public function handle(int $playerId, int $originPlanetId, int $targetGalaxy, int $targetSystem, int $targetPosition, int $targetType, ?array $launchUnits = null): AiActionResult;
}
