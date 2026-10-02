<?php

use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

/**
 * Every planet the account owns mines deeper than the stock planted here can pay for, with the energy
 * and storage that keep the earlier passes out of the way: each planet is saving for its next step.
 */
function holdGoalPlanet(Situation $situation): Situation
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

// PERS-007 against ECON-001's IDLE_QUEUES. The reserve is the planet's own goal, so one planet short
// of its next step holds its pile -- that is the strategy working -- while a sibling that already
// holds enough for its own next step still spends: stockpile_strategy decides how a pile is spent,
// never whether the account plays.
test('a goal saver holds the planet short of its goal and still builds on the funded sibling', function (): void {
    holdGoalPlanet(Situation::of($this)->goalSaver()->colony())
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->session()
        ->expectBuildingQueueBusy();
});
