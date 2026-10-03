<?php

use Illuminate\Support\Facades\DB;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\Planet;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// QUAL-003 (invariant:NAKED_BESIDE_WALLED, aspect:shipyard): the live cohort's planets are poor, not the
// fifty-million test planet. A live account read "1 planet at zero defence while one holds 1,566 units":
// the walled planet is walled, and the sibling beside it stays at zero for a whole day because what it
// earns is spent each login and never reaches the price of the wall unit it owes.
test('a day of a walled homeworld leaves its poor colony holding a wall', function (): void {
    $before = Planet::query()->where('user_id', $this->currentUserId)->pluck('id')->all();

    $situation = Situation::of($this)
        ->level('robot_factory', 2)
        ->level('shipyard', 4)
        ->defence('rocket_launcher', 20)
        ->colony()
        ->stockEveryPlanet(6_000, 3_000, 1_000);

    $situation->day();

    foreach (Planet::query()->where('user_id', $this->currentUserId)->where('planet_type', 1)->pluck('id') as $planetId) {
        $row = (array) DB::table('planets')->where('id', (int) $planetId)->first();
        $built = 0;
        foreach (ObjectService::getDefenseObjects() as $defence) {
            $built += (int) ($row[$defence->machine_name] ?? 0);
        }

        expect($built)->toBeGreaterThan(0, 'planet ' . $planetId . ' holds no built defence after a day; ' . $situation->account());
    }
});
