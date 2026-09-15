<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

interface QueueAiRecall
{
    public function handle(int $playerId, int $planetId): AiActionResult;
}
