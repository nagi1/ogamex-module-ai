### FILE: app/Actions/QueueAiRecycleAction.php
```php
<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiRecycle;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\RecycleMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\DebrisField;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerGameStateService;
use OGame\Services\PlayerService;
use OGame\Services\UnitQueueService;
use Symfony\Component\Yaml\Yaml;

/**
 * Module-owned adapter over the host's harvest (recycle) fleet path.
 *
 * The decision, the field, the harvest-hull count and the hull order are the
 * module's; the mission, its legality and the harvest itself are the host's. The
 * hull is the one the host's own RecycleMission requires for the target slot.
 */
class QueueAiRecycleAction implements QueueAiRecycle
{
    /** The cheapest speed the host accepts (10%), a slow harvest run. */
    private const RECYCLE_SPEED = 10.0;

    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
    ) {
    }

    public function handle(int $playerId, int $planetId, int $targetGalaxy, int $targetSystem, int $targetPosition, int $targetType): AiActionResult
    {
        if (!Planet::query()->whereKey($planetId)->where('user_id', $playerId)->exists()) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }

        try {
            $player = $this->playerGameStateService->advance($playerId, $planetId);

            if ($player->isBanned()) {
                return AiActionResult::rejected(AiQueueActionReason::PlayerBanned);
            }
            if ($player->isInVacationMode()) {
                return AiActionResult::rejected(AiQueueActionReason::VacationMode);
            }

            $origin = $this->planetServiceFactory->makeForPlayer($player, $planetId, false);
            $harvester = RecycleMission::getHarvesterMachineNameForPosition($targetPosition);

            if ($origin->getShipUnits()->getAmountByMachineName($harvester) <= 0) {
                return $this->orderHarvestHulls($player, $origin, $harvester, $targetGalaxy, $targetSystem, $targetPosition);
            }

            $fleet = $this->harvestFleet($player, $origin, $targetGalaxy, $targetSystem, $targetPosition);
            if ($fleet === null) {
                return AiActionResult::rejected(AiQueueActionReason::NoDisposableFleet);
            }

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            $mission = $fleetMissions->createNewFromPlanet(
                $origin,
                new Coordinate($targetGalaxy, $targetSystem, $targetPosition),
                PlanetType::from($targetType),
                RecycleMission::getTypeId(),
                $fleet,
                new Resources(),
                self::RECYCLE_SPEED,
            );

            return AiActionResult::queued($mission->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }

    /**
     * A field is in reach and nothing the account owns can carry it, so the hull
     * the host's own RecycleMission asks for is ordered into the planet's yard.
     * The trip itself stays refused: no fleet exists to fly it yet.
     */
    private function orderHarvestHulls(PlayerService $player, PlanetService $origin, string $harvester, int $galaxy, int $system, int $position): AiActionResult
    {
        $ship = ObjectService::getShipObjectByMachineName($harvester);
        $capacity = $ship->properties->capacity->calculate($player)->totalValue;
        $needed = (int) ceil($this->fieldMass($galaxy, $system, $position) / max(1, $capacity));
        $count = min($needed, $this->harvestHullOrderCap());

        if ($count > 0) {
            app(UnitQueueService::class)->add($origin, (int) $ship->id, $count);
        }

        return AiActionResult::rejected(AiQueueActionReason::NoDisposableFleet);
    }

    /** The cap on one field's hull order, from the module's behaviour file. */
    private function harvestHullOrderCap(): int
    {
        $rules = Yaml::parseFile(dirname(__DIR__, 2) . '/resources/behavior/harvest-hull.yaml');

        return (int) ($rules['harvest_hull_order_cap'] ?? 0);
    }

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

    /** The field's total mass at dispatch time, re-derived, never trusted from the decision. */
    private function fieldMass(int $galaxy, int $system, int $position): float
    {
        $field = DebrisField::query()
            ->where('galaxy', $galaxy)
            ->where('system', $system)
            ->where('planet', $position)
            ->first();

        return $field === null ? 0.0 : (float) $field->metal + (float) $field->crystal + (float) $field->deuterium;
    }
}
```

### FILE: resources/behavior/harvest-hull.yaml
```yaml
# Harvest-hull rules for the module's recycle adapter.
#
# The hulls one recycle intent orders are the field's own mass divided by the
# host's capacity for that hull, so a big field is still harvested by
# fleet-sized trips instead of one giant run. The cap keeps a single field from
# eating the whole yard.
harvest_hull_order_cap: 20
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

beforeEach(function (): void {
    app()->bind(QueueAiRecycle::class, QueueAiRecycleAction::class);
    // The harvest surface is shared state, so clear it each test rather than
    // depend on transaction rollback in a parallel worker.
    DebrisField::query()->delete();
    UnitQueue::query()->delete();
    FleetMission::query()->where('mission_type', RecycleMission::getTypeId())->delete();
});

function harvestOriginPlanetId(int $userId): int
{
    return (int) Planet::query()->where('user_id', $userId)->value('id');
}

function harvestDebrisField(int $position, int $metal): void
{
    DebrisField::create([
        'galaxy' => 1,
        'system' => 2,
        'planet' => $position,
        'metal' => $metal,
        'crystal' => 0,
        'deuterium' => 0,
    ]);
}

function harvestTrip(int $userId, int $planetId, int $position): AiActionResult
{
    return app(QueueAiRecycle::class)->handle($userId, $planetId, 1, 2, $position, PlanetType::DebrisField->value);
}

// Nothing the account owns can carry the field, so the hull the trip needs is
// ordered instead: a recycle intent that is never followed by a hull is the
// reason the debris stays in space for ever.
test('a field the account cannot carry orders the hull the trip needs', function (): void {
    $planetId = harvestOriginPlanetId($this->currentUserId);
    harvestDebrisField(5, 20_000);

    $result = harvestTrip($this->currentUserId, $planetId, 5);

    expect($result)->toBeInstanceOf(AiActionResult::class)
        ->and(UnitQueue::query()->count())->toBe(1)
        ->and((int) UnitQueue::query()->value('object_amount'))->toBe(1);
});

// Past the cap the module builds the cap: one field must not eat the whole yard.
test('a field past the cap orders only the cap', function (): void {
    $planetId = harvestOriginPlanetId($this->currentUserId);
    harvestDebrisField(6, 1_000_000_000);

    harvestTrip($this->currentUserId, $planetId, 6);

    expect(UnitQueue::query()->count())->toBe(1)
        ->and((int) UnitQueue::query()->value('object_amount'))->toBe(20);
});

// No mass at the coordinates is no work: dust does not cost yard time.
test('an empty field orders no hull', function (): void {
    $planetId = harvestOriginPlanetId($this->currentUserId);

    harvestTrip($this->currentUserId, $planetId, 7);

    expect(UnitQueue::query()->count())->toBe(0);
});

// The hull is already owned, so the yard is untouched and the trip is the host's.
test('an account that owns a hull orders nothing', function (): void {
    $planetId = harvestOriginPlanetId($this->currentUserId);
    $this->planetAddUnit('recycler', 1);
    harvestDebrisField(8, 20_000);

    harvestTrip($this->currentUserId, $planetId, 8);

    expect(UnitQueue::query()->count())->toBe(0);
});
```

### FILE: resources/scenarios/recycle-without-hull.json
```json
{
    "name": "recycle-without-hull",
    "situation": "A debris field worth a trip sits in reach and the account owns no harvest hull, so every recycle intent is refused for want of a fleet.",
    "status": "unverified",
    "persona": "balanced",
    "input": {
        "own_units": {"recycler": 0},
        "debris_fields": [
            {"galaxy": 1, "system": 2, "planet": 5, "metal": 20000, "crystal": 0, "deuterium": 0}
        ]
    },
    "decision_key": "recycle",
    "expect": {
        "action": "recycle",
        "mission_type": "recycle",
        "unit_ordered": "recycler",
        "outcome": "harvest_hull_ordered"
    }
}
```

### FILE: resources/scenarios/recycle-with-hull.json
```json
{
    "name": "recycle-with-hull",
    "situation": "The account owns the harvest hull the field needs, so the recycle intent flies the mission instead of ordering another hull.",
    "status": "unverified",
    "persona": "balanced",
    "input": {
        "own_units": {"recycler": 5},
        "debris_fields": [
            {"galaxy": 1, "system": 2, "planet": 5, "metal": 20000, "crystal": 0, "deuterium": 0}
        ]
    },
    "decision_key": "recycle",
    "expect": {
        "action": "recycle",
        "mission_type": "recycle",
        "unit_ordered": null,
        "outcome": "recycle_mission_queued"
    }
}
```