<?php

use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// QUAL-003: the account's whole wall stood on one planet while a sibling stayed at zero defence. The
// sibling was already offered the file's floor, but only after the planner's per-planet habits: while
// the account's cargo kept chasing the next rich report, that habit answered every session first and
// the naked planet was never reached. A sibling that already holds a wall is the account past its
// opening, so the bare planet's floor is answered before those habits.
test('a naked colony outranks the homeworld habit once a sibling holds a wall', function (): void {
    habitPriorityProfile($this->currentUserId);

    // Homeworld: the wall already standing and the probe still missing, so the account's scouting
    // habit wants the next order here -- the order that used to be placed before the naked planet.
    $this->planetSetObjectLevel('robot_factory', 2);
    $this->planetSetObjectLevel('shipyard', 4);
    $this->planetSetObjectLevel('solar_plant', 30);
    $this->planetSetObjectLevel('metal_mine', 20);
    $this->planetSetObjectLevel('crystal_mine', 18);
    $this->planetSetObjectLevel('deuterium_synthesizer', 12);
    $this->planetAddResources(new Resources(5_000_000_000, 5_000_000_000, 5_000_000_000));
    $this->planetAddUnit('light_laser', 60);
    $this->playerSetResearchLevel('combustion_drive', 3);
    $this->playerSetResearchLevel('impulse_drive', 3);
    $this->playerSetResearchLevel('espionage_technology', 2);
    $this->playerSetResearchLevel('astrophysics', 4);
    $this->planetAddUnit('small_cargo', 1);
    $this->planetAddUnit('colony_ship', 1);

    // Colony: the fleet that founded it, probe included, and never a defence unit.
    $colony = habitPriorityColony($this->secondPlanetService);

    // Levels written straight to the model leave the cached production and energy columns behind, and
    // the planner reads those from its own fresh service.
    foreach ([$this->planetService, $colony] as $planet) {
        $planet->updateResourceProductionStats();
        $planet->updateResourceStorageStats();
    }

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->not->toBeNull()
        ->and($plan->reason)->toContain('role:defense:standing')
        ->and($plan->planetId)->toBe($colony->getPlanetId());
});

// The bound the gate keeps: while no wall stands anywhere the account is still in its opening and its
// habits keep their turn -- so this rule moves only the naked-beside-walled case.
test('a fresh account still opens with the cargo habit while no wall stands anywhere', function (): void {
    habitPriorityProfile($this->currentUserId);

    // One planet, no defence anywhere on the account: the opening cargo hull is still what it wants,
    // so the floor must not be hoisted ahead of the opening just because this planet is bare.
    $this->planetSetObjectLevel('shipyard', 2);
    $this->planetAddResources(new Resources(5_000_000_000, 5_000_000_000, 5_000_000_000));
    $this->playerSetResearchLevel('combustion_drive', 2);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->not->toBeNull()
        ->and($plan->reason)->toBe('role:cargo:small_cargo');
});

function habitPriorityProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 24_000 + $playerId,
        'enabled' => true,
    ]);
}

/** A colony that mines and can pay for a first wall, but has never been given a defence unit. */
function habitPriorityColony(?PlanetService $colony): PlanetService
{
    $colony = $colony ?? throw new LogicException('the account must own a second planet for this story.');

    foreach (['robot_factory' => 2, 'shipyard' => 4, 'solar_plant' => 30, 'metal_mine' => 18, 'crystal_mine' => 16, 'deuterium_synthesizer' => 10] as $machineName => $level) {
        $colony->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, $level, true);
    }

    $colony->addResources(new Resources(300_000, 300_000, 0));
    habitPriorityOpeningFleet($colony);

    return $colony;
}

/** The opening roles satisfied, so the habits below them are what the planner is left with. */
function habitPriorityOpeningFleet(PlanetService $planet): void
{
    $planet->addUnit('small_cargo', 1);
    $planet->addUnit('colony_ship', 1);
    $planet->addUnit('espionage_probe', 1);
}
