<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

interface QueueAiTrade
{
    public function handle(int $playerId, int $planetId, string $giveResource, string $receiveResource, int $giveAmount): AiActionResult;
}
