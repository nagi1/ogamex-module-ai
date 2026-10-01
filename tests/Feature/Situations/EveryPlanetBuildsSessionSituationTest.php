<?php

use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// ECON-001 / IMPL-66 / PERS-007's fast proof (aspect: economy, invariant: IDLE_QUEUES). Through the real
// session, not a planner call: a player with stock on every planet keeps every build queue busy.
test('stock on every planet keeps every build queue busy after one login', function (): void {
    Situation::of($this)
        ->colony()
        ->stockEveryPlanet(500_000, 300_000, 100_000)
        ->session()
        ->expectEveryPlanetBuilding();
});
