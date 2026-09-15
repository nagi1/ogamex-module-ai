<?php

use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The canonical opening in every published guide starts with a solar plant and not with a mine, and
 * this is why: the host multiplies a planet's whole production by the energy it can cover, so the
 * capacity is built before the mine that would outdraw it rather than after the mines have stalled.
 *
 * The expectations are computed from the host's own catalogue throughout -- which objects produce
 * energy, what they cost -- so a module that named its own buildings would fail its own tests instead
 * of satisfying them.
 */
test('a fresh account builds capacity before the mine that would outdraw it', function (): void {
    energyProfile($this->currentUserId);
    $this->planetAddResources(energyPlenty());
    energyStoraged();

    $plan = app(QueueableBuildingPlanner::class)->plan($this->currentUserId);
    $machineName = ObjectService::getObjectById((int) $plan?->buildingId)->machine_name;

    expect($plan?->reason)->toBe('energy:' . $machineName)
        ->and(energyProductionOf($this->currentUserId, $machineName))->toBeGreaterThan(0);
});

test('a planet that covers its next upgrade plans its own way', function (): void {
    energyProfile($this->currentUserId);
    $this->planetAddResources(energyPlenty());
    energyStoraged();
    $this->planetSetObjectLevel('solar_plant', 20);

    $plan = app(QueueableBuildingPlanner::class)->plan($this->currentUserId);

    expect($plan?->reason)->not->toStartWith('energy:');
});

// A planet already mining at a loss is short whatever its next upgrade would do, so the rule has to
// notice the balance it is in as well as the one it would fall into.
test('a planet already in deficit wants capacity', function (): void {
    energyProfile($this->currentUserId);
    $this->planetAddResources(energyPlenty());
    energyStoraged();
    $this->planetSetObjectLevel('metal_mine', 20);
    $this->planetSetObjectLevel('crystal_mine', 18);

    $plan = app(QueueableBuildingPlanner::class)->plan($this->currentUserId);

    expect($plan?->reason)->toStartWith('energy:');
});

test('the capacity offered is the cheapest the host has, so a solar plant comes before a fusion reactor', function (): void {
    energyProfile($this->currentUserId);
    $this->planetAddResources(energyPlenty());
    energyStoraged();

    $plan = app(QueueableBuildingPlanner::class)->plan($this->currentUserId);

    expect(ObjectService::getObjectById((int) $plan?->buildingId)->machine_name)->toBe('solar_plant');
});

function energyProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 5_000 + $playerId,
        'enabled' => true,
    ]);
}

function energyPlenty(): Resources
{
    return app()->makeWith(Resources::class, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]);
}

/** Enough warehouse that a funded balance is not "about to overflow", so a test can ask about energy alone. */
function energyStoraged(): void
{
    test()->planetSetObjectLevel('metal_store', 10);
    test()->planetSetObjectLevel('crystal_store', 10);
    test()->planetSetObjectLevel('deuterium_store', 10);
}

/** What the host's own production calculation says a building puts out at its next level. */
function energyProductionOf(int $playerId, string $machineName): float
{
    $planets = app(PlayerServiceFactory::class)->make($playerId, true)->planets->all();
    $planet = array_values($planets)[0];

    return (float) $planet->getObjectProduction($machineName, $planet->getObjectLevel($machineName) + 1)->energy->get();
}
