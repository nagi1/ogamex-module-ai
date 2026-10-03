<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

interface QueueAiMissile
{
    public function handle(int $playerId, int $originPlanetId, int $targetGalaxy, int $targetSystem, int $targetPosition, int $targetType, int $missiles): AiActionResult;
}
