<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// The fast proof settles a planet with a colony ship already on hand. These are the two edges
// around it: the account whose ship is still to be built, and the account with no slot left.

// A free slot is worked for before the ship exists: the yard is given the ship. A colonisation with no ship at the origin
// would only be refused (8 Nov 2026: 201 such refusals in an hour), so it is written once the ship stands.
test('a free colony slot orders the colony ship before the colonisation', function (): void {
    Situation::of($this)
        ->research('astrophysics', 4)
        ->research('impulse_drive', 3)
        ->level('shipyard', 4)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->session()
        ->expectQueued('colony_ship');
});

test('an account whose colony slots are exhausted plans no colony', function (): void {
    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->session()
        ->expectNoWork(AiWorkKind::Colonize);
});
