<?php

use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// QUAL-006 and ECON-001's shared fast proof. OGame runs the build queue and the research lab side by
// side, and a player keeps both busy: one login with stock for both starts a building AND a research.
// Today the planner gives each planet one step, a building or a research, so the other queue idles
// (live, 1 Oct 2026: account 3's planet idle because its one step was ion_technology).
test('a login with stock for both keeps the build queue and the lab busy', function (): void {
    Situation::of($this)
        ->level('research_lab', 3)
        ->resources(2_000_000, 2_000_000, 1_000_000)
        ->session()
        ->expectBuildingQueueBusy()
        ->expectResearchQueueBusy();
});
