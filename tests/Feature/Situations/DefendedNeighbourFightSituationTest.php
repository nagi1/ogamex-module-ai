<?php

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// LIFE-001: a raid is flown for the fight, not only for a free farm. The neighbour stands a
// defence the account can beat, so the raid it flies lands on a defender and produces combat
// rounds and debris, instead of pillaging one more empty planet.
test('a fleet account raids a defended neighbour instead of only empty farms', function (): void {
    Situation::of($this)
        ->archetype(AiArchetype::Fleeter)
        ->research('combustion_drive', 6)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('large_cargo', 20)
        ->ships('light_fighter', 50)
        ->ships('cruiser', 20)
        ->colony()
        ->inactiveNeighbour(daysQuiet: 8, metal: 400_000, crystal: 200_000)
        ->neighbourUnits('rocket_launcher', 10)
        ->spyReport()
        ->session()
        ->expectMission('Attack');
});
