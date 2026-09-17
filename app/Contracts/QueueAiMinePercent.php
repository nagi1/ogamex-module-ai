<?php

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiActionResult;

/**
 * Module-owned adapter over the host's mine-percentage setter: the same path a
 * player's resources page uses, so legality and validation stay the host's.
 */
interface QueueAiMinePercent
{
    public function handle(int $playerId, int $planetId, int $buildingId, int $percentage): AiActionResult;
}
