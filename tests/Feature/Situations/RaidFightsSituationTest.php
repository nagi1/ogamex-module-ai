<?php

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// LIFE-001: an experienced player also hits a target that fights back when the odds and the haul are
// good — the battle then has combat rounds and leaves debris, instead of pillaging one more empty
// planet. A fleet account stands a combat fleet beside an inactive neighbour that fields a real wall,
// holds a fresh report on it, and must fly an Attack. The defenceless neighbour is the zero case of
// the same rule: still raided, just without a fight.

test('a fleet account flies a fight against a defended neighbour', function (): void {
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
        ->expectWork(AiWorkKind::Raid)
        ->expectMission('Attack');
});

test('a defenceless neighbour is still raided', function (): void {
    Situation::of($this)
        ->archetype(AiArchetype::Fleeter)
        ->research('combustion_drive', 6)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('large_cargo', 20)
        ->ships('light_fighter', 50)
        ->colony()
        ->inactiveNeighbour(daysQuiet: 8, metal: 400_000, crystal: 200_000)
        ->spyReport()
        ->session()
        ->expectWork(AiWorkKind::Raid)
        ->expectMission('Attack');
});

test('a neighbour that keeps a fleet is fought', function (): void {
    Situation::of($this)
        ->archetype(AiArchetype::Fleeter)
        ->research('combustion_drive', 6)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('large_cargo', 20)
        ->ships('light_fighter', 200)
        ->ships('cruiser', 20)
        ->colony()
        ->inactiveNeighbour(daysQuiet: 8, metal: 400_000, crystal: 200_000)
        ->neighbourUnits('light_fighter', 40)
        ->spyReport()
        ->session()
        ->expectWork(AiWorkKind::Raid)
        ->expectMission('Attack');
});
