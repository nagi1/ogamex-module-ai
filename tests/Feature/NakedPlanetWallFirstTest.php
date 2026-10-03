<?php

use Modules\AI\Tests\Support\Situation;
use OGame\Models\BuildingQueue;
use OGame\Models\Planet;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// QUAL-003 (invariant:NAKED_BESIDE_WALLED, aspect:shipyard): the wall waits on a yard the bare colony
// does not have, so its login has to go to that yard before any wall unit. The marker is the host's own
// prerequisite list for the defence registry, not a facility this file names.
test('a login beside a walled homeworld spends the bare colony on the yard its wall waits on', function (): void {
    $before = Planet::query()->where('user_id', $this->currentUserId)->pluck('id')->all();

    $situation = Situation::of($this)
        ->level('robot_factory', 2)
        ->level('shipyard', 4)
        ->ships('small_cargo', 10)
        ->defence('light_laser', 60)
        ->colony()
        ->stockEveryPlanet(50_000_000, 50_000_000, 50_000_000)
        ->session();

    $colonyId = (int) Planet::query()->where('user_id', $this->currentUserId)->where('planet_type', 1)
        ->whereNotIn('id', $before)->value('id');
    $ordered = BuildingQueue::query()->where('planet_id', $colonyId)->where('canceled', 0)
        ->orderBy('id')->value('object_id');

    expect($ordered)->not->toBeNull('the bare colony got no order this login; ' . $situation->account());

    $prerequisites = [];
    foreach (ObjectService::getDefenseObjects() as $defence) {
        $prerequisites = [...$prerequisites, ...array_keys(ObjectService::getRecursiveRequirements($defence->machine_name))];
    }

    $orderedName = ObjectService::getObjectById((int) $ordered)->machine_name;
    expect(in_array($orderedName, $prerequisites, true))->toBeTrue(
        'the bare colony spent its login on ' . $orderedName . ', not on what its wall waits for; ' . $situation->account()
    );
});
