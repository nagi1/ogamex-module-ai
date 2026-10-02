<?php

use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use OGame\Services\UnitQueueService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// QUAL-003: the cohort read left one planet at zero defence beside a sibling holding a wall. The account
// places one wall order per login, and a planet whose first wall is paid for but not yet built still read
// as "holds nothing", so the same planet took every order and the naked sibling was never reached. A wall
// already in the yard is standing, so the next login turns to the sibling.
test('a naked sibling takes the wall order once the first planet\'s wall is already in the yard', function (): void {
    bareWallProfile($this->currentUserId);

    $home = $this->planetService;
    $this->planetSetObjectLevel('robot_factory', 2);
    $this->planetSetObjectLevel('shipyard', 4);
    $this->planetSetObjectLevel('solar_plant', 30);
    $this->planetAddResources(new Resources(50_000_000, 50_000_000, 50_000_000));
    bareWallOpeningFleet($home);

    $colony = bareWallBareColony($this->secondPlanetService);

    foreach ([$home, $colony] as $planet) {
        $planet->updateResourceProductionStats();
        $planet->updateResourceStorageStats();
    }

    // With no wall anywhere the account places its first order on one of the two planets.
    $first = app(QueueableUnitPlanner::class)->plan($this->currentUserId);
    expect($first)->not->toBeNull()
        ->and($first->reason)->toContain('role:defense:standing');

    $served = $first->planetId;
    $bare = $served === $home->getPlanetId() ? $colony : $home;

    // The yard takes the order the way the QueueUnits executor places it: paid for, not yet built.
    app(UnitQueueService::class)->add(
        app(PlanetServiceFactory::class)->make($served, true),
        ObjectService::getObjectByMachineName('rocket_launcher')->id,
        1,
    );

    $second = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($second)->not->toBeNull()
        ->and($second->reason)->toContain('role:defense:standing')
        ->and($second->planetId)->toBe($bare->getPlanetId());
});

function bareWallProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 31_000 + $playerId,
        'enabled' => true,
    ]);
}

/** A planet whose opening roles are satisfied, so the standing wall is what the planner is left with. */
function bareWallOpeningFleet(PlanetService $planet): void
{
    $planet->addUnit('small_cargo', 1);
    $planet->addUnit('colony_ship', 1);
    $planet->addUnit('espionage_probe', 1);
}

/**
 * A colony that mines nothing and stands no defence: the planet the cohort read found naked beside a
 * walled sibling. Its yard can build the host's cheapest defence, so only the order spread can leave it
 * bare. Speed one is the universe the read was taken on; at the test case's economy_speed the host's base
 * income alone lifts the colony above the file's floor.
 */
function bareWallBareColony(?PlanetService $colony): PlanetService
{
    $colony = $colony ?? throw new LogicException('the account must own a second planet for this story.');

    resolve(SettingsService::class)->set('economy_speed', 1);

    foreach (['metal_mine', 'crystal_mine', 'deuterium_synthesizer'] as $machineName) {
        $colony->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, 0, true);
    }

    $colony->setObjectLevel(ObjectService::getObjectByMachineName('solar_plant')->id, 30, true);
    $colony->setObjectLevel(ObjectService::getObjectByMachineName('shipyard')->id, 4, true);
    $colony->updateResourceProductionStats();
    $colony->updateResourceStorageStats();
    $colony->addResources(new Resources(50_000_000, 50_000_000, 50_000_000));
    bareWallOpeningFleet($colony);

    return $colony;
}
