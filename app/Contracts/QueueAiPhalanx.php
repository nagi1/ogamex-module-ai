<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

/**
 * This translates a module decision to scan into the host's sensor-phalanx path.
 *
 * The host remains the authority on whether the moon owns a phalanx, the target is
 * in range and the scan can run. A refused scan records the refusal and writes no
 * game state.
 */
interface QueueAiPhalanx
{
    public function handle(int $playerId, int $moonPlanetId, int $targetPlanetId): AiActionResult;
}
