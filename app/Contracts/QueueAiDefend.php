<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

interface QueueAiDefend
{
    public function handle(int $playerId, int $sourcePlanetId, int $targetPlanetId): AiActionResult;
}
