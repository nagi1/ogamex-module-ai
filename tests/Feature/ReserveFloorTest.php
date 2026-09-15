<?php

use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\ReserveFloor;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * A build that consumes the last resources prevents the next save, commitment and research step, so a
 * player reserves first: keep a fraction of the planet's storage, reduced by what production refills
 * while saving (SP5). The floor is arithmetic over host quotes only -- storage, production, the saving
 * horizon -- so nothing here names a game object, and an extension that changes storage or production
 * changes the floor with no module edit.
 */
test('the reserve floor is the published buffer reduced by the production that refills it', function (): void {
    reserveProfile($this->currentUserId);
    $this->planetSetObjectLevel('metal_store', 10);
    $this->planetSetObjectLevel('crystal_store', 10);
    $this->planetSetObjectLevel('deuterium_store', 10);
    $this->planetSetObjectLevel('solar_plant', 20);

    $planet = reservePlanet($this->currentUserId);
    $floor = app(ReserveFloor::class)->floor($planet, ReserveFloor::ECONOMY_HOURS);

    expect($floor->metal->get())
        ->toBe(max(0.0, $planet->metalStorage()->get() * ReserveFloor::BUFFER - $planet->getMetalProductionPerHour() * ReserveFloor::ECONOMY_HOURS))
        ->and($floor->crystal->get())
        ->toBe(max(0.0, $planet->crystalStorage()->get() * ReserveFloor::BUFFER - $planet->getCrystalProductionPerHour() * ReserveFloor::ECONOMY_HOURS))
        ->and($floor->deuterium->get())
        ->toBe(max(0.0, $planet->deuteriumStorage()->get() * ReserveFloor::BUFFER - $planet->getDeuteriumProductionPerHour() * ReserveFloor::ECONOMY_HOURS));
});

test('a floor never goes negative, whatever the production', function (): void {
    reserveProfile($this->currentUserId);
    // Deep production against a tiny warehouse: the refill dwarfs the buffer, so the floor is zero.
    $this->planetSetObjectLevel('solar_plant', 30);
    $this->planetSetObjectLevel('metal_mine', 45);
    $this->planetSetObjectLevel('crystal_mine', 45);
    $this->planetSetObjectLevel('deuterium_synthesizer', 45);

    $floor = app(ReserveFloor::class)->floor(reservePlanet($this->currentUserId), ReserveFloor::ECONOMY_HOURS);

    expect($floor->metal->get())->toBe(0.0)
        ->and($floor->crystal->get())->toBe(0.0)
        ->and($floor->deuterium->get())->toBe(0.0);
});

/**
 * The planner is what the reserve guards: a planet that can pay the price but not the price plus the
 * floor must fall through to nothing, and one that can pay both must build. The scenario keeps every
 * other pass quiet -- deep mines never repay so the economy has nothing, and no solar plant means the
 * cheapest capacity is the only answer -- so the boundary is read off the first candidate alone.
 */
test('the planner refuses a build the price alone covers and accepts it once the floor is met', function (): void {
    reserveProfile($this->currentUserId);
    reserveDeepPlanet();

    $planet = reservePlanet($this->currentUserId);
    $price = ObjectService::getObjectPrice('solar_plant', $planet);
    $floor = app(ReserveFloor::class)->floor($planet, ReserveFloor::ECONOMY_HOURS);

    // The price alone: the host would accept it, but the reserve will not.
    $this->planetAddResources(priceOnly($price));
    expect(app(QueueableBuildingPlanner::class)->plan($this->currentUserId))->toBeNull();

    // The price plus the floor: the same account now builds.
    $this->planetAddResources($floor);
    $plan = app(QueueableBuildingPlanner::class)->plan($this->currentUserId);

    expect($plan)->not->toBeNull()
        ->and(ObjectService::getObjectById((int) $plan?->buildingId)->machine_name)->toBe('solar_plant');
});

function reserveProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 7_000 + $playerId,
        'enabled' => true,
    ]);
}

/**
 * Deep mines and a full warehouse with no solar plant: production throttles to nothing so the economy
 * has no candidate, and the planet is energy-short so the cheapest capacity is the only answer.
 *
 * The levels stay inside the planet's own field cap (the host refuses any building once a planet's
 * fields are used up, so a deeper fixture would not be a planet the game could be in).
 */
function reserveDeepPlanet(): void
{
    foreach (['metal_mine' => 35, 'crystal_mine' => 30, 'deuterium_synthesizer' => 25] as $machineName => $level) {
        test()->planetSetObjectLevel($machineName, $level);
    }
    foreach (['metal_store' => 20, 'crystal_store' => 20, 'deuterium_store' => 20] as $machineName => $level) {
        test()->planetSetObjectLevel($machineName, $level);
    }
}

/** The planet as the planner reads it: freshly recomputed production and capacity. */
function reservePlanet(int $playerId): PlanetService
{
    $planet = array_values(app(PlayerServiceFactory::class)->make($playerId, true)->planets->all())[0];
    $planet->updateResources(false);
    $planet->updateResourceProductionStats(false);
    $planet->updateResourceStorageStats(false);

    return $planet;
}

/** The price with no floor on top, as the smallest funded balance that covers the price alone. */
function priceOnly(Resources $price): Resources
{
    return new Resources($price->metal->get(), $price->crystal->get(), $price->deuterium->get());
}
