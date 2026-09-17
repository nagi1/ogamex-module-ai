<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

/**
 * This translates a module decision to fleetsave into the host's normal fleet path.
 *
 * The host remains the authority on whether the fleet exists, the destination is
 * the account's own planet and the mission is legal.
 */
interface QueueAiFleetSave
{
    public function handle(
        int $playerId,
        int $originPlanetId,
        int $destinationPlanetId,
        int $shadowDestinationPlanetId = 0,
        int $harvestGalaxy = 0,
        int $harvestSystem = 0,
        int $harvestPosition = 0,
        float $speed = 1.0,
    ): AiActionResult;
}
