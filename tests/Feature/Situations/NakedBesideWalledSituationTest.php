<?php

use Modules\AI\Domain\Decision\DefenseNeedEvaluator;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Planet;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// QUAL-003's situation (invariant:NAKED_BESIDE_WALLED, aspect:shipyard). The cohort read found accounts
// with planets at zero defence while a sibling held a real wall: the whole wall was raised on the planet
// with the most to lose and the bare siblings were never reached. One login has to give the bare planet
// the wall order the account already holds elsewhere, in the host's own yard.
test('a login gives the bare sibling the wall the account already holds elsewhere', function (): void {
    $situation = Situation::of($this)
        ->levelEveryPlanet('robot_factory', 2)
        ->levelEveryPlanet('shipyard', 4)
        ->defence('rocket_launcher', 20)
        ->stockEveryPlanet(20_000_000, 10_000_000, 5_000_000)
        ->session();

    $bare = Planet::query()->where('user_id', $this->currentUserId)->where('planet_type', 1)
        ->whereKeyNot($this->currentPlanetId)->value('id');
    $planet = app(PlanetServiceFactory::class)->make((int) $bare, true);

    $situation->expectWork(AiWorkKind::QueueUnits);

    // A short build may already be complete by the time the session's own clock has advanced to every
    // intent, so the wall counts whether it is standing or still paid for in the yard.
    expect(app(DefenseNeedEvaluator::class)->standingUnits($planet))->toBeGreaterThan(0, 'expected the wall on the bare sibling; ' . $situation->account());
});

// The bound on the other side: a planet that already holds a wall is not the account's next wall order,
// so one login serves the bare sibling instead of stacking yet another batch on the walled planet.
test('a login does not spend its wall order on the planet that already holds one', function (): void {
    $situation = Situation::of($this)
        ->levelEveryPlanet('robot_factory', 2)
        ->levelEveryPlanet('shipyard', 4)
        ->defence('rocket_launcher', 20)
        ->stockEveryPlanet(20_000_000, 10_000_000, 5_000_000)
        ->session();

    $home = app(PlanetServiceFactory::class)->make($this->currentPlanetId, true);
    $situation->expectWork(AiWorkKind::QueueUnits);

    expect(app(DefenseNeedEvaluator::class)->standingUnits($home))->toBe(20, 'expected the walled planet untouched while its sibling is bare; ' . $situation->account());
});
