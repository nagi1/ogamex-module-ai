<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

interface QueueAiTransfer
{
    public function handle(int $playerId, int $sourcePlanetId, int $targetPlanetId, int $metal, int $crystal, int $deuterium): AiActionResult;
}
