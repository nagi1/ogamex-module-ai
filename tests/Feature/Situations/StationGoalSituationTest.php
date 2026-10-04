<?php

use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Models\AiProfile;
use Modules\AI\Tests\Support\Situation;
use OGame\Factories\PlayerServiceFactory;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// COVER-OBJECTS-001: a station the host's catalogue offers is a goal in its own right. The chain serves
// one ambition at a time, and on an account that owns a ship that ambition is its next war hull, so the
// station nobody else waits on was skipped as "producible" and the facilities only it wants were never
// asked for -- the host names no other object that wants a level-ten robotics factory, so the whole climb
// to the nano factory (and the terraformer behind it) stayed unreachable. Each story below plants the
// account at one side of that boundary and reads what it queues.

/**
 * A settled account whose mines no longer repay their next level, with every station but the nano
 * factory standing and every facility it waits on already up. The terraformer is what a deep planet
 * built to keep its fields.
 */
function stationGoalMature(Situation $situation): Situation
{
    return $situation
        ->resources(20_000_000, 10_000_000, 2_000_000)
        ->level('metal_mine', 28)
        ->level('crystal_mine', 28)
        ->level('deuterium_synthesizer', 28)
        ->level('solar_plant', 40)
        ->level('metal_store', 14)
        ->level('crystal_store', 14)
        ->level('deuterium_store', 14)
        ->level('terraformer', 25)
        ->level('shipyard', 2)
        ->level('research_lab', 11)
        ->level('alliance_depot', 1)
        ->level('missile_silo', 1)
        ->level('space_dock', 1)
        ->research('computer_technology', 10);
}

/**
 * Every planet of the account stands a wall, so the login writes no unit order: the host refuses a
 * yard upgrade while ships or defence are in production, and a login that orders a wall first would
 * spend its station order on that refusal instead.
 */
function stationGoalWall(int $playerId): void
{
    foreach (app(PlayerServiceFactory::class)->make($playerId, true)->planets->all() as $planet) {
        $planet->addUnit('rocket_launcher', 300);
    }
}

/** Every step the plan offers this planet, in the planner's own order, so a failure names them. */
function stationGoalSteps(PlanetService $planet): string
{
    $profile = AiProfile::query()->where('player_id', $planet->getPlayer()?->getId())->firstOrFail();
    $planet->updateResources(false);
    $planet->updateResourceProductionStats(false);
    $planet->updateResourceStorageStats(false);

    return implode(', ', array_map(
        static fn ($candidate): string => $candidate->reason,
        app(QueueableBuildingPlanner::class)->passes($profile)['routine']($planet),
    ));
}

test('a settled account with nothing left to mine buys the station its prerequisites have unlocked', function (): void {
    $situation = stationGoalMature(Situation::of($this))->level('robot_factory', 10);
    stationGoalWall($this->currentUserId);

    $routine = stationGoalSteps($this->planetService);
    expect($routine)->toStartWith('station:nano_factory', 'the planet offered: ' . $routine);

    $situation->session()->expectQueued('nano_factory');
});

test('below the level the station waits on, that prerequisite is the step', function (): void {
    $situation = stationGoalMature(Situation::of($this))->level('robot_factory', 9);
    stationGoalWall($this->currentUserId);

    $routine = stationGoalSteps($this->planetService);
    expect($routine)->toStartWith('chain:robot_factory', 'the planet offered: ' . $routine)
        ->and($routine)->toContain('station:nano_factory');

    $situation->session()->expectQueued('robot_factory');
});

test('a planet whose mines still repay keeps mining instead of taking on a station', function (): void {
    stationGoalMature(Situation::of($this))->level('robot_factory', 10)->level('metal_mine', 8);

    $routine = stationGoalSteps($this->planetService);
    expect($routine)->not->toContain('station:', 'the planet offered: ' . $routine);
});
