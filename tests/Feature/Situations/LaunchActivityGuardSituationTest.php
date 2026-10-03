<?php

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// LIFE_FIGHTS / RAID-010: the activity dot a raider reads at launch says the owner is at the
// keyboard, and an owner at the keyboard answers it by moving what moves. A fleet parked at home
// can be fleetsaved out of the way, so the raid waits; a wall and a hold of resources stay where
// they are, so the raid flies and the neighbour fights. daysQuiet: 0 is the neighbour whose owner
// logged in just now with its planet stamp as fresh, which is the dot.

test('a raid flies against a playing neighbour that holds a wall but no fleet', function (): void {
    Situation::of($this)
        ->archetype(AiArchetype::Fleeter)
        ->research('combustion_drive', 6)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('large_cargo', 20)
        ->ships('light_fighter', 50)
        ->ships('cruiser', 20)
        ->colony()
        ->inactiveNeighbour(daysQuiet: 0, metal: 400_000, crystal: 200_000)
        ->neighbourUnits('rocket_launcher', 10)
        ->spyReport()
        ->session()
        ->expectWork(AiWorkKind::Raid)
        ->expectMission('Attack');
});

test('a raid waits while the neighbour the fight is for can still move its fleet', function (): void {
    $situation = Situation::of($this)
        ->archetype(AiArchetype::Fleeter)
        ->research('combustion_drive', 6)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('large_cargo', 20)
        ->ships('light_fighter', 50)
        ->ships('cruiser', 20)
        ->colony()
        ->inactiveNeighbour(daysQuiet: 0, metal: 400_000, crystal: 200_000)
        ->neighbourUnits('light_fighter', 5)
        ->spyReport()
        ->session();

    expect($situation->work())->toContain(AiWorkKind::Raid)
        ->and($situation->missions())->not->toContain('Attack')
        ->and(implode(' ', $situation->refused()))->toContain('target_active_at_dispatch');
});

test('the same fleet on a neighbour whose owner is away is still raided', function (): void {
    Situation::of($this)
        ->archetype(AiArchetype::Fleeter)
        ->research('combustion_drive', 6)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('large_cargo', 20)
        ->ships('light_fighter', 50)
        ->ships('cruiser', 20)
        ->colony()
        ->inactiveNeighbour(daysQuiet: 8, metal: 400_000, crystal: 200_000)
        ->neighbourUnits('light_fighter', 5)
        ->spyReport()
        ->session()
        ->expectMission('Attack');
});
