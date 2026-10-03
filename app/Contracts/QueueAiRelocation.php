<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

interface QueueAiRelocation
{
    public function handle(int $playerId, int $planetId, int $galaxy, int $system, int $position): AiActionResult;
}
