<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// FLEET-003's fast proof. A hostile fleet is due inside the reaction lead and the account has ships to
// lose: an experienced player moves the fleet. The whole session must reach that decision.
test('a hostile fleet due soon and ships on hand is a fleet save the session makes', function (): void {
    Situation::of($this)
        ->resources(100_000, 100_000, 100_000)
        ->ships('large_cargo', 5)
        ->hostileFleet(seconds: 150)
        ->session()
        ->expectWork(AiWorkKind::FleetSave);
});

test('with no ships there is nothing to save', function (): void {
    Situation::of($this)->hostileFleet(seconds: 150)->session()->expectNoWork(AiWorkKind::FleetSave);
});
