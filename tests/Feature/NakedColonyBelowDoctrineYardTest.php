<?php

use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiDefenseDoctrine;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// QUAL-003: a planet at zero defence beside a walled sibling. The doctrine's own shape answered
// nothing for a young colony: Big Gun Heavy's ratio names Light Laser first (shipyard two) while the
// Rocket Launcher it could build is the anchor and never in the ratio, so the composition returned
// null and the colony stayed naked however often its session offered the wall. The smallest defence
// the host offers is the floor such a planet takes.
test('a naked colony whose yard is below every unit the doctrine names is still walled', function (): void {
    belowYardProfile($this->currentUserId);

    // Homeworld: a wall standing, so the account is past its opening and the naked planet takes the
    // floor ahead of the habits. Its yard is high, so only the colony can be the one left bare.
    $this->planetSetObjectLevel('robot_factory', 2);
    $this->planetSetObjectLevel('shipyard', 4);
    $this->planetSetObjectLevel('solar_plant', 30);
    $this->planetAddResources(new Resources(5_000_000_000, 5_000_000_000, 5_000_000_000));
    $this->planetAddUnit('light_laser', 60);

    // Colony: yard level one and plenty to pay, so the host's Rocket Launcher is buildable here while
    // every unit Big Gun Heavy names waits on a shipyard of two or more.
    $colony = belowYardColony($this->secondPlanetService, 1);

    foreach ([$this->planetService, $colony] as $planet) {
        $planet->updateResourceProductionStats();
        $planet->updateResourceStorageStats();
    }

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);
    $machineName = $plan === null ? '' : ObjectService::getObjectById($plan->unitId)->machine_name;

    expect($plan)->not->toBeNull()
        ->and($plan->reason)->toContain('role:defense:standing')
        ->and($plan->planetId)->toBe($colony->getPlanetId())
        ->and($machineName)->toBe('rocket_launcher')
        ->and($plan->amount)->toBeGreaterThanOrEqual(1);
});

// The bound the fallback keeps: once the yard can build what the doctrine names, the doctrine's own
// unit is chosen and the host's cheapest is not substituted for it.
test('a colony whose yard reaches the doctrine unit takes the doctrine unit', function (): void {
    belowYardProfile($this->currentUserId);

    $this->planetSetObjectLevel('shipyard', 4);
    $this->planetAddResources(new Resources(5_000_000_000, 5_000_000_000, 5_000_000_000));
    $this->planetAddUnit('light_laser', 60);
    $this->playerSetResearchLevel('laser_technology', 3);

    $colony = belowYardColony($this->secondPlanetService, 2);

    foreach ([$this->planetService, $colony] as $planet) {
        $planet->updateResourceProductionStats();
        $planet->updateResourceStorageStats();
    }

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->not->toBeNull()
        ->and($plan->planetId)->toBe($colony->getPlanetId())
        ->and(ObjectService::getObjectById($plan->unitId)->machine_name)->toBe('light_laser');
});

/** A miner whose belief builds Big Gun Heavy: the shape whose ratio opens on a unit a colony's yard cannot reach. */
function belowYardProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'defense_doctrine' => AiDefenseDoctrine::RocketPlasma,
        'random_seed' => 26_000 + $playerId,
        'enabled' => true,
    ]);
}

/** The bare colony beside the wall: a yard at the given level, a purse, and never a defence unit. */
function belowYardColony(?PlanetService $colony, int $shipyardLevel): PlanetService
{
    $colony = $colony ?? throw new LogicException('the account must own a colony for this story.');

    $colony->setObjectLevel(ObjectService::getObjectByMachineName('shipyard')->id, $shipyardLevel, true);
    $colony->addResources(new Resources(5_000_000_000, 5_000_000_000, 5_000_000_000));

    return $colony;
}
