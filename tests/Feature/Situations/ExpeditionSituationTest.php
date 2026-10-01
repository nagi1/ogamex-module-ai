<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// ARB-001's fast proof (aspect: fleet_breadth). A player with a fleet and nothing to raid or recycle
// sends it on an expedition sometimes, so over a few logins the fleet does more than sit at home.
test('a fleet with nothing to raid or recycle flies an expedition within a few logins', function (): void {
    Situation::of($this)
        ->research('astrophysics', 3)
        ->research('combustion_drive', 6)
        ->ships('large_cargo', 10)
        ->ships('light_fighter', 20)
        ->ships('espionage_probe', 1)
        ->resources(500_000, 500_000, 500_000)
        ->sessions(4)
        ->expectWork(AiWorkKind::Expedition);
});
