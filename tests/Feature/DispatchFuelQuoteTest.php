<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// FUEL-ROOT-001: "Not enough resources on the planet to send the fleet" is the host's answer to a flight
// the origin cannot fuel, and every dispatching planner asks that one quote before it offers. A plan the
// gate then refuses for an empty tank is not a plan: the account decided to fly something the game would
// not launch, and offering it again on the next login is the repeat the cohort invariant counts.

// At the bound, from the side that flies: an ally just raided is helped from a body that holds the fuel.
test('a raided ally is helped from a body that can fuel the flight', function (): void {
    Situation::of($this)
        ->ships('light_fighter', 6)
        ->resources(0, 0, 1_000_000)
        ->allyUnderAttack()
        ->session()
        ->expectCandidate('Defend');
});

// At zero: the same body holds the combat hulls but no deuterium, so the flight cannot be paid for and
// the defence is never offered — the gate is asked before the plan, not after it.
test('a raided ally is not helped from a body that cannot fuel the flight', function (): void {
    $situation = Situation::of($this)
        ->ships('light_fighter', 6)
        ->allyUnderAttack()
        ->session();

    expect($situation->candidates())->not->toBeEmpty()
        ->and($situation->candidates())->not->toContain('Defend')
        ->and($situation->work())->not->toContain(AiWorkKind::Defend);
});

// The colony ship is a flight too: a free slot is offered only when a body can pay to fly the ship there.
test('a colony ship with an empty tank claims no slot', function (): void {
    Situation::of($this)
        ->research('astrophysics', 4)
        ->research('impulse_drive', 3)
        ->ships('colony_ship', 1)
        ->session()
        ->expectNoWork(AiWorkKind::Colonize);
});

// Past the bound: the fuel is on the planet, so the slot the ship can reach is claimed as before.
test('a colony ship the account can fuel claims the free slot', function (): void {
    Situation::of($this)
        ->research('astrophysics', 4)
        ->research('impulse_drive', 3)
        ->ships('colony_ship', 1)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->session()
        ->expectWork(AiWorkKind::Colonize);
});
