<?php

use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

/**
 * Every planet the account owns mines deep enough that the next level is out of reach of its stock,
 * with the energy and storage that keep the earlier passes out of the way: the whole account is saving.
 */
function savingsGoalPlanet(Situation $situation): Situation
{
    return $situation
        ->levelEveryPlanet('metal_mine', 13)
        ->levelEveryPlanet('crystal_mine', 13)
        ->levelEveryPlanet('deuterium_synthesizer', 13)
        ->levelEveryPlanet('solar_plant', 30)
        ->levelEveryPlanet('metal_store', 20)
        ->levelEveryPlanet('crystal_store', 20)
        ->levelEveryPlanet('deuterium_store', 20)
        ->stockEveryPlanet(5_000, 2_000, 1_000);
}

// PERS-007: a goal saver names the step it is saving for -- here the next mine, out of reach of the
// 5.000 metal it holds -- and keeps the pile against it, so a cheaper facility that is affordable
// waits instead of eating what the goal needs.
test('a goal saver short of its goal holds the pile instead of buying a cheaper step', function (): void {
    $situation = savingsGoalPlanet(Situation::of($this)->goalSaver())->session();

    expect($situation->queued())->toBeEmpty('expected the goal saver to hold its pile, but ' . $situation->account());
});

// The same balance without the strategy is the spender the decision doctrine describes: it buys the
// step it can pay for. The strategy decides how the pile is spent, never whether it is spent.
test('an account with no saving strategy buys the affordable step with the same balance', function (): void {
    savingsGoalPlanet(Situation::of($this))->session()->expectBuildingQueueBusy();
});

// The reserve is not a freeze: once the pile reaches the goal, the goal is bought and the account
// spends again, so a goal saver is never left idle by its own saving.
test('a goal saver whose pile reaches the goal buys it', function (): void {
    savingsGoalPlanet(Situation::of($this)->goalSaver())
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->session()
        ->expectBuildingQueueBusy();
});

// With nothing to save for -- every step the planner offers is affordable at a glance -- the goal
// saver spends like anyone else.
test('a funded goal saver with nothing to save for still queues a building', function (): void {
    Situation::of($this)->goalSaver()->resources(1_000_000, 1_000_000, 1_000_000)->session()->expectBuildingQueueBusy();
});
