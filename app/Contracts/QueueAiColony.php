<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

/**
 * This translates a module decision to colonise into the host's normal fleet path.
 *
 * Callers receive a typed outcome; the host remains the authority on whether the
 * slot is still empty, the colony ship is still there and astrophysics allows it.
 */
interface QueueAiColony
{
    public function handle(int $playerId, int $planetId, int $galaxy, int $system, int $position): AiActionResult;
}
