<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

/**
 * This translates a module decision to queue ships or defence into the host's normal unit queue.
 *
 * Callers receive a typed outcome instead of needing to know whether the host rejected ownership,
 * legality, affordability, or the object type.
 */
interface QueueAiUnits
{
    public function handle(int $playerId, int $planetId, int $unitId, int $amount): AiActionResult;
}
