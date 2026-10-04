<?php

use Modules\AI\Domain\Decision\QueueableColony;
use Modules\AI\Domain\Decision\QueueableColonyPlanner;
use Modules\AI\Domain\Decision\QueueableExpedition;
use Modules\AI\Domain\Decision\QueueableExpeditionPlanner;
use Modules\AI\Domain\Decision\QueueableSpy;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\DebrisField;
use OGame\Models\FleetMission;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// DISPATCH_REFUSALS: a refused dispatch is not a mission, so nothing a planner read recorded it and the
// same target or the same origin was offered again on the next login (measured 4 Oct 2026: one account
// tried one target 21 times in six hours). RaidPlanner already reads that memory; every other planner
// that offers a target or an origin has to read it too. Debris beside the planet with recyclers on hand
// is the story: an ordinary session sends the harvest, unless the gate just refused that field or that
// body.

test('an ordinary session recycles the field beside the planet', function (): void {
    DebrisField::query()->delete();
    FleetMission::query()->delete();

    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('recycler', 2)
        ->debris(40_000, 20_000)
        ->session()
        ->expectWork(AiWorkKind::Recycle);
});

// At the bound: the gate refused that field a moment ago, in the host's own words ("This mission is not
// possible."), which is a reason the module never names — so the field has to be remembered from the
// coordinate the refusal carried, not from a reason string.
test('a field the gate just refused is not the harvest this session sends', function (): void {
    DebrisField::query()->delete();
    FleetMission::query()->delete();

    $home = $this->planetService->getPlanetCoordinates();

    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('recycler', 2)
        ->debris(40_000, 20_000)
        ->refusedDispatch(galaxy: (int) $home->galaxy, system: (int) $home->system, position: (int) $home->position, reason: 'This mission is not possible.')
        ->session()
        ->expectNoWork(AiWorkKind::Recycle);
});

// At the bound, from the other side: the body the recyclers stand on was refused, so it is not the
// origin this session harvests from — and the account's other body cannot build a harvest hull.
test('a body the gate just refused is not the origin this session flies from', function (): void {
    DebrisField::query()->delete();
    FleetMission::query()->delete();

    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('recycler', 2)
        ->debris(40_000, 20_000)
        ->refusedDispatch($this->currentPlanetId, reason: 'source_short_at_dispatch')
        ->session()
        ->expectNoWork(AiWorkKind::Recycle);
});

// Past the bound: the same refusal from outside the cooling (a night later, tanks refilled) is forgotten,
// as a player who has had time to refuel tries again.
test('a refusal outside the window no longer stops the harvest', function (): void {
    DebrisField::query()->delete();
    FleetMission::query()->delete();

    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('recycler', 2)
        ->debris(40_000, 20_000)
        ->refusedDispatch($this->currentPlanetId, reason: 'source_short_at_dispatch', minutesAgo: 720)
        ->session()
        ->expectWork(AiWorkKind::Recycle);
});

test('the spy planner does not offer a neighbour the gate just refused', function (): void {
    $situation = Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('espionage_probe', 3)
        ->inactiveNeighbour();

    $planner = app(QueueableSpyPlanner::class);
    $offered = $planner->plan($this->currentUserId);

    expect($offered)->toBeInstanceOf(QueueableSpy::class);

    $situation->refusedDispatch(galaxy: $offered->targetGalaxy, system: $offered->targetSystem, position: $offered->targetPosition, reason: 'This mission is not possible.');

    $after = $planner->plan($this->currentUserId);

    expect([$after?->targetGalaxy, $after?->targetSystem, $after?->targetPosition])
        ->not->toBe([$offered->targetGalaxy, $offered->targetSystem, $offered->targetPosition]);
});

test('the expedition planner does not send from a body the gate just refused', function (): void {
    $situation = Situation::of($this)
        ->research('astrophysics', 3)
        ->research('combustion_drive', 6)
        ->ships('large_cargo', 10)
        ->resources(500_000, 500_000, 500_000);

    $planner = app(QueueableExpeditionPlanner::class);
    $offered = $planner->plan($this->currentUserId);

    expect($offered)->toBeInstanceOf(QueueableExpedition::class);

    $situation->refusedDispatch($offered->planetId, reason: 'no_disposable_fleet');

    expect($planner->plan($this->currentUserId)?->planetId)->not->toBe($offered->planetId);
});

test('the colony planner does not build from a body the gate just refused', function (): void {
    $situation = Situation::of($this)
        ->research('astrophysics', 4)
        ->research('impulse_drive', 3)
        ->ships('colony_ship', 1)
        ->stockEveryPlanet(1_000_000, 1_000_000, 1_000_000);

    $planner = app(QueueableColonyPlanner::class);
    $offered = $planner->plan($this->currentUserId);

    expect($offered)->toBeInstanceOf(QueueableColony::class);

    $situation->refusedDispatch($offered->planetId, reason: 'Not enough units on the planet to send the fleet. Units required: colony_ship');

    expect($planner->plan($this->currentUserId)?->planetId)->not->toBe($offered->planetId);
});
