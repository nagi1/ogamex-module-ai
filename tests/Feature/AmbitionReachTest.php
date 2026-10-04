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
