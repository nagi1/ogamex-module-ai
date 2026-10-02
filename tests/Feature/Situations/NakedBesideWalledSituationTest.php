<?php

use Modules\AI\Domain\Decision\DefenseNeedEvaluator;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\BuildingQueue;
use OGame\Models\Planet;
use OGame\Services\ObjectService;
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

// The invariant counts the account, not the planet (player 51: one planet holding 1,496 units, others
// naked), so a full day of the account's own logins must leave no sibling at zero defence.
test('an account whose homeworld holds a wall leaves no sibling naked', function (): void {
    $situation = Situation::of($this)
        ->resources(50_000_000, 50_000_000, 50_000_000)
        ->level('robot_factory', 2)
        ->level('shipyard', 4)
        ->ships('small_cargo', 10)
        ->ships('light_laser', 60)
        ->colony()
        ->stockEveryPlanet(50_000_000, 50_000_000, 50_000_000);

    $situation->day();

    expect(nakedPlanets($this->currentUserId))->toBe([], 'these planets stay at zero defence; ' . $situation->account());
});

// A walled homeworld moves the bare sibling before the sibling's own habits, so one login already
// turns to the yard the sibling needs rather than leaving it for a later day. The sibling's yard is
// below the wall's unit, so the order it takes is the facility the wall waits on.
test('a login beside a walled homeworld turns to the bare sibling', function (): void {
    $situation = Situation::of($this)
        ->resources(50_000_000, 50_000_000, 50_000_000)
        ->level('robot_factory', 2)
        ->level('shipyard', 4)
        ->ships('small_cargo', 10)
        ->ships('light_laser', 60)
        ->colony()
        ->stockEveryPlanet(50_000_000, 50_000_000, 50_000_000);

    $situation->session();

    foreach (nakedPlanets($this->currentUserId) as $planetId) {
        $ordered = BuildingQueue::query()->where('planet_id', $planetId)->where('canceled', 0)->exists();
        expect($ordered)->toBeTrue('the bare sibling got no order this login; ' . $situation->account());
    }
});

// The zero case of the rule: the invariant names a naked planet *beside a walled sibling*. A lone
// planet has no sibling, so nothing hoists the wall for it -- it takes the ordinary floor.
test('a one-planet account is walled without a sibling to point at', function (): void {
    $situation = Situation::of($this)
        ->resources(50_000_000, 50_000_000, 50_000_000)
        ->level('robot_factory', 2)
        ->level('shipyard', 4)
        ->ships('small_cargo', 10)
        ->stockEveryPlanet(50_000_000, 50_000_000, 50_000_000);

    $situation->day();

    expect(standingDefence($this->currentUserId))->toBeGreaterThan(0, 'the lone planet stayed at zero defence; ' . $situation->account());
});

// The bound at zero: with nothing to build with, the account queues no wall at all -- the host's own
// affordability gate is not bypassed to make a naked planet look walled.
test('an account that can pay for nothing queues no wall', function (): void {
    $situation = Situation::of($this)
        ->level('robot_factory', 2)
        ->level('shipyard', 4)
        ->ships('small_cargo', 10)
        ->ships('light_laser', 60)
        ->colony();

    $situation->session();

    $defence = array_map(static fn ($object): string => $object->machine_name, ObjectService::getDefenseObjects());

    expect($situation->work())->not->toBeEmpty()
        ->and(array_intersect($defence, $situation->queued()))->toBe([], 'a wall was queued without the resources to pay for it; ' . $situation->account());
});

/** @return list<int> the account's planets (moons excluded) that hold no defence, ordered or built */
function nakedPlanets(int $playerId): array
{
    return Planet::query()->where('user_id', $playerId)->where('planet_type', 1)->pluck('id')
        ->map(static fn ($id): int => (int) $id)
        ->filter(static function (int $id): bool {
            $planet = app(PlanetServiceFactory::class)->make($id, true);

            return $planet === null || app(DefenseNeedEvaluator::class)->standingUnits($planet) === 0;
        })->values()->all();
}

/** The defence units the account stands on its planets. */
function standingDefence(int $playerId): int
{
    $total = 0;

    foreach (Planet::query()->where('user_id', $playerId)->where('planet_type', 1)->pluck('id') as $planetId) {
        $planet = app(PlanetServiceFactory::class)->make((int) $planetId, true);
        $total += $planet === null ? 0 : $planet->getDefenseUnits()->getAmount();
    }

    return $total;
}
