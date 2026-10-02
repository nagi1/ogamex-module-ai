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

// QUAL-003: the cohort read kept finding a planet at zero defence beside a sibling's wall. A doctrine
// whose ratio names only units the planet's yard cannot build yet -- a big-gun doctrine names nothing
// below a level-two shipyard and a laser lab, while the rocket launcher it could build is its anchor and
// not in the ratio at all -- left the wall order unplaced: the composition answered nothing and the
// planet stayed naked. The host's own cheapest defence unit it can build is the wall instead, exactly as
// the opening pass already does for a bare planet.

test('a planet whose doctrine names nothing it can build is walled with the host\'s cheapest unit', function (): void {
    stalledWallProfile($this->currentUserId, AiDefenseDoctrine::RocketPlasma);

    $home = $this->planetService;
    $this->planetSetObjectLevel('robot_factory', 2);
    $this->planetSetObjectLevel('shipyard', 1);
    $this->planetSetObjectLevel('solar_plant', 30);
    $this->planetSetObjectLevel('metal_mine', 0);
    $this->planetSetObjectLevel('crystal_mine', 0);
    $this->planetSetObjectLevel('deuterium_synthesizer', 0);
    $this->planetAddResources(new Resources(5_000_000, 5_000_000, 5_000_000));
    stalledWallOpeningFleet($home);
    stalledWallPrepare($home);
    stalledWallQuietSibling($this->secondPlanetService);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->not->toBeNull()
        ->and($plan->reason)->toBe('role:defense:standing:rocket_launcher')
        ->and($plan->planetId)->toBe($home->getPlanetId());
});

// At the bound: a wall that covers what the planet stands to lose is the wall the evaluator says it
// wants, so the fallback above must not order more on top of it.
test('a planet whose standing wall covers its exposure gets no further wall order', function (): void {
    stalledWallProfile($this->currentUserId, AiDefenseDoctrine::RocketPlasma);

    $home = $this->planetService;
    $this->planetSetObjectLevel('robot_factory', 2);
    $this->planetSetObjectLevel('shipyard', 1);
    $this->planetSetObjectLevel('solar_plant', 30);
    $this->planetSetObjectLevel('metal_mine', 0);
    $this->planetSetObjectLevel('crystal_mine', 0);
    $this->planetSetObjectLevel('deuterium_synthesizer', 0);
    $this->planetAddResources(new Resources(5_000_000, 5_000_000, 5_000_000));
    stalledWallOpeningFleet($home);
    stalledWallPrepare($home);
    $home->addUnit('rocket_launcher', 500);
    stalledWallQuietSibling($this->secondPlanetService);

    expect(app(QueueableUnitPlanner::class)->plan($this->currentUserId))->toBeNull();
});

// At zero: a yard that can build no defence unit at all has nothing to order, however exposed the planet
// is; raising that yard is the building chain's work, not a unit order the host would refuse.
test('a planet whose yard can build nothing at all gets no wall order', function (): void {
    stalledWallProfile($this->currentUserId, AiDefenseDoctrine::RocketPlasma);

    $home = $this->planetService;
    $this->planetSetObjectLevel('robot_factory', 2);
    $this->planetSetObjectLevel('shipyard', 0);
    $this->planetSetObjectLevel('solar_plant', 30);
    $this->planetSetObjectLevel('metal_mine', 0);
    $this->planetSetObjectLevel('crystal_mine', 0);
    $this->planetSetObjectLevel('deuterium_synthesizer', 0);
    $this->planetAddResources(new Resources(5_000_000, 5_000_000, 5_000_000));
    stalledWallOpeningFleet($home);
    stalledWallPrepare($home);
    stalledWallQuietSibling($this->secondPlanetService);

    expect(app(QueueableUnitPlanner::class)->plan($this->currentUserId))->toBeNull();
});

function stalledWallProfile(int $playerId, AiDefenseDoctrine $doctrine): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Turtle,
        'skill_band' => AiSkillBand::Standard,
        'defense_doctrine' => $doctrine,
        'random_seed' => 61_000 + $playerId,
        'enabled' => true,
    ]);
}

/**
 * The account's other planet, which stands nothing, produces nothing and can queue nothing: it is never
 * the planet a wall order can go to, so the story is about the planet the test names.
 */
function stalledWallQuietSibling(?PlanetService $planet): void
{
    $planet = $planet ?? throw new LogicException('the account must own a second planet for this story.');

    foreach (['metal_mine', 'crystal_mine', 'deuterium_synthesizer', 'shipyard'] as $machineName) {
        $planet->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, 0, true);
    }

    stalledWallPrepare($planet);
}

/**
 * The host keeps a planet's energy balance in stored columns, so a level set straight on the model leaves
 * the balance the planners read at the level the planet had before it: the yard then reads a power
 * shortfall that is not there and answers it before the wall. Recomputing it is what the host does on a
 * real level change.
 */
function stalledWallPrepare(PlanetService $planet): void
{
    $planet->updateResourceProductionStats();
    $planet->updateResourceStorageStats();
    $planet->reloadPlanet();
}

/** The opening roles satisfied, so nothing but the wall is left for the planner to answer. */
function stalledWallOpeningFleet(PlanetService $planet): void
{
    $planet->addUnit('small_cargo', 1);
    $planet->addUnit('colony_ship', 1);
    $planet->addUnit('espionage_probe', 1);
}
