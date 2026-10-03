<?php

use Illuminate\Support\Facades\DB;
use Modules\AI\Domain\Decision\DefenseNeed;
use Modules\AI\Domain\Decision\DefenseNeedEvaluator;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\BuildingQueue;
use OGame\Models\Planet;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// The one surviving situation for invariant:NAKED_BESIDE_WALLED after the QUAL-DEDUP merge: a naked
// colony, a naked planet and a stationary fleet, each read at the bound and at zero. The live read
// found accounts with planets at zero defence while a sibling held a real wall: the whole wall was
// raised on the planet with the most to lose and the bare siblings were never reached.
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

// QUAL-003's live shape (invariant:NAKED_BESIDE_WALLED, aspect:shipyard), the story the deleted
// NakedColonyWallSituationTest held: the colony below the yard its wall waits on. The cohort read
// found an account holding a wall on one planet while a young colony stood at zero defence: the wall
// waits on a shipyard the colony does not have yet, and the account kept spending its yard elsewhere.
// An account whose sibling already holds a wall has to bring the shipyard and then the wall to the
// bare colony, instead of leaving it naked for as long as it plays.
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

// The story the deleted StationaryFleetWallSituationTest held: what a planet stands to lose is not only
// the output that piles up while the account is away. A fleet left standing in orbit is worth to a
// raider exactly what the pile is, so a wall sized on production alone leaves it uncovered. The plant
// puts the idle fleet on a planet whose small wall already covers the mines, and the record must state
// a need only once the fleet is there.
test('an idle fleet raises what the planet stands to lose', function (): void {
    $situation = Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->level('shipyard', 4)
        ->defence('rocket_launcher', 200);

    $player = $this->planetService->getPlayer();
    $covered = app(DefenseNeedEvaluator::class)->evaluate($player, $this->planetService);

    expect($covered)->toBeNull('the wall already covers production-only exposure; ' . $situation->account());

    $situation->ships('light_fighter', 2_000);

    $exposed = app(DefenseNeedEvaluator::class)->evaluate($player, $this->planetService);

    expect($exposed)->toBeInstanceOf(DefenseNeed::class)
        ->and($exposed->protectedValue)->toBeGreaterThan(0.0)
        ->and($exposed->currentDefenseValue)->toBeGreaterThan(0.0);
});

// The bound at zero of the fleet rule: the fleet only adds to the exposure, it is not what creates the
// need. A planet whose own output is nothing still takes the file's floor, so an account is not left
// naked because it has no fleet to protect.
test('a planet with nothing standing in orbit still takes the doctrine floor', function (): void {
    resolve(SettingsService::class)->set('economy_speed', 1);

    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->level('shipyard', 4)
        ->level('metal_mine', 0)
        ->level('crystal_mine', 0)
        ->level('deuterium_synthesizer', 0);

    $this->planetService->updateResourceProductionStats();

    $need = app(DefenseNeedEvaluator::class)->evaluate($this->planetService->getPlayer(), $this->planetService);

    expect($need)->toBeInstanceOf(DefenseNeed::class)
        ->and($need->reason)->toBe('defense:need:minimum-deterrent');
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
// affordability gate is not bypassed to make a naked planet look walled. Read after the merged
// stories, so one file carries the rule at the bound, at zero and past it.
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

// The cohort's own read, the story the deleted NakedPlanetLiveInvariantTest held: the invariant counts
// the defence columns the host has *built* on every planet and cries wolf when one is zero while a
// sibling already holds a real wall. This mirrors that read after a full day of the account's own
// logins, so the merged file proves the shape the live invariant actually measures, not only the
// orders a login placed.
test('a walled homeworld leaves no colony at zero built defence after a day', function (): void {
    $situation = Situation::of($this)
        ->resources(50_000_000, 50_000_000, 50_000_000)
        ->level('robot_factory', 2)
        ->level('shipyard', 4)
        ->ships('small_cargo', 10)
        ->defence('rocket_launcher', 1_200)
        ->colony()
        ->colony()
        ->stockEveryPlanet(50_000_000, 50_000_000, 50_000_000);

    $situation->day();

    expect(builtNakedPlanets($this->currentUserId))->toBe([], 'these planets hold no built defence; ' . $situation->account());
});

/** @return list<int> the account's planets (moons excluded) whose built defence columns are all zero */
function builtNakedPlanets(int $playerId): array
{
    $defence = array_map(static fn ($object): string => $object->machine_name, ObjectService::getDefenseObjects());

    return Planet::query()->where('user_id', $playerId)->where('planet_type', 1)->pluck('id')
        ->map(static fn ($id): int => (int) $id)
        ->filter(static function (int $id) use ($defence): bool {
            $row = (array) DB::table('planets')->where('id', $id)->first();
            foreach ($defence as $machineName) {
                if ((int) ($row[$machineName] ?? 0) > 0) {
                    return false;
                }
            }

            return true;
        })->values()->all();
}

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
