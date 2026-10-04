<?php

use Modules\AI\Domain\Decision\EconomyUpgrades;
use Modules\AI\Domain\Decision\EnergyCapacity;
use Modules\AI\Domain\Decision\FacilityChain;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// COVER-*: the objects nobody owned (nano factory, terraformer, moon stations, the graviton technology and the
// deathstar behind it) were unreachable for a structural reason each, not for want of resources. Each test
// asks the real planners the question a stuck account would, with the host's own catalogue as the answer key.

function ambitionProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 7_000 + $playerId,
        'enabled' => true,
    ]);
}

test('a station is a goal the chain climbs to, so its missing prerequisites become steps', function (): void {
    ambitionProfile($this->currentUserId);
    $this->planetAddResources(app()->makeWith(Resources::class, ['metal' => 50_000_000, 'crystal' => 50_000_000, 'deuterium' => 50_000_000]));
    foreach (['research_lab', 'shipyard', 'missile_silo'] as $facility) {
        $this->planetSetObjectLevel($facility, 12);
    }
    foreach (ObjectService::getResearchObjects() as $research) {
        $this->playerSetResearchLevel($research->machine_name, 20);
    }
    $this->planetSetObjectLevel('robot_factory', 3);

    $planet = array_values(app(PlayerServiceFactory::class)->make($this->currentUserId, true)->planets->all())[0];
    $stations = array_filter(ObjectService::getStationObjects(), fn ($station) => ObjectService::getRecursiveRequirements($station->machine_name) !== []
        && ObjectService::objectValidPlanetType($station->machine_name, $planet));
    $reasons = array_map(fn ($step) => $step->reason, app(FacilityChain::class)->pending($planet));

    // Every station the planet type can hold wants at least one prerequisite the catalogue names; the chain offers
    // the ones it is short of, so a deeper station (the nano factory wants robotics 10) is climbed to.
    expect($stations)->not->toBeEmpty()
        ->and(implode(' ', $reasons))->toContain('robot_factory');
});

test('a moon is not a naked sibling: its own station comes before a wall prerequisite', function (): void {
    ambitionProfile($this->currentUserId);
    $this->planetAddResources(app()->makeWith(Resources::class, ['metal' => 5_000_000, 'crystal' => 5_000_000, 'deuterium' => 5_000_000]));
    $this->planetAddUnit('rocket_launcher', 10);

    $homeworld = array_values(app(PlayerServiceFactory::class)->make($this->currentUserId, true)->planets->all())[0];
    $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($homeworld, 2_000_000, 20);
    $moon->addResources(app()->makeWith(Resources::class, ['metal' => 5_000_000, 'crystal' => 5_000_000, 'deuterium' => 5_000_000]));

    $profile = AiProfile::query()->where('player_id', $this->currentUserId)->firstOrFail();
    $passes = app(QueueableBuildingPlanner::class)->passes($profile);
    $moonView = app(PlayerServiceFactory::class)->make($this->currentUserId, true)->planets->getById($moon->getPlanetId());

    expect($passes['wall']($moonView))->toBe([])
        ->and($passes['doctrine']($moonView))->toBe([])
        ->and(array_map(fn ($step) => $step->reason, $passes['routine']($moonView))[0] ?? '')->toContain('moon-station');
});

test('a technology priced in energy makes the planet raise its capacity until it can pay', function (): void {
    ambitionProfile($this->currentUserId);
    $this->planetAddResources(app()->makeWith(Resources::class, ['metal' => 900_000_000, 'crystal' => 900_000_000, 'deuterium' => 900_000_000]));
    foreach (['metal_store', 'crystal_store', 'deuterium_store'] as $store) {
        $this->planetSetObjectLevel($store, 20);
    }
    $this->planetSetObjectLevel('research_lab', 12);

    $planet = array_values(app(PlayerServiceFactory::class)->make($this->currentUserId, true)->planets->all())[0];
    $energyPriced = array_filter(ObjectService::getResearchObjects(), fn ($research) => ObjectService::getObjectPrice($research->machine_name, $planet)->energy->get() > 0
        && ObjectService::objectRequirementsMet($research->machine_name, $planet));

    expect($energyPriced)->not->toBeEmpty()
        ->and(app(EconomyUpgrades::class)->energyGap($planet))->toBeGreaterThan(0.0)
        ->and(app(EnergyCapacity::class)->shortfall($planet))->toBeGreaterThan(0.0);
});

// A standing need (power, wall) paid for in the yard is not asked for again: overlapping sessions each plan the
// same need before the first order lands, which is how one planet came to hold ten times the power it draws.
test('an order for power or wall that is already in the yard is a repeat, and an ordinary order is not', function (): void {
    ambitionProfile($this->currentUserId);
    $planetId = array_values(app(PlayerServiceFactory::class)->make($this->currentUserId, true)->planets->all())[0]->getPlanetId();
    $satellite = ObjectService::getUnitObjectByMachineName('solar_satellite');
    $cruiser = ObjectService::getUnitObjectByMachineName('cruiser');
    $planner = app(Modules\AI\Domain\Decision\QueueableUnitPlanner::class);

    $order = fn ($unit, string $reason) => new Modules\AI\Domain\Decision\QueueableUnit($planetId, $unit->id, 5, $reason);
    expect($planner->repeatsYardOrder($order($satellite, 'role:energy:solar_satellite')))->toBeFalse();

    (new OGame\Models\UnitQueue())->forceFill([
        'planet_id' => $planetId, 'object_id' => $satellite->id, 'object_amount' => 5, 'time_duration' => 10,
        'time_start' => time(), 'time_end' => time() + 10, 'time_progress' => 0, 'object_amount_progress' => 0,
        'metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'processed' => 0,
    ])->save();

    expect($planner->repeatsYardOrder($order($satellite, 'role:energy:solar_satellite')))->toBeTrue()
        ->and($planner->repeatsYardOrder($order($satellite, 'role:capital:solar_satellite')))->toBeFalse()
        ->and($planner->repeatsYardOrder($order($cruiser, 'role:energy:cruiser')))->toBeFalse();
});

// The collector's crawler stops paying where the host says the mines cannot use more: a handful a planet left
// every account far below it (57 crawlers in a cohort whose mines could use hundreds).
test('a collector keeps ordering crawlers up to what its mines can use, and stops there', function (): void {
    ambitionProfile($this->currentUserId);
    OGame\Models\User::query()->whereKey($this->currentUserId)->update(['character_class' => OGame\Enums\CharacterClass::COLLECTOR->value]);
    $this->planetAddResources(app()->makeWith(Resources::class, ['metal' => 900_000_000, 'crystal' => 900_000_000, 'deuterium' => 900_000_000]));
    foreach (['shipyard' => 12, 'robot_factory' => 2, 'metal_mine' => 20, 'crystal_mine' => 20, 'deuterium_synthesizer' => 20, 'solar_plant' => 40] as $machineName => $level) {
        $this->planetSetObjectLevel($machineName, $level);
    }
    foreach (ObjectService::getResearchObjects() as $research) {
        $this->playerSetResearchLevel($research->machine_name, 12);
    }

    // The class-ship role is one of a dozen the planner ranks; it is asked alone here, as the unit planner asks it.
    $classShip = function () {
        $player = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $planet = array_values($player->planets->all())[0];
        $method = new ReflectionMethod(Modules\AI\Domain\Decision\QueueableUnitPlanner::class, 'classShip');

        return [$planet, $method->invoke(app(Modules\AI\Domain\Decision\QueueableUnitPlanner::class), $player, $planet)];
    };

    [$planet, $plan] = $classShip();
    $usable = $planet->getUsableUnitCap('crawler');

    expect($usable)->toBe(480)
        ->and($plan?->reason)->toBe('role:class:crawler')
        ->and($plan->amount)->toBeGreaterThan(5)->and($plan->amount)->toBeLessThanOrEqual($usable);

    $this->planetAddUnit('crawler', $usable);

    expect($classShip()[1])->toBeNull();
});

// MISSILES-001: a silo holds ten slots a level and a missile takes two, so the stock a planet keeps is
// the silo's own interplanetary capacity — five a level, per resources/behavior/def-ipm.yaml — with no
// floor of its own: without a silo there is no stock, and a deeper silo stores proportionally more.
test('the interplanetary missiles a planet keeps are what its silo stores', function (): void {
    ambitionProfile($this->currentUserId);
    $standing = function () {
        $planet = array_values(app(PlayerServiceFactory::class)->make($this->currentUserId, true)->planets->all())[0];

        return (new ReflectionMethod(Modules\AI\Domain\Decision\QueueableUnitPlanner::class, 'missileStanding'))
            ->invoke(app(Modules\AI\Domain\Decision\QueueableUnitPlanner::class), $planet);
    };

    expect($standing())->toBe(0);

    $this->planetSetObjectLevel('missile_silo', 4);

    expect($standing())->toBe(20);

    $this->planetSetObjectLevel('missile_silo', 8);

    expect($standing())->toBe(40);

    $this->planetSetObjectLevel('missile_silo', 12);

    expect($standing())->toBe(60);
});
