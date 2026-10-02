<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// ARB-001's boundary, both sides: the errand slot takes what the situation offers.
// ARB-001's boundary. With nothing to raid, recycle or colonise and no road out, the chore that
// fills the build queue keeps the login: an account with only an economy to tend still builds.
// ExpeditionSituationTest proves the other side, where the situation offers a fleet errand.
test('an account with only an economy to tend spends the login on the build chore', function (): void {
    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->session()
        ->expectWork(AiWorkKind::BuildFirstBuilding);
});

// The same boundary from the other side: the chore fills the queues anyway, so a colony ship on
// hand and a slot free takes the login instead of it — the errand the situation offers wins.
test('a colony ship on hand takes the login from the build chore', function (): void {
    Situation::of($this)
        ->research('astrophysics', 4)
        ->research('impulse_drive', 3)
        ->ships('colony_ship', 1)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->session()
        ->expectWork(AiWorkKind::Colonize);
});
