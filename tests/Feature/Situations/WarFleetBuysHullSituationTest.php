<?php

use Modules\AI\Tests\Support\Situation;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// LIFE-002: a login whose decision was about something else still spends what the economy leaves on the
// strongest hull the yard can build, so accounts own a war fleet and not only cargo. Which hull that is
// comes from the host's own catalogue, so nothing below names one.

/**
 * The dearest military hull the host lets this planet build: the account's own answer, computed from the
 * catalogue the planner reads, never a name written in the test.
 */
function warFleetDearestHull(PlanetService $planet): ?string
{
    $dearest = null;
    $dearestPrice = 0.0;

    foreach (ObjectService::getMilitaryShipObjects() as $hull) {
        if (! ObjectService::objectRequirementsMet($hull->machine_name, $planet)
            || ! ObjectService::objectCharacterClassMet($hull->machine_name, $planet)) {
            continue;
        }

        $price = ObjectService::getObjectPrice($hull->machine_name, $planet);
        $sum = $price->metal->get() + $price->crystal->get() + $price->deuterium->get();
        if ($sum > $dearestPrice) {
            $dearest = $hull->machine_name;
            $dearestPrice = $sum;
        }
    }

    return $dearest;
}

/** The planet the test case is logged in on, read through the case's own protected fixture. */
function warFleetPlanet(object $test): PlanetService
{
    return (fn (): PlanetService => $this->planetService)->call($test);
}

test('a login that chose something else still spends the surplus on the strongest hull the yard builds', function (): void {
    $situation = Situation::of($this)
        ->resources(50_000_000, 30_000_000, 20_000_000)
        ->level('solar_plant', 30)
        ->level('robot_factory', 10)
        ->level('shipyard', 8)
        ->research('combustion_drive', 6)
        ->research('impulse_drive', 6)
        ->research('hyperspace_drive', 6)
        ->research('hyperspace_technology', 6)
        ->ships('large_cargo', 5)
        ->ships('espionage_probe', 1)
        ->ships('colony_ship', 1)
        ->defence('rocket_launcher', 400);

    // Read after the yard stands: the dearest hull is the catalogue's answer to what this planet can build.
    $dearest = warFleetDearestHull(warFleetPlanet($this));
    expect($dearest)->not->toBeNull();

    $situation->session()->expectQueued($dearest);
});

// The live shape: the plan the schedule is handed is the first role some planet wants, and that is
// almost never the fleet -- a probe the planet lacks, a colony ship, a wall. Here the account owns no
// probe, so the planner's answer is the probe and the fleet never reaches the plan; the login still has
// to buy the hull, which is what the schedule asks the planner for directly.
test('a login whose plan is spent on a probe still buys the hull the yard builds', function (): void {
    $situation = Situation::of($this)
        ->resources(50_000_000, 30_000_000, 20_000_000)
        ->level('solar_plant', 30)
        ->level('robot_factory', 10)
        ->level('shipyard', 8)
        ->research('combustion_drive', 6)
        ->research('impulse_drive', 6)
        ->research('hyperspace_drive', 6)
        ->research('hyperspace_technology', 6)
        ->ships('large_cargo', 5)
        ->ships('colony_ship', 1)
        ->defence('rocket_launcher', 400);

    $dearest = warFleetDearestHull(warFleetPlanet($this));
    expect($dearest)->not->toBeNull();

    $situation->session()->expectQueued($dearest);
});

test('an account that cannot pay for a hull orders none, however busy the login', function (): void {
    $situation = Situation::of($this)
        ->resources(400, 400, 0)
        ->level('solar_plant', 30)
        ->level('robot_factory', 10)
        ->level('shipyard', 8)
        ->research('combustion_drive', 6)
        ->research('impulse_drive', 6)
        ->research('hyperspace_drive', 6)
        ->research('hyperspace_technology', 6)
        ->ships('large_cargo', 5)
        ->ships('espionage_probe', 1)
        ->ships('colony_ship', 1)
        ->defence('rocket_launcher', 400);

    $dearest = warFleetDearestHull(warFleetPlanet($this));
    expect($dearest)->not->toBeNull();

    $situation->session();

    expect(in_array($dearest, $situation->queued(), true))->toBeFalse('expected no hull order, but the account queued: ' . $situation->account());
});
