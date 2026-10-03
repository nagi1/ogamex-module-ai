<?php

use Modules\AI\Domain\Decision\DefenseNeedEvaluator;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Planet;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// QUAL-003 (invariant:NAKED_BESIDE_WALLED, aspect:shipyard): the wall pass served one planet per
// login, so an account with several naked siblings beside its wall took as many logins as it has
// planets -- more than its own day of sessions -- and the cohort read kept finding planets at zero
// defence while one sibling held a real wall (3 planets at zero beside one holding 1,312 units).
// A player clicks through every naked colony before logging off, and the invariant reads the
// account, so one login has to hand each bare sibling the wall order the account already holds.
test('a login beside a walled homeworld walls every naked sibling, not just the first', function (): void {
    $situation = Situation::of($this)
        ->colony()
        ->colony()
        ->levelEveryPlanet('robot_factory', 2)
        ->levelEveryPlanet('shipyard', 4)
        ->defence('rocket_launcher', 20)
        ->stockEveryPlanet(20_000_000, 10_000_000, 5_000_000);

    $nakedIds = array_values(array_diff(
        Planet::query()->where('user_id', $this->currentUserId)->where('planet_type', 1)->pluck('id')->all(),
        [$this->currentPlanetId]
    ));
    sort($nakedIds);
    expect($nakedIds)->toHaveCount(3);

    // The planner's own pass names one wall order per bare sibling before the session places them.
    expect(app(QueueableUnitPlanner::class)->standingDefenceOrders($this->currentUserId))
        ->toHaveCount(3, 'the pass skipped a bare sibling; ' . $situation->account());

    $situation->session()->expectWork(AiWorkKind::QueueUnits);

    // A short build may already be complete by the session's clock reaching each intent, so the wall
    // counts whether it is standing or still paid for in the yard.
    foreach ($nakedIds as $planetId) {
        $planet = app(PlanetServiceFactory::class)->make((int) $planetId, true);
        expect(app(DefenseNeedEvaluator::class)->standingUnits($planet))->toBeGreaterThan(0, 'the bare sibling ' . $planetId . ' stayed at zero defence; ' . $situation->account());
    }

    // The walled homeworld keeps the wall it had: the login's orders belong to the bare siblings.
    expect(app(DefenseNeedEvaluator::class)->standingUnits(app(PlanetServiceFactory::class)->make($this->currentPlanetId, true)))
        ->toBe(20, 'the login stacked wall on the planet that already holds one; ' . $situation->account());
});

// The zero case: a sibling the account cannot pay for is not walled at all. The host refuses an order
// it cannot pay for whole, so a pass that spent the login on an unaffordable batch would leave the
// planet naked and the login wasted; it waits for a later login instead.
test('a login that can pay for nothing queues no wall on the bare sibling', function (): void {
    $situation = Situation::of($this)
        ->colony()
        ->levelEveryPlanet('robot_factory', 2)
        ->levelEveryPlanet('shipyard', 4)
        ->ships('small_cargo', 10)
        ->ships('light_laser', 60);

    $situation->session();

    $defence = array_map(static fn ($object): string => $object->machine_name, ObjectService::getDefenseObjects());

    expect($situation->work())->not->toBeEmpty()
        ->and(array_intersect($defence, $situation->queued()))->toBe([], 'a wall was queued without the resources to pay for it; ' . $situation->account());
});
