<?php

use Modules\AI\Tests\Support\Situation;
use OGame\Models\Planet;
use OGame\Models\UnitQueue;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// MISSILES-001: an account that has built a silo fills it, and what it fills it with is the silo's own
// interplanetary capacity — resources/behavior/def-ipm.yaml states five missiles a level, the same number
// the host's ten-slots-a-level cap allows once each missile takes two. The account used to keep a fixed
// five however deep the silo was, so the stock never followed the building it belonged to.

/**
 * A planet that can fly interplanetary missiles and has a wall to thin in its reports: the silo, the
 * impulse drive the host's range wants, the shipyard the missile's requirements name, and a neighbour
 * whose defence the account has probed. Planted on every planet, so the story does not depend on which
 * of the account's planets the login reaches first.
 */
function missileStockSituation(
    Situation $situation,
    int $siloLevel,
    int $metal = 20_000_000,
    int $crystal = 20_000_000,
    int $deuterium = 20_000_000,
): Situation {
    $situation->levelEveryPlanet('shipyard', 4)
        ->research('impulse_drive', 3)
        ->stockEveryPlanet($metal, $crystal, $deuterium);

    if ($siloLevel > 0) {
        $situation->levelEveryPlanet('missile_silo', $siloLevel);
    }

    return $situation->inactiveNeighbour(metal: 300_000)->neighbourUnits('rocket_launcher', 40)->spyReport();
}

/** The missiles the account put in its yards, summed over its planets: what it filled the silo with. */
function missileStockOrdered(array $planetIds): int
{
    return (int) UnitQueue::query()
        ->whereIn('planet_id', $planetIds)
        ->where('object_id', ObjectService::getObjectByMachineName('interplanetary_missile')->id)
        ->sum('object_amount');
}

// The shallowest silo the host lets a missile be built from: ten slots a level and two slots a missile,
// so a level-four silo is twenty missiles rather than the handful the account used to keep.
test('a silo four deep is stocked to the twenty missiles it stores', function (): void {
    $situation = missileStockSituation(Situation::of($this), 4)->session();
    $planets = Planet::query()->where('user_id', $this->currentUserId)->pluck('id')->all();

    expect(missileStockOrdered($planets))->toBe(20, 'expected the silo filled to twenty missiles, but ' . $situation->account())
        ->and($situation->queued())->toContain('interplanetary_missile');
});

// Twice the silo stores twice the stock, which is the whole point of the rule: the account follows the
// building it paid for instead of a constant.
test('twice the silo stores twice the missiles', function (): void {
    $situation = missileStockSituation(Situation::of($this), 8)->session();
    $planets = Planet::query()->where('user_id', $this->currentUserId)->pluck('id')->all();

    expect(missileStockOrdered($planets))->toBe(40, 'expected the silo filled to forty missiles, but ' . $situation->account());
});

// Past the bound the cap wins: a planet that cannot pay for the whole stock orders what it can pay for,
// never more than the silo will take.
test('a planet too poor for the whole silo stocks what it can pay for', function (): void {
    $situation = missileStockSituation(Situation::of($this), 8, metal: 60_000, crystal: 60_000, deuterium: 40_000)->session();
    $planets = Planet::query()->where('user_id', $this->currentUserId)->pluck('id')->all();

    expect(missileStockOrdered($planets))->toBeGreaterThan(0, 'expected the poor planet to stock what it could pay for, but ' . $situation->account())
        ->and(missileStockOrdered($planets))->toBeLessThan(40);
});

// Zero: no silo, no stock. The host refuses the missile without a level-four silo, so nothing is ordered
// and the account does not carry a handful of missiles around a planet that cannot hold them.
test('a planet with no silo orders no missiles', function (): void {
    $situation = missileStockSituation(Situation::of($this), 0)->session();
    $planets = Planet::query()->where('user_id', $this->currentUserId)->pluck('id')->all();

    expect(missileStockOrdered($planets))->toBe(0, 'expected no missile without a silo, but ' . $situation->account())
        ->and($situation->queued())->not->toContain('interplanetary_missile');
});
