### EDIT: app/Actions/QueueAiRecycleAction.php
<<<<<<< SEARCH
use OGame\Services\PlayerGameStateService;
use OGame\Services\PlayerService;
=======
use OGame\Services\PlayerGameStateService;
use OGame\Services\PlayerService;
use OGame\Services\UnitQueueService;
use Symfony\Component\Yaml\Yaml;
>>>>>>> REPLACE

<<<<<<< SEARCH
    /** The cheapest speed the host accepts (10%), a slow harvest run. */
    private const RECYCLE_SPEED = 10.0;

    public function __construct(
=======
    /** The cheapest speed the host accepts (10%), a slow harvest run. */
    private const RECYCLE_SPEED = 10.0;

    /** The harvest numbers, read once from the module's behavior file. */
    private ?array $harvestPolicy = null;

    public function __construct(
>>>>>>> REPLACE

<<<<<<< SEARCH
            $fleet = $this->harvestFleet($player, $origin, $targetGalaxy, $targetSystem, $targetPosition);
            if ($fleet === null) {
                return AiActionResult::rejected(AiQueueActionReason::NoDisposableFleet);
            }
=======
            $fleet = $this->harvestFleet($player, $origin, $targetGalaxy, $targetSystem, $targetPosition);
            if ($fleet === null) {
                // The unit planner has no recycler role, so a field worth harvesting is
                // the only order for a harvest hull the account ever gets.
                $this->orderHarvestHulls($player, $origin, $targetGalaxy, $targetSystem, $targetPosition);

                return AiActionResult::rejected(AiQueueActionReason::NoDisposableFleet);
            }
>>>>>>> REPLACE

<<<<<<< SEARCH
    /** The field's total mass at dispatch time, re-derived, never trusted from the decision. */
=======
    /**
     * Order harvest hulls at the origin when the body owns none.
     *
     * Enough hulls to lift the field in one trip, capped by the policy, and only for
     * a field that can pay for them; the host's queue owns the build time and the
     * shipyard's requirement check. Once a hull exists no further order is placed,
     * because the hull count taken at dispatch is above zero.
     */
    private function orderHarvestHulls(PlayerService $player, PlanetService $origin, int $galaxy, int $system, int $position): void
    {
        $mass = $this->fieldMass($galaxy, $system, $position);
        if ($mass < $this->harvestPolicy()['worthwhile_mass']) {
            return;
        }

        $ship = ObjectService::getShipObjectByMachineName(RecycleMission::getHarvesterMachineNameForPosition($position));
        $capacity = max(1, $ship->properties->capacity->calculate($player)->totalValue);
        $count = (int) min($this->harvestPolicy()['max_hulls_per_order'], max(1, (int) ceil($mass / $capacity)));

        app()->makeWith(UnitQueueService::class, ['player' => $player, 'planet' => $origin])
            ->add($ship->machine_name, $count);
    }

    /** The harvest numbers, loaded by name from the module's behavior directory. */
    private function harvestPolicy(): array
    {
        $this->harvestPolicy ??= (array) Yaml::parseFile(dirname(__DIR__, 2) . '/resources/behavior/harvest.yaml');

        return $this->harvestPolicy['harvest'];
    }

    /** The field's total mass at dispatch time, re-derived, never trusted from the decision. */
>>>>>>> REPLACE

### FILE: resources/behavior/harvest.yaml
```yaml
# Harvest policy for the AI pilot.
#
# Debris is only worth buying a recycler for when the field can pay for the hull:
# no harvest hull is ordered for dust, and never more hulls than one trip needs.
# The module reads these as numbers, so keep them numeric.
harvest:
  # A field lighter than this is left in space instead of being ordered for.
  worthwhile_mass: 5000

  # Ceiling on the hulls a single order may add; the field's mass decides the count.
  max_hulls_per_order: 5
```

### FILE: tests/Feature/HarvestHullOrderTest.php
```php
<?php

use Modules\AI\Actions\QueueAiRecycleAction;
use Modules\AI\Contracts\QueueAiRecycle;
use OGame\GameMissions\RecycleMission;
use OGame\Models\DebrisField;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\UnitQueue;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiRecycle::class, QueueAiRecycleAction::class);
    // The recycle surface is shared state, so clear it per test rather than lean on
    // rollback in a parallel worker.
    DebrisField::query()->delete();
    FleetMission::query()->where('mission_type', RecycleMission::getTypeId())->delete();
    UnitQueue::query()->delete();
});

// No harvest hull is owned, so the intent cannot fly: the hull is ordered instead
// of the field being abandoned.
test('a recycle intent with no hull orders a harvest hull', function (): void {
    recycleProfile($this->currentUserId);
    $planetId = (int) Planet::query()->where('user_id', $this->currentUserId)->orderBy('id')->firstOrFail()->id;
    DebrisField::create(['galaxy' => 1, 'system' => 2, 'planet' => 3, 'metal' => 20_000, 'crystal' => 20_000, 'deuterium' => 0]);

    app(QueueAiRecycle::class)->handle($this->currentUserId, $planetId, 1, 2, 3, PlanetType::DebrisField->value);

    expect(UnitQueue::query()->count())->toBe(1)
        ->and(FleetMission::query()->where('mission_type', RecycleMission::getTypeId())->count())->toBe(0);
});

// At the bound the policy still pays for the trip.
test('a field exactly at the worthwhile mass orders a hull', function (): void {
    recycleProfile($this->currentUserId);
    $planetId = (int) Planet::query()->where('user_id', $this->currentUserId)->orderBy('id')->firstOrFail()->id;
    DebrisField::create(['galaxy' => 1, 'system' => 2, 'planet' => 6, 'metal' => 2_500, 'crystal' => 2_500, 'deuterium' => 0]);

    app(QueueAiRecycle::class)->handle($this->currentUserId, $planetId, 1, 2, 6, PlanetType::DebrisField->value);

    expect(UnitQueue::query()->count())->toBe(1);
});

// Past the bound (lighter than the policy floor) the account buys nothing.
test('a field below the worthwhile mass orders nothing', function (): void {
    recycleProfile($this->currentUserId);
    $planetId = (int) Planet::query()->where('user_id', $this->currentUserId)->orderBy('id')->firstOrFail()->id;
    DebrisField::create(['galaxy' => 1, 'system' => 2, 'planet' => 4, 'metal' => 2_000, 'crystal' => 2_000, 'deuterium' => 0]);

    app(QueueAiRecycle::class)->handle($this->currentUserId, $planetId, 1, 2, 4, PlanetType::DebrisField->value);

    expect(UnitQueue::query()->count())->toBe(0)
        ->and(FleetMission::query()->where('mission_type', RecycleMission::getTypeId())->count())->toBe(0);
});

// At zero debris there is nothing to harvest and nothing to buy for.
test('no debris field at all orders nothing', function (): void {
    recycleProfile($this->currentUserId);
    $planetId = (int) Planet::query()->where('user_id', $this->currentUserId)->orderBy('id')->firstOrFail()->id;

    app(QueueAiRecycle::class)->handle($this->currentUserId, $planetId, 1, 2, 7, PlanetType::DebrisField->value);

    expect(UnitQueue::query()->count())->toBe(0)
        ->and(FleetMission::query()->where('mission_type', RecycleMission::getTypeId())->count())->toBe(0);
});

// A body that owns a hull flies the mission and orders nothing further.
test('a body that owns a hull flies the harvest and orders nothing', function (): void {
    recycleProfile($this->currentUserId);
    $planetId = (int) Planet::query()->where('user_id', $this->currentUserId)->orderBy('id')->firstOrFail()->id;
    $this->planetAddUnit('recycler', 1);
    DebrisField::create(['galaxy' => 1, 'system' => 2, 'planet' => 5, 'metal' => 20_000, 'crystal' => 20_000, 'deuterium' => 0]);

    app(QueueAiRecycle::class)->handle($this->currentUserId, $planetId, 1, 2, 5, PlanetType::DebrisField->value);

    expect(UnitQueue::query()->count())->toBe(0)
        ->and(FleetMission::query()->where('mission_type', RecycleMission::getTypeId())->count())->toBe(1);
});
```

### FILE: resources/scenarios/debris-field.json
```json
{
    "name": "debris-field",
    "persona": "Early-game AI account on an expedition diet; it has lost fleets to expedition battles and owns no harvest hull.",
    "situation": "A recycle intent lands on a debris field heavy enough to pay for a recycler, and the origin body owns no harvest hull.",
    "input": {
        "owned_harvest_hulls": 0,
        "debris_field": {
            "galaxy": 1,
            "system": 2,
            "planet": 3,
            "metal": 20000,
            "crystal": 20000,
            "deuterium": 0
        },
        "policy": {
            "worthwhile_mass": 5000,
            "max_hulls_per_order": 5
        }
    },
    "decision_key": "recycle",
    "expect": {
        "action": "queue_recycle",
        "mission_queued": false,
        "reason": "no_disposable_fleet",
        "side_effect": {
            "queue": "shipyard",
            "unit": "the host's harvester for the target slot",
            "count_at_least": 1
        },
        "outcome": "recyclers owned on the next cohort read and the field reduced by a recycle mission"
    },
    "status": "unverified",
    "checklist": [
        {
            "topic": "hull-source",
            "question": "Which module path orders the harvest hull while the unit planner carries no recycler role?",
            "status": "unverified"
        },
        {
            "topic": "worthwhile-mass",
            "question": "At what field mass does one harvest hull pay for itself on the live cohorts?",
            "status": "unverified"
        }
    ]
}
```

### FILE: resources/scenarios/debris-field-dust.json
```json
{
    "name": "debris-field-dust",
    "persona": "Early-game AI account on an expedition diet; it has lost fleets to expedition battles and owns no harvest hull.",
    "situation": "A recycle intent lands on a debris field lighter than the policy floor, and the origin body owns no harvest hull.",
    "input": {
        "owned_harvest_hulls": 0,
        "debris_field": {
            "galaxy": 1,
            "system": 2,
            "planet": 4,
            "metal": 2000,
            "crystal": 2000,
            "deuterium": 0
        },
        "policy": {
            "worthwhile_mass": 5000,
            "max_hulls_per_order": 5
        }
    },
    "decision_key": "recycle",
    "expect": {
        "action": "queue_recycle",
        "mission_queued": false,
        "reason": "no_disposable_fleet",
        "side_effect": {
            "queue": "shipyard",
            "unit": null,
            "count_at_least": 0
        },
        "outcome": "no hull is bought for dust, so the account keeps its resources"
    },
    "status": "unverified",
    "checklist": [
        {
            "topic": "dust-vs-payback",
            "question": "Does a field below the floor ever justify a hull on the live cohorts?",
            "status": "unverified"
        }
    ]
}
```