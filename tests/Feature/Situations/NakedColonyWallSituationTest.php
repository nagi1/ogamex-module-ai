<?php

use Modules\AI\Domain\Decision\DefenseNeedEvaluator;
use Modules\AI\Tests\Support\Situation;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Planet;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// QUAL-003's live shape (invariant:NAKED_BESIDE_WALLED, aspect:shipyard): the invariant is read on the
// live cohort, so this planted story is the fast reproduction of it. The cohort read found an account
// holding a wall on one planet while a young colony stood at zero defence: the wall waits on a shipyard the
// colony does not have yet, and the account kept spending its yard elsewhere. An account whose sibling
// already holds a wall has to bring the shipyard and then the wall to the bare colony, instead of leaving
// it naked for as long as it plays.
test('an account whose sibling holds a wall reaches the colony that has no shipyard yet', function (): void {
    $before = Planet::query()->where('user_id', $this->currentUserId)->pluck('id')->all();

    $situation = Situation::of($this)
        ->level('robot_factory', 2)
        ->level('shipyard', 4)
        ->defence('rocket_launcher', 30)
        ->colony()
        ->stockEveryPlanet(20_000_000, 10_000_000, 5_000_000)
        ->sessions(8, 30);

    $colonyId = (int) Planet::query()->where('user_id', $this->currentUserId)->where('planet_type', 1)
        ->whereNotIn('id', $before)->value('id');
    $colony = app(PlanetServiceFactory::class)->make($colonyId, true);

    expect(app(DefenseNeedEvaluator::class)->standingUnits($colony))->toBeGreaterThan(0, 'expected the wall on the colony that started without a shipyard; ' . $situation->account());
});
