<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

interface QueueAiExpedition
{
    public function handle(int $playerId, int $planetId, int $galaxy, int $system): AiActionResult;
}
