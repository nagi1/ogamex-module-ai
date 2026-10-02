<?php

use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// QUAL-008's boundary. The colony ship is what the mission consumes, but the host gates the
// destination on the account's own astrophysics: a ship without the reach finds no colonisable
// slot, so the account must not dispatch it. The kit plants no research, so the reach is zero.
test('a colony ship without the astrophysics reach flies no colonisation', function (): void {
    Situation::of($this)
        ->ships('colony_ship', 1)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->sessions(2)
        ->expectNoMission('Colonisation');
});
