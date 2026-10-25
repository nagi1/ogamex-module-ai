<?php

namespace Modules\AI\Actions;

use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\GameObjects\Models\UnitObject;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use RuntimeException;

/**
 * Training only (plan/rl/world-sim-spec.md, units): the host's shipyard as tables the fast simulator reads, so the
 * simulator names no ship or defence and holds no formula. Build seconds are the host's own over a grid of the levels
 * that change them, and those objects, the ones that cap how many of a unit a planet may hold and the slots each unit
 * takes are all found by asking the host on a scratch copy of the planet, never by name.
 */
class ExportAiUnitsCatalogueAction
{
    public const VERSION = 1;

    /** Stock given to the scratch planet, so a cap below what it pays for is a rule and not a price. */
    private const PLENTY = 1e12;

    /** The store levels a capacity rule is checked at before the simulator is trusted with it. */
    private const CAPACITY_CHECK_LEVELS = [1, 2, 3, 7];

    /**
     * @param  array<int, PlanetService>  $planets  real planets in order, each a start state; the first is copied as the probe
     * @return array<string, mixed>
     */
    public function handle(array $planets, int $gridLevel): array
    {
        $units = array_values(ObjectService::getUnitObjects());
        $stations = array_values([...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()]);
        $research = array_values(ObjectService::getResearchObjects());
        $planets = array_values($planets);
        $exported = array_map(fn (PlanetService $planet): array => $this->planet($planet, $units), $planets);

        $probe = app(PlanetServiceFactory::class)->make($planets[0]->getPlanetId(), true)
            ?? throw new RuntimeException('The probe planet could not be loaded.');
        $savedResearch = $this->researchLevels($probe, $research);
        $this->clear($probe, $stations, $research, $units);
        $probe->addResources(app()->makeWith(Resources::class, ['metal' => self::PLENTY, 'crystal' => self::PLENTY, 'deuterium' => self::PLENTY]), false);

        $modifiers = $this->timeModifiers($probe, $units, [...$stations, ...$research]);
        $grid = $this->timeGrid($probe, $units, $modifiers, $gridLevel);
        $limits = $this->limits($probe, $units, $stations);
        $production = $this->movesProduction($probe, $units, $stations);

        $this->restoreResearch($probe, $research, $savedResearch);
        app(PlanetServiceFactory::class)->forgetPlanetCache($probe->getPlanetId());
        $modifierIds = array_map(static fn (GameObject $object): int => $object->id, $modifiers);

        return [
            'version' => self::VERSION,
            'economy_speed' => app(SettingsService::class)->economySpeed(),
            'time_grid_level' => $gridLevel,
            'time_modifiers' => $modifierIds,
            // The shipyard and defence controllers refuse an order while an object that sets unit build time is
            // upgrading, and that object cannot start upgrading while an order is unfinished (isObjectUpgradeBlocked).
            'blocked_while_upgrading' => $modifierIds,
            'stores' => $limits['stores'],
            'units' => array_map(fn (UnitObject $unit): array => [
                'id' => $unit->id,
                'machine_name' => $unit->machine_name,
                'type' => $unit->type->value,
                'price' => $this->price($unit),
                'requirements' => array_map(static fn ($requirement): array => [
                    'id' => ObjectService::getObjectByMachineName($requirement->object_machine_name)->id,
                    'level' => $requirement->level,
                ], $unit->requirements),
                'build_seconds_grid' => $grid[$unit->id],
                'owned_limit' => $limits['owned'][$unit->id] ?? null,
                'store' => $limits['store'][$unit->id] ?? null,
                // A unit the host counts in the planet's production or energy couples the shipyard to the economy.
                'moves_production' => $production[$unit->id],
            ], $units),
            'planets' => $exported,
        ];
    }

    /**
     * @param  list<UnitObject>  $units
     * @return array<string, mixed>
     */
    private function planet(PlanetService $planet, array $units): array
    {
        return [
            'planet_id' => $planet->getPlanetId(),
            'player_id' => $planet->getPlayer()?->getId(),
            'class_allowed' => array_values(array_map(static fn (UnitObject $unit): int => $unit->id, array_filter($units, static fn (UnitObject $unit): bool => ObjectService::objectCharacterClassMet($unit->machine_name, $planet)))),
            'units' => array_combine(array_map(static fn (UnitObject $unit): string => (string) $unit->id, $units), array_map(static fn (UnitObject $unit): int => $planet->getObjectAmount($unit->machine_name), $units)),
        ];
    }

    /** @return list<float> */
    private function price(UnitObject $unit): array
    {
        $price = ObjectService::getObjectRawPrice($unit->machine_name);

        return [$price->metal->get(), $price->crystal->get(), $price->deuterium->get()];
    }

    /**
     * Every object whose level changes a unit's build seconds, read off the unit the host takes longest to build so
     * the whole seconds do not hide a small change.
     *
     * @param  list<UnitObject>  $units
     * @param  list<GameObject>  $candidates
     * @return list<GameObject>
     */
    private function timeModifiers(PlanetService $probe, array $units, array $candidates): array
    {
        $seconds = array_map(static fn (UnitObject $unit): int => $probe->getUnitConstructionTime($unit->machine_name), $units);
        $base = max([0, ...$seconds]);
        $reference = $units[(int) array_search($base, $seconds, true)];

        return array_values(array_filter($candidates, function (GameObject $candidate) use ($probe, $reference, $base): bool {
            $this->setLevel($probe, $candidate, 1);
            $moved = $probe->getUnitConstructionTime($reference->machine_name) !== $base;
            $this->setLevel($probe, $candidate, 0);

            return $moved;
        }));
    }

    /**
     * The host's seconds per unit for every combination of modifier levels up to the grid level, the first modifier
     * varying slowest.
     *
     * @param  list<UnitObject>  $units
     * @param  list<GameObject>  $modifiers
     * @return array<int, list<int>>
     */
    private function timeGrid(PlanetService $probe, array $units, array $modifiers, int $gridLevel): array
    {
        $grid = array_fill_keys(array_map(static fn (UnitObject $unit): int => $unit->id, $units), []);
        foreach ($this->combinations(count($modifiers), $gridLevel) as $levels) {
            foreach ($modifiers as $index => $modifier) {
                $this->setLevel($probe, $modifier, $levels[$index]);
            }
            foreach ($units as $unit) {
                $grid[$unit->id][] = $probe->getUnitConstructionTime($unit->machine_name);
            }
        }
        foreach ($modifiers as $modifier) {
            $this->setLevel($probe, $modifier, 0);
        }

        return $grid;
    }

    /**
     * Every assignment of 0..$top to $count positions, the first varying slowest.
     *
     * @return list<list<int>>
     */
    private function combinations(int $count, int $top): array
    {
        $combinations = [[]];
        for ($position = 0; $position < $count; $position++) {
            $combinations = array_merge(...array_map(static fn (array $prefix): array => array_map(static fn (int $level): array => [...$prefix, $level], range(0, $top)), $combinations));
        }

        return $combinations;
    }

    /**
     * The caps the host puts on an order besides its price. A unit the host lets a bare planet hold only a few of has an
     * owned limit; a unit whose cap moves with a building's level shares that building's capacity with the other units
     * it caps, each taking a number of slots measured against the unit that takes fewest.
     *
     * @param  list<UnitObject>  $units
     * @param  list<GameObject>  $stations
     * @return array{owned: array<int, int>, store: array<int, array{id: int, slots: int}>, stores: list<array{id: int, capacity_per_level: int}>}
     */
    private function limits(PlanetService $probe, array $units, array $stations): array
    {
        $owned = [];
        $byStore = [];
        foreach ($units as $unit) {
            $bare = $this->maxAmount($probe, $unit);
            $store = $this->storeOf($probe, $unit, $stations, $bare);
            if ($store !== null) {
                $byStore[$store->id][] = $unit;

                continue;
            }
            if ($bare < $this->priceBound($unit)) {
                $owned[$unit->id] = $bare;
            }
        }

        $store = [];
        $stores = [];
        foreach ($byStore as $storeId => $members) {
            $object = ObjectService::getObjectById($storeId);
            $this->setLevel($probe, $object, 1);
            $atOne = array_map(fn (UnitObject $unit): int => $this->maxAmount($probe, $unit), $members);
            $capacity = max([0, ...$atOne]);
            $reference = $members[(int) array_search($capacity, $atOne, true)];
            foreach ($members as $member) {
                $probe->addUnit($member->machine_name, 1, false);
                $store[$member->id] = ['id' => $storeId, 'slots' => $capacity - $this->maxAmount($probe, $reference)];
                $probe->addUnit($member->machine_name, -1, false);
            }
            $this->assertCapacityIsLinear($probe, $object, $members, $store, $capacity);
            $this->setLevel($probe, $object, 0);
            $stores[] = ['id' => $storeId, 'capacity_per_level' => $capacity];
        }

        return ['owned' => $owned, 'store' => $store, 'stores' => $stores];
    }

    /** The level every building and station holds while production is probed, so each producer and consumer is running. */
    private const PRODUCTION_PROBE_LEVEL = 5;

    /** How many of a unit are held while production is probed. */
    private const PRODUCTION_PROBE_UNITS = 1000;

    /**
     * Whether holding a unit changes what the planet produces or the energy it has, asked of the host with every
     * building and station running.
     *
     * @param  list<UnitObject>  $units
     * @param  list<GameObject>  $stations
     * @return array<int, bool>
     */
    private function movesProduction(PlanetService $probe, array $units, array $stations): array
    {
        foreach ($stations as $station) {
            $this->setLevel($probe, $station, self::PRODUCTION_PROBE_LEVEL);
        }
        $without = $this->production($probe);
        $moves = [];
        foreach ($units as $unit) {
            $probe->addUnit($unit->machine_name, self::PRODUCTION_PROBE_UNITS, false);
            $moves[$unit->id] = $this->production($probe) !== $without;
            $probe->addUnit($unit->machine_name, -self::PRODUCTION_PROBE_UNITS, false);
        }
        foreach ($stations as $station) {
            $this->setLevel($probe, $station, 0);
        }
        $probe->updateResourceProductionStats(false);

        return $moves;
    }

    /** @return list<float> */
    private function production(PlanetService $probe): array
    {
        $probe->updateResourceProductionStats(false);

        return [$probe->getMetalProductionPerHour(), $probe->getCrystalProductionPerHour(), $probe->getDeuteriumProductionPerHour(), $probe->energyProduction()->get(), $probe->energy()->get()];
    }

    /** @param list<GameObject> $stations */
    private function storeOf(PlanetService $probe, UnitObject $unit, array $stations, int $bare): GameObject|null
    {
        foreach ($stations as $station) {
            $this->setLevel($probe, $station, 1);
            $moved = $this->maxAmount($probe, $unit) !== $bare;
            $this->setLevel($probe, $station, 0);
            if ($moved) {
                return $station;
            }
        }

        return null;
    }

    /**
     * The simulator computes a capped amount as floor((capacity × level − slots in use) / slots); a host rule of any
     * other shape must stop the export rather than give the simulator a wrong table.
     *
     * @param  list<UnitObject>  $members
     * @param  array<int, array{id: int, slots: int}>  $store
     */
    private function assertCapacityIsLinear(PlanetService $probe, GameObject $object, array $members, array $store, int $capacity): void
    {
        foreach (self::CAPACITY_CHECK_LEVELS as $level) {
            $this->setLevel($probe, $object, $level);
            foreach ($members as $member) {
                $expected = intdiv($capacity * $level, max(1, $store[$member->id]['slots']));
                if ($this->maxAmount($probe, $member) !== $expected) {
                    throw new RuntimeException(sprintf('The cap of %s at %s %d is not linear in its slots.', $member->machine_name, $object->machine_name, $level));
                }
            }
        }
    }

    private function maxAmount(PlanetService $probe, UnitObject $unit): int
    {
        return ObjectService::getObjectMaxBuildAmount($unit->machine_name, $probe, true);
    }

    private function priceBound(UnitObject $unit): int
    {
        $highest = max([0.0, ...$this->price($unit)]);

        return $highest > 0 ? (int) floor(self::PLENTY / $highest) : PHP_INT_MAX;
    }

    /**
     * Zeroes every building, station, research and unit of the scratch planet, unsaved.
     *
     * @param  list<GameObject>  $stations
     * @param  list<GameObject>  $research
     * @param  list<UnitObject>  $units
     */
    private function clear(PlanetService $probe, array $stations, array $research, array $units): void
    {
        foreach ([...$stations, ...$research] as $object) {
            $this->setLevel($probe, $object, 0);
        }
        foreach ($units as $unit) {
            $probe->addUnit($unit->machine_name, -$probe->getObjectAmount($unit->machine_name), false);
        }
    }

    /** Stations live on the scratch planet; researches live on its owner, which the real planet shares, so they are put back. */
    private function setLevel(PlanetService $probe, GameObject $object, int $level): void
    {
        if ($object->type === GameObjectType::Research) {
            $probe->getPlayer()?->setResearchLevel($object->machine_name, $level, false);

            return;
        }
        $probe->setObjectLevel($object->id, $level, false);
    }

    /**
     * @param  list<GameObject>  $research
     * @return list<int>
     */
    private function researchLevels(PlanetService $probe, array $research): array
    {
        return array_map(static fn (GameObject $object): int => (int) $probe->getPlayer()?->getResearchLevel($object->machine_name), $research);
    }

    /**
     * @param  list<GameObject>  $research
     * @param  list<int>  $levels
     */
    private function restoreResearch(PlanetService $probe, array $research, array $levels): void
    {
        foreach ($research as $index => $object) {
            $probe->getPlayer()?->setResearchLevel($object->machine_name, $levels[$index], false);
        }
    }
}
