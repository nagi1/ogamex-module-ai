<?php

use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\QueueableUnit;
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
// floor the behaviour file states was already offered to a planet holding nothing, but the economy's
// saving veto dropped it right there: a planet short of its next mine is saving, which is what a young
// colony always is, so it never got the wall while the homeworld, past its steps, took every order.
test('a colony still short of its next mine is walled, not left to the walled homeworld', function (): void {
    $profile = nakedWallProfile($this->currentUserId);

    // Homeworld: the doctrine's anchor already standing, mines that produce, and a purse in which no
    // step is out of reach -- so the saving veto answers nothing here and only the naked planet can win.
    $this->planetSetObjectLevel('robot_factory', 2);
    $this->planetSetObjectLevel('shipyard', 4);
    $this->planetSetObjectLevel('solar_plant', 30);
    $this->planetSetObjectLevel('metal_mine', 20);
    $this->planetSetObjectLevel('crystal_mine', 18);
    $this->planetSetObjectLevel('deuterium_synthesizer', 12);
    $this->planetAddResources(new Resources(5_000_000_000, 5_000_000_000, 5_000_000_000));
    $this->planetAddUnit('light_laser', 60);
    nakedWallOpeningFleet($this->planetService);

    // Colony: never given a defence unit, and its next mine is far beyond what it holds, so the
    // building planner reports it as saving -- the state the veto used to answer with "no wall".
    $colony = nakedWallSavingColony($this->secondPlanetService);

    // Levels written straight to the model leave the cached production and energy columns behind, and
    // the planner reads those from its own fresh service.
    foreach ([$this->planetService, $colony] as $planet) {
        $planet->updateResourceProductionStats();
        $planet->updateResourceStorageStats();
    }

    expect(app(QueueableBuildingPlanner::class)->savingFor($colony, $profile))->not->toBeNull();

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toContain('role:defense:standing')
        ->and($plan->planetId)->toBe($colony->getPlanetId());
});

function nakedWallProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 23_000 + $playerId,
        'enabled' => true,
    ]);
}

/** A colony that mines hard but cannot pay for its next step: the naked, saving planet. */
function nakedWallSavingColony(?PlanetService $colony): PlanetService
{
    $colony = $colony ?? throw new LogicException('the account must own a second planet for this story.');

    foreach (['robot_factory' => 2, 'shipyard' => 4, 'solar_plant' => 30, 'metal_mine' => 18, 'crystal_mine' => 16, 'deuterium_synthesizer' => 10] as $machineName => $level) {
        $colony->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, $level, true);
    }

    // Enough to buy a first wall, nowhere near the next mine.
    $colony->addResources(new Resources(300_000, 300_000, 0));
    nakedWallOpeningFleet($colony);

    return $colony;
}

/** The opening roles satisfied, so nothing but the wall is left for the planner to answer. */
function nakedWallOpeningFleet(PlanetService $planet): void
{
    $planet->addUnit('small_cargo', 1);
    $planet->addUnit('colony_ship', 1);
    $planet->addUnit('espionage_probe', 1);
}
