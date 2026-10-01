<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// QUAL-009's fast proof (aspect: shipyard). An established player with a shipyard, the drive for cargo
// ships and spare stock builds ships: a fleet is what raids, saves and recycles fly with.
test('an established account with a shipyard and spare stock builds ships', function (): void {
    Situation::of($this)
        ->level('shipyard', 4)
        ->level('robot_factory', 2)
        ->research('combustion_drive', 6)
        ->research('impulse_drive', 3)
        ->resources(5_000_000, 3_000_000, 1_000_000)
        ->sessions(3)
        ->expectWork(AiWorkKind::QueueUnits);
});
