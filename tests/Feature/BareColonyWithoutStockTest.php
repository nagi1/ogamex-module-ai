<?php

use Illuminate\Support\Facades\DB;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\Planet;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// PERS-004 (invariant:NAKED_BESIDE_WALLED, aspect:shipyard): the live read counts the defence columns
// the host has *built* on every planet of an account and cries wolf when one stands at zero while a
// sibling already holds a real wall. The cohort read found one account still in that shape (player 59:
// 1 planet at zero defence while one held 1,162 units) although the walled homeworld and its poor
// sibling are both handled: the remaining shape is the colony the account founded and never stocked,
// which has no shipyard and nothing to pay for one. A player sends the colony what it needs; a day of
// the account's own logins has to leave that planet walled too.
test('a day of a walled homeworld leaves its unstoked colony holding a wall', function (): void {
    $situation = Situation::of($this)
        ->resources(50_000_000, 50_000_000, 50_000_000)
        ->level('robot_factory', 2)
        ->level('shipyard', 4)
        ->defence('rocket_launcher', 60)
        ->colony()
        ->stockEveryPlanet(50_000_000, 50_000_000, 50_000_000);

    $situation->day();

    $planets = Planet::query()->where('user_id', $this->currentUserId)->where('planet_type', 1)->pluck('id');
    $defence = array_map(static fn ($object): string => $object->machine_name, ObjectService::getDefenseObjects());

    foreach ($planets as $planetId) {
        $row = (array) DB::table('planets')->where('id', (int) $planetId)->first();
        $built = 0;
        foreach ($defence as $machineName) {
            $built += (int) ($row[$machineName] ?? 0);
        }

        expect($built)->toBeGreaterThan(0, 'planet ' . $planetId . ' holds no built defence after a day; ' . $situation->account());
    }
});
