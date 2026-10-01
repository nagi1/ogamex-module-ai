<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// The fast proof settles a planet with a colony ship already on hand. These are the two edges
// around it: the account whose ship is still to be built, and the account with no slot left.

test('a free colony slot is claimed even before the colony ship is built', function (): void {
    Situation::of($this)
        ->research('astrophysics', 4)
        ->research('impulse_drive', 3)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->session()
        ->expectWork(AiWorkKind::Colonize);
});

test('an account whose colony slots are exhausted plans no colony', function (): void {
    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->session()
        ->expectNoWork(AiWorkKind::Colonize);
});
