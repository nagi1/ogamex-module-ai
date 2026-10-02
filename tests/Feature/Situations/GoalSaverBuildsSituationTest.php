<?php

use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// PERS-007 (aspect: economy, invariant: IDLE_QUEUES). stockpile_strategy decides *how* a pile is
// spent, never *whether* the account plays: a goal saver with stock on every planet keeps every
// build queue busy, the same way the immediate spender does.
test('a goal saver with stock on every planet keeps every build queue busy after one login', function (): void {
    Situation::of($this)
        ->goalSaver()
        ->colony()
        ->stockEveryPlanet(500_000, 300_000, 100_000)
        ->session()
        ->expectEveryPlanetBuilding();
});
