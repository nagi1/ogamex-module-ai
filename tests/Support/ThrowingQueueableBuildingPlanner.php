<?php

namespace Modules\AI\Tests\Support;

use Modules\AI\Domain\Decision\QueueableBuilding;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\QueueableResearch;
use OGame\Services\PlayerService;
use RuntimeException;

/**
 * A planner that fails, so a test can prove what the job does when deciding throws.
 *
 * The job consults the planner only for an intent that carries no building of its own, which is
 * exactly the case a test wants to force: nothing was written down, so the failure is the decision's.
 */
class ThrowingQueueableBuildingPlanner extends QueueableBuildingPlanner
{
    public function __construct()
    {
    }

    public function plan(int $playerId, ?PlayerService $player = null): QueueableBuilding|QueueableResearch|null
    {
        throw app()->makeWith(RuntimeException::class, ['message' => 'test decision failure']);
    }
}
