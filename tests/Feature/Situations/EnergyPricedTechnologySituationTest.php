<?php

use Modules\AI\Domain\Decision\EconomyUpgrades;
use Modules\AI\Domain\Decision\EnergyCapacity;
use Modules\AI\Tests\Support\Situation;
use OGame\Factories\PlayerServiceFactory;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// COVER-OBJECTS-001 (aspect: economy): the capacity a technology priced in energy asks for is raised
// while that technology is still next, and not one login after the account already holds it. The host
// keeps a technology's level on the player, so reading it from the planet answered zero for every
// technology: the energy that price charges was demanded on every login of every mature planet, the
// one build slot went to yet another plant, and the station the plan had lined up behind that slot
// (the nano factory, the terraformer) never got its turn.

test('an account that already holds the energy-priced technology raises no capacity for it', function (): void {
    $situation = Situation::of($this)
        ->stockEveryPlanet(60_000, 60_000, 60_000)
        ->levelEveryPlanet('metal_mine', 7)
        ->levelEveryPlanet('crystal_mine', 5)
        ->levelEveryPlanet('deuterium_synthesizer', 3)
        ->levelEveryPlanet('solar_plant', 9)
        ->levelEveryPlanet('metal_store', 10)
        ->levelEveryPlanet('crystal_store', 10)
        ->levelEveryPlanet('deuterium_store', 10)
        ->levelEveryPlanet('robot_factory', 3)
        ->levelEveryPlanet('shipyard', 3)
        ->levelEveryPlanet('research_lab', 12)
        ->research('combustion_drive', 2)
        ->research('energy_technology', 1)
        ->research('graviton_technology', 1);

    $planet = array_values(app(PlayerServiceFactory::class)->make($this->currentUserId, true)->planets->all())[0];
    $planet->updateResources(false);
    $planet->updateResourceProductionStats(false);

    // The technology is not next any more, and the planet is not short of anything else.
    expect(app(EconomyUpgrades::class)->energyGap($planet))->toBe(0.0, 'the energy the technology charges while it is owned')
        ->and(app(EnergyCapacity::class)->shortfall($planet))->toBe(0.0, 'the planet\'s own shortfall')
        ->and(app(EnergyCapacity::class)->pending($planet))->toBe([]);

    $situation->session();

    // The login still spends its build slot -- on the mine that repays it, not on power it already has.
    expect($situation->queued())->not->toBeEmpty()
        ->and(in_array('solar_plant', $situation->queued(), true))->toBeFalse($situation->account())
        ->and(in_array('fusion_plant', $situation->queued(), true))->toBeFalse($situation->account());
});

test('past the level the technology is useful for, the planet is asked for no energy at all', function (): void {
    Situation::of($this)
        ->level('metal_mine', 7)
        ->level('solar_plant', 9)
        ->level('research_lab', 12)
        ->research('graviton_technology', 2);

    $planet = array_values(app(PlayerServiceFactory::class)->make($this->currentUserId, true)->planets->all())[0];
    $planet->updateResources(false);
    $planet->updateResourceProductionStats(false);

    expect(app(EconomyUpgrades::class)->energyGap($planet))->toBe(0.0)
        ->and(app(EnergyCapacity::class)->pending($planet))->toBe([]);
});

test('a technology that is not yet in reach raises no capacity either', function (): void {
    Situation::of($this)
        ->level('metal_mine', 7)
        ->level('solar_plant', 9)
        ->level('research_lab', 11)
        ->research('graviton_technology', 0);

    $planet = array_values(app(PlayerServiceFactory::class)->make($this->currentUserId, true)->planets->all())[0];
    $planet->updateResources(false);
    $planet->updateResourceProductionStats(false);

    expect(app(EconomyUpgrades::class)->energyGap($planet))->toBe(0.0)
        ->and(app(EnergyCapacity::class)->pending($planet))->toBe([]);
});
