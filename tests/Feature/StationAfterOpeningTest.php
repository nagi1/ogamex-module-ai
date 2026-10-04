<?php

use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// COVER_OBJECTS: the station project's turn was a price comparison against the cheapest station the
// planet does not hold -- and the cheapest one it does not hold is the station behind a graph the planet
// has not climbed yet, so a planet whose mines had long passed the opening stayed on mine levels and no
// account in the cohort ever held the nanite factory. A planet whose mines have passed the level the
// station's own requirement graph names is past that opening: the pile its warehouse cannot hold goes to
// the station it waits on, which is the dearest thing a settled planet buys.
//
// The mine level is the boundary. The same planet with its mines still inside the opening keeps mining,
// because a level-eight mine is the small purchase whatever the station behind it costs.

/** A settled planet whose every other station stands, so the station left to hold is the nanite factory. */
function stationSpendPlanet(Situation $situation, int $mineLevel): Situation
{
    return $situation
        ->resources(2_000_000, 1_000_000, 300_000)
        ->level('metal_mine', $mineLevel)
        ->level('crystal_mine', $mineLevel)
        ->level('deuterium_synthesizer', $mineLevel)
        ->level('solar_plant', 30)
        ->level('metal_store', 3)
        ->level('crystal_store', 3)
        ->level('deuterium_store', 3)
        ->level('robot_factory', 10)
        ->level('shipyard', 2)
        ->level('research_lab', 11)
        ->level('alliance_depot', 1)
        ->level('missile_silo', 1)
        ->level('space_dock', 1)
        ->research('computer_technology', 10)
        ->defence('rocket_launcher', 300);
}

test('a planet whose mines are past the station graph spends its full warehouse on the station project', function (): void {
    stationSpendPlanet(Situation::of($this), 14)->session()->expectQueued('nano_factory');
});

test('a planet whose mines are still inside the opening keeps mining', function (): void {
    $situation = stationSpendPlanet(Situation::of($this), 8)->session();

    expect(in_array('nano_factory', $situation->queued(), true))->toBeFalse(
        'expected the cheap mine to keep the build slot, but ' . $situation->account(),
    );
});
