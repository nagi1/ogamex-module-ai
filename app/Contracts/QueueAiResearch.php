<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

/**
 * This translates a module decision to research into the host's normal queue path.
 *
 * Callers receive a typed outcome instead of needing to know whether the host rejected ownership,
 * legality, affordability, or queue availability.
 */
interface QueueAiResearch
{
    public function handle(int $playerId, int $planetId, int $researchId): AiActionResult;
}
