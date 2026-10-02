<?php

use Modules\AI\Domain\Decision\DefenseNeedEvaluator;
use Modules\AI\Tests\Support\Situation;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\BuildingQueue;
use OGame\Models\Planet;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// QUAL-003: the cohort read caught accounts whose whole wall stood on one planet while a sibling sat at
// zero defence (player 51: one planet holding 1,496 units, others naked). The invariant counts the
// account, not the planet, so a walled homeworld must not leave a sibling bare.
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
