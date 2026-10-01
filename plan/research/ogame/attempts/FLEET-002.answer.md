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
    /**
     * Enough harvest hulls to carry the field, capped by what the body holds.
     *
     * The hull is the one the host's RecycleMission requires for the slot and its
     * capacity is the host's own figure, so a mod that changes either changes the
     * count with no module edit. At least one hull flies for a field still worth
     * a trip.
     */
    private function harvestFleet(PlayerService $player, PlanetService $origin, int $galaxy, int $system, int $position): ?UnitCollection
    {
        $ship = ObjectService::getShipObjectByMachineName(RecycleMission::getHarvesterMachineNameForPosition($position));
        $available = $origin->getShipUnits()->getAmountByMachineName($ship->machine_name);
        if ($available <= 0) {
            return null;
        }

        $capacity = $ship->properties->capacity->calculate($player)->totalValue;
        $needed = (int) ceil($this->fieldMass($galaxy, $system, $position) / max(1, $capacity));
        $count = max(1, min($available, $needed));

        $fleet = new UnitCollection();
        $fleet->addUnit($ship, $count);

        return $fleet;
    }
=======
    /**
     * Enough harvest hulls to carry the field, capped by what the body holds.
     *
     * The hull is the one the host's RecycleMission requires for the slot and its
     * capacity is the host's own figure, so a mod that changes either changes the
     * count with no module edit. At least one hull flies for a field still worth
     * a trip. A body that holds none orders the hull the trip needs instead, so a
     * field a human would harvest does not sit in space forever.
     */
    private function harvestFleet(PlayerService $player, PlanetService $origin, int $galaxy, int $system, int $position): ?UnitCollection
    {
        $ship = ObjectService::getShipObjectByMachineName(RecycleMission::getHarvesterMachineNameForPosition($position));
        $available = $origin->getShipUnits()->getAmountByMachineName($ship->machine_name);
        if ($available <= 0) {
            $this->orderHarvestHulls($player, $origin, $galaxy, $system, $position);

            return null;
        }

        $capacity = $ship->properties->capacity->calculate($player)->totalValue;
        $needed = (int) ceil($this->fieldMass($galaxy, $system, $position) / max(1, $capacity));
        $count = max(1, min($available, $needed));

        $fleet = new UnitCollection();
        $fleet->addUnit($ship, $count);

        return $fleet;
    }

    /**
     * The hulls this trip needs, ordered through the yard the account already builds in.
     *
     * Mass-free coordinates order nothing: yard time spent on dust is never paid
     * back. The cap bounds what one field may claim, so a single field cannot eat
     * a whole shipyard order.
     */
    private function orderHarvestHulls(PlayerService $player, PlanetService $origin, int $galaxy, int $system, int $position): void
    {
        $ship = ObjectService::getShipObjectByMachineName(RecycleMission::getHarvesterMachineNameForPosition($position));
        $capacity = max(1, $ship->properties->capacity->calculate($player)->totalValue);
        $needed = (int) ceil($this->fieldMass($galaxy, $system, $position) / $capacity);
        $count = min($needed, $this->harvestHullOrderCap());
        if ($count <= 0) {
            return;
        }

        $unitQueue = app()->makeWith(UnitQueueService::class, ['player' => $player]);
        $unitQueue->add($origin, $ship, $count);
    }

    /** The order cap, read by name from the module's behaviour file. */
    private function harvestHullOrderCap(): int
    {
        $behaviour = Yaml::parseFile(dirname(__DIR__, 2) . '/resources/behavior/harvest-order.yaml');

        return (int) $behaviour['max_hulls_per_order'];
    }
>>>>>>> REPLACE

### FILE: resources/behavior/harvest-order.yaml
```yaml
# Bounds on the harvest hulls one debris field may order from the yard when the
# account owns none: the trip is sized to the field, but a single field must not
# claim a whole shipyard order.
max_hulls_per_order: 20
```

### FILE: tests/Feature/HarvestHullOrderTest.php
```php
<?php

use Modules\AI\Actions\QueueAiRecycleAction;
use Modules\AI\Contracts\QueueAiRecycle;
use Modules\AI\Support\AiActionResult;
use OGame\GameMissions\RecycleMission;
use OGame\Models\DebrisField;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\UnitQueue;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/** The account's own body, the one the fixtures put hulls on. */
function harvestHullOrderPlanet(int $playerId): int
{
    return (int) Planet::query()->where('user_id', $playerId)->orderBy('id')->value('id');
}

/** A field of the given total mass at the given slot. */
function harvestDebrisField(int $position, int $mass): void
{
    DebrisField::create([
        'galaxy' => 1,
        'system' => 1,
        'planet' => $position,
        'metal' => $mass,
        'crystal' => 0,
        'deuterium' => 0,
    ]);
}

/** The real harvest path: the module's recycle adapter, the host's mission. */
function harvestTrip(int $playerId, int $planetId, int $position): AiActionResult
{
    return app(QueueAiRecycle::class)->handle(
        $playerId,
        $planetId,
        1,
        1,
        $position,
        PlanetType::DebrisField->value,
    );
}

beforeEach(function (): void {
    app()->bind(QueueAiRecycle::class, QueueAiRecycleAction::class);
    DebrisField::query()->delete();
    UnitQueue::query()->delete();
    FleetMission::query()->where('mission_type', RecycleMission::getTypeId())->delete();
});

// A field a human would harvest with no hull anywhere is work the account never
// gets to: the hull the trip needs is ordered, not the field abandoned.
test('a field the account cannot carry orders the hull the trip needs', function (): void {
    harvestDebrisField(5, 10_000);

    $result = harvestTrip($this->currentUserId, harvestHullOrderPlanet($this->currentUserId), 5);

    expect($result)->toBeInstanceOf(AiActionResult::class)
        ->and(UnitQueue::query()->count())->toBe(1)
        ->and((int) UnitQueue::query()->value('object_amount'))->toBe(1);
});

// Past the cap the module orders the cap: one field must not eat the whole yard.
test('a field past the cap orders only the cap', function (): void {
    harvestDebrisField(6, 1_000_000_000);

    harvestTrip($this->currentUserId, harvestHullOrderPlanet($this->currentUserId), 6);

    expect(UnitQueue::query()->count())->toBe(1)
        ->and((int) UnitQueue::query()->value('object_amount'))->toBe(20);
});

// No mass at the coordinates is no work: dust does not cost yard time.
test('an empty field orders no hull', function (): void {
    harvestDebrisField(7, 0);

    $result = harvestTrip($this->currentUserId, harvestHullOrderPlanet($this->currentUserId), 7);

    expect($result)->toBeInstanceOf(AiActionResult::class)
        ->and(UnitQueue::query()->count())->toBe(0);
});

// An account that already owns a harvest hull flies the trip; the yard is not
// asked for a second fleet.
test('an account that owns a hull orders nothing', function (): void {
    $this->planetAddUnit('recycler', 1);
    harvestDebrisField(8, 1_000_000);

    harvestTrip($this->currentUserId, harvestHullOrderPlanet($this->currentUserId), 8);

    expect(UnitQueue::query()->count())->toBe(0);
});
```

### FILE: resources/scenarios/recycle-without-harvest-hull.json
```json
{
    "name": "recycle-without-harvest-hull",
    "situation": "A debris field worth harvesting sits near the account and the account owns no harvest hull, so every recycle intent it decides can never fly and the debris stays in space.",
    "persona": "raider",
    "input": {
        "owned_harvest_hulls": 0,
        "debris_field": {
            "galaxy": 1,
            "system": 1,
            "position": 5,
            "metal": 10000,
            "crystal": 0,
            "deuterium": 0
        }
    },
    "decision_key": "recycle",
    "expect": {
        "action": "recycle",
        "harvest_hull_order": {
            "object": "recycler",
            "amount": 1
        }
    },
    "status": "unverified",
    "checklist": [
        {
            "topic": "hull-source",
            "question": "Is the harvest hull ordered through the host's yard queue, at the host's price, or granted for free?",
            "status": "unverified"
        },
        {
            "topic": "order-size",
            "question": "How many hulls does one field order when the account owns none: what the field carries, or a fixed count?",
            "status": "unverified"
        },
        {
            "topic": "order-cap",
            "question": "What bounds one field's order so it cannot claim the whole shipyard?",
            "status": "unverified"
        },
        {
            "topic": "repeat-intent",
            "question": "What stops a second recycle intent, before the ordered hulls exist, from ordering the same hulls again?",
            "status": "unverified"
        }
    ]
}
```

### FILE: resources/scenarios/recycle-with-harvest-hull.json
```json
{
    "name": "recycle-with-harvest-hull",
    "situation": "The same field, but the account already owns a harvest hull: the trip flies and the yard is left alone.",
    "persona": "raider",
    "input": {
        "owned_harvest_hulls": 1,
        "debris_field": {
            "galaxy": 1,
            "system": 1,
            "position": 5,
            "metal": 10000,
            "crystal": 0,
            "deuterium": 0
        }
    },
    "decision_key": "recycle",
    "expect": {
        "action": "recycle",
        "harvest_hull_order": null
    },
    "status": "unverified",
    "checklist": [
        {
            "topic": "hull-count",
            "question": "Does an owned hull stop the order entirely, or is the order sized to the shortfall?",
            "status": "unverified"
        },
        {
            "topic": "trip-size",
            "question": "How many owned hulls of the required type fly for a field of a given mass?",
            "status": "unverified"
        }
    ]
}
```