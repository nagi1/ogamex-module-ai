<?php

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// ATK-001 / DEF-33 / ARB-001's fast proof, for a Fleeter (raiding is its taste), for the second half of the raid story (aspect: raids). A
// player holding a fresh report on an undefended inactive with 600k stock and cargo ships on hand
// raids it. InactiveNeighbourSessionSituationTest proves the first half: spying the neighbour.
test('a report on an undefended inactive with stock and cargo ships on hand is a raid', function (): void {
    Situation::of($this)
        ->archetype(AiArchetype::Fleeter)
        ->research('combustion_drive', 6)
        ->ships('large_cargo', 20)
        ->ships('light_fighter', 10)
        ->resources(200_000, 200_000, 500_000)
        ->inactiveNeighbour(daysQuiet: 10, metal: 400_000, crystal: 200_000)
        ->spyReport()
        ->session()
        ->expectWork(AiWorkKind::Raid)
        ->expectMission('Attack');
});

test('a report on a defended inactive is not raided with cargo ships alone', function (): void {
    Situation::of($this)
        ->archetype(AiArchetype::Fleeter)
        ->research('combustion_drive', 6)
        ->ships('large_cargo', 20)
        ->resources(200_000, 200_000, 500_000)
        ->inactiveNeighbour(daysQuiet: 10)
        ->neighbourUnits('rocket_launcher', 40)
        ->spyReport()
        ->session()
        ->expectNoMission('Attack');
});
