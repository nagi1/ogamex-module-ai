<?php

use Modules\AI\Tests\Support\Situation;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// COVER-OBJECTS-001: the fields are finite and the host refuses every field-consuming building once
// they are used up -- the object that adds fields included, and the station it waits on needs a field
// of its own. A cohort whose economy is told only what a step pays back therefore mines its planets to
// the last field and can never hold the nanite factory, the terraformer behind it, the laboratory the
// energy-priced technology waits on or the hull behind that. The station project keeps the fields it
// still needs, so the economy steps stop before them.
//
// The stories run a mature planet: mines deep enough that the station project has its turn, every other
// station standing, the technology the nanite factory waits on already researched, and a warehouse
// already full -- the pass that spends a capped planet's surplus is the one that would eat the fields.

/**
 * A settled planet holding every station but the nanite factory and the terraformer behind it, with the
 * wall a login would otherwise spend its yard on already standing (a yard upgrade is refused while units
 * are in production, which would spend the station's turn on that refusal).
 */
function stationFieldsProject(Situation $situation): Situation
{
    return $situation
        ->resources(40_000_000, 2_000_000, 2_000_000)
        ->level('metal_mine', 28)
        ->level('crystal_mine', 28)
        ->level('deuterium_synthesizer', 28)
        ->level('solar_plant', 30)
        ->level('metal_store', 8)
        ->level('crystal_store', 7)
        ->level('deuterium_store', 7)
        ->level('robot_factory', 10)
        ->level('shipyard', 2)
        ->level('research_lab', 11)
        ->level('alliance_depot', 1)
        ->level('missile_silo', 1)
        ->level('space_dock', 1)
        ->research('computer_technology', 10)
        ->defence('rocket_launcher', 300);
}

test('a planet down to its last fields keeps them for the station its fields are for', function (): void {
    $situation = stationFieldsProject(Situation::of($this))->fieldsLeft(2);

    $situation->session()->expectQueued('nano_factory');
});

test('a planet whose mines are still the small purchase keeps spending its last fields', function (): void {
    // The same last two fields and the same full warehouse, but the mines are cheap, so the station
    // project has no turn: nothing is reserved and the planet keeps building as before.
    $situation = stationFieldsProject(Situation::of($this))
        ->level('metal_mine', 8)
        ->level('crystal_mine', 8)
        ->level('deuterium_synthesizer', 8)
        ->fieldsLeft(2)
        ->session();

    $spent = array_filter(
        $situation->queued(),
        static fn (string $machineName): bool => ObjectService::getObjectByMachineName($machineName)->consumesPlanetField,
    );

    expect(in_array('nano_factory', $situation->queued(), true))->toBeFalse('expected the mines to keep the fields, but ' . $situation->account())
        ->and($spent)->not->toBeEmpty('expected the planet to keep building, but ' . $situation->account());
});
