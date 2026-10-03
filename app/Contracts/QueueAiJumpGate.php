<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

interface QueueAiJumpGate
{
    public function handle(int $playerId, int $sourceMoonId, int $targetMoonId): AiActionResult;
}
