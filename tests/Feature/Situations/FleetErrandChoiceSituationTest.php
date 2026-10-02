<?php

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// ARB-001's fleet errand, aspect: fleet_breadth. The fleet does more than one thing: when the
// situation offers a raid the fleet raids, and the expedition takes the login only when there is
// nothing better to farm. A fixed table would send the same errand either way.
test('a report worth raiding takes the login from the expedition', function (): void {
    Situation::of($this)
        ->archetype(AiArchetype::Fleeter)
        ->research('astrophysics', 3)
        ->research('combustion_drive', 6)
        ->ships('large_cargo', 20)
        ->ships('light_fighter', 10)
        ->ships('espionage_probe', 1)
        ->resources(200_000, 200_000, 500_000)
        ->inactiveNeighbour(daysQuiet: 10, metal: 400_000, crystal: 200_000)
        ->spyReport()
        ->session()
        ->expectWork(AiWorkKind::Raid);
});
