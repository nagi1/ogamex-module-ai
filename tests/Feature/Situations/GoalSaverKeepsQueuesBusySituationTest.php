<?php

use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\BuildingQueue;
use OGame\Models\Planet;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// PERS-007 against ECON-001's IDLE_QUEUES, the cohort reading that failed (live: player 37 played
// this hour with 2 of 2 planets buildable and idle). "Buildable" is the cohort's word for a planet
// QueueableBuildingPlanner::steps() offers a step for, so the planner and the account must agree:
// a planet whose pile the goal reserves is being saved for, not neglected, and must not be offered.
// Offered while the account rightly saves is the defect -- the read-out then calls a saver idle.
test('a goal saver holding its pile is offered no step, so its planets never read buildable', function (): void {
    $situation = Situation::of($this)->goalSaver()->colony()
        ->levelEveryPlanet('metal_mine', 13)
        ->levelEveryPlanet('crystal_mine', 13)
        ->levelEveryPlanet('deuterium_synthesizer', 13)
        ->levelEveryPlanet('solar_plant', 30)
        ->levelEveryPlanet('metal_store', 20)
        ->levelEveryPlanet('crystal_store', 20)
        ->levelEveryPlanet('deuterium_store', 20)
        ->stockEveryPlanet(5_000, 2_000, 1_000);

    $situation->session();

    $planned = collect(app(QueueableBuildingPlanner::class)->steps($this->currentUserId))->pluck('planetId')->unique();

    expect($planned->all())->toBe([], 'expected the goal-saver planets not to read as buildable, but ' . $situation->account());
});

// The other half of the same reading: a session that also ferries stock between the account's own
// planets must still leave each planet the step the planner offers for it. A ferry that empties a
// planet before its building is placed spends the queue slot and the step goes with it.
test('a goal saver that ferries stock still queues the step it was saving for', function (): void {
    $situation = Situation::of($this)->goalSaver()->colony()
        ->levelEveryPlanet('metal_mine', 13)
        ->levelEveryPlanet('crystal_mine', 13)
        ->levelEveryPlanet('deuterium_synthesizer', 13)
        ->levelEveryPlanet('solar_plant', 30)
        ->levelEveryPlanet('metal_store', 20)
        ->levelEveryPlanet('crystal_store', 20)
        ->levelEveryPlanet('deuterium_store', 20)
        ->stockEveryPlanet(500_000, 300_000, 100_000)
        ->ships('small_cargo', 5);

    $situation->session();

    $planets = Planet::query()->where('user_id', $this->currentUserId)->where('planet_type', 1)->pluck('id');
    $planned = collect(app(QueueableBuildingPlanner::class)->steps($this->currentUserId))->pluck('planetId')->unique();
    $busy = BuildingQueue::query()->whereIn('planet_id', $planets)->where('processed', 0)->distinct()->pluck('planet_id');

    expect($planned->diff($busy)->values()->all())->toBe([], $situation->account());
});
