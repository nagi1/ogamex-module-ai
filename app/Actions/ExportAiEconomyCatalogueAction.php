<?php

namespace Modules\AI\Actions;

use Modules\AI\Domain\Choice\EconomyChoiceEncoder;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\GameObjects\Models\UnitObject;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use RuntimeException;

/**
 * Training only (plan/rl, ogsim): the host's economy as tables the fast simulator reads, so the simulator holds no
 * number and names no object of its own. Every value is the host's own answer on a real planet: prices, build
 * times, production and storage per level, requirements and field use. Build time is tabulated with every station
 * at zero, and each object that shortens it is found by asking the host, never by name. Researches are tabulated the
 * same way, and the production rows a research changes are found by raising it on the planet and asking again.
 * Units that move production (found by holding them and asking) get their own tables per planet: the row they add as
 * producers, the energy the host counts outside every row, and the change they make to each producer's row, all over
 * the count the host puts to use; every other table is read with no unit on the planet.
 */
class ExportAiEconomyCatalogueAction
{
    public const VERSION = 4;

    /** The level a build-time probe uses: high enough that the host's whole seconds keep the divisor precise. */
    private const PROBE_LEVEL = 40;

    /** Build times are the host's own for every time modifier up to this level; beyond it the divisor is used. */
    private const TIME_GRID_LEVEL = 12;

    /** Production that depends on researches is tabulated for every combination of their levels up to this level. */
    private const RESEARCH_GRID_LEVEL = 12;

    /** Unit tables run over counts up to this many (or up to what the host can put to use at the highest levels). */
    public const UNIT_GRID = 1000;

    /** The level every object holds while the export asks whether holding a unit moves production at all. */
    private const MOVER_PROBE_LEVEL = 5;

    private const MOVER_PROBE_UNITS = 1000;

    /** Level patterns a usable-count rule is checked at before the simulator is trusted with it as linear. */
    private const USABLE_CHECKS = [1, 2, 3, 5];

    /** Level patterns the probes below ask the host at; the first holds every object and every unit at nothing. */
    private const PROBE_STEPS = [0, 1, 3, 7, 11];

    /**
     * @param  list<PlanetService>  $planets  real planets, each becomes one start state of the simulator
     * @return array<string, mixed>
     */
    public function handle(array $planets, int $maxLevel, int $unitGrid = self::UNIT_GRID): array
    {
        $units = array_values(ObjectService::getUnitObjects());
        // Every other table is the host's answer with no unit on the planet; the units are put back at the end.
        $held = array_map(fn (PlanetService $planet): array => $this->takeUnits($planet, $units), $planets);
        $objects = $this->objects($planets[0]);
        $modifiers = $this->timeModifiers($planets[0], $objects, $objects, $objects, $maxLevel);
        $modifierIds = array_column($modifiers, 'id');
        $research = ObjectService::getResearchObjects();
        $everything = [...$objects, ...$research];
        $researchModifiers = $this->timeModifiers($planets[0], $everything, $research, $everything, $maxLevel);
        $researchModifierIds = array_column($researchModifiers, 'id');
        $this->assertStorageIgnoresResearch($planets[0], $everything, $research, $maxLevel);
        $movers = $this->movers($planets, $everything, $units);

        $exported = array_map(fn (PlanetService $planet, array $counts): array => $this->planet($planet, $objects, $everything, $research, $maxLevel) + [
            'unit_counts' => array_map(static fn (UnitObject $unit): int => $counts[$unit->id], $movers),
            // A unit the planet can neither build nor holds is not tabulated there, which keeps the export small.
            'unit_production' => array_map(fn (UnitObject $unit): array => $this->tabulated($planet, $unit, $counts[$unit->id]) ? $this->unitProduction($planet, $unit, $objects, $everything, $maxLevel, $unitGrid) : self::noUnitProduction($unit), $movers),
        ], $planets, $held);
        $probes = $this->unitProbes($planets, $objects, $research, $movers, $maxLevel, $unitGrid);
        foreach ($planets as $index => $planet) {
            $this->putUnits($planet, $held[$index]);
        }

        return [
            'version' => self::VERSION,
            'economy_speed' => app(SettingsService::class)->economySpeed(),
            'max_level' => $maxLevel,
            'time_grid_level' => self::TIME_GRID_LEVEL,
            'research_grid_level' => self::RESEARCH_GRID_LEVEL,
            'objects' => array_map(fn (GameObject $object): array => $this->object($object, $planets[0], $everything, $modifierIds, $maxLevel), $objects),
            'time_modifiers' => $modifiers,
            'research' => array_map(fn (GameObject $object): array => $this->object($object, $planets[0], $everything, $researchModifierIds, $maxLevel), $research),
            'time_modifiers_research' => $researchModifiers,
            // Catalogue v4: the units whose count moves production; every planet's unit lists follow this order.
            'units' => array_map(static fn (UnitObject $unit): int => $unit->id, $movers),
            // Combinations of levels and unit counts with the host's own answer, so the simulator's composition of the
            // per-level and per-count tables is checked against the host and not against itself.
            'unit_probes' => $probes,
            'planets' => $exported,
        ];
    }

    /** @return list<GameObject> every building and station the host lets a planet queue */
    private function objects(PlanetService $planet): array
    {
        $objects = [...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()];

        return array_values(array_filter($objects, static fn (GameObject $object): bool => ObjectService::objectValidPlanetType($object->machine_name, $planet)));
    }

    /**
     * @param  list<GameObject>  $objects  every exported object, buildings and researches, zeroed while a row is read
     * @param  list<int>  $modifierIds
     * @return array<string, mixed>
     */
    private function object(GameObject $object, PlanetService $planet, array $objects, array $modifierIds, int $maxLevel): array
    {
        $prices = [];
        $seconds = [];
        $grid = [];
        $this->withLevels($planet, $objects, [], function () use ($object, $planet, $modifierIds, $maxLevel, &$prices, &$seconds, &$grid): void {
            for ($level = 1; $level <= $maxLevel; $level++) {
                $price = ObjectService::getObjectRawPrice($object->machine_name, $level);
                $prices[] = [$price->metal->get(), $price->crystal->get(), $price->deuterium->get()];
                $grid[] = $this->timeGrid($planet, $object, $level - 1, $modifierIds);
                $seconds[] = $grid[$level - 1][0];
            }
        });

        return [
            'id' => $object->id,
            'machine_name' => $object->machine_name,
            'consumes_field' => $object->type !== GameObjectType::Research && $object->consumesPlanetField,
            // How many objects name this one as a requirement, counted by the encoder the model is served through.
            'unlocks' => app(EconomyChoiceEncoder::class)->unlocks()[$object->machine_name] ?? 0,
            'requirements' => array_map(static fn ($requirement): array => [
                'id' => ObjectService::getObjectByMachineName($requirement->object_machine_name)->id,
                'level' => $requirement->level,
            ], $object->requirements),
            'price' => $prices,
            'build_seconds' => $seconds,
            'build_seconds_grid' => $grid,
        ];
    }

    /**
     * The host's build time of one level for every combination of time-modifier levels up to TIME_GRID_LEVEL, the
     * first modifier varying slowest. Whole seconds cannot be divided back exactly, so the simulator reads these.
     *
     * @param  list<int>  $modifierIds
     * @return list<int>
     */
    private function timeGrid(PlanetService $planet, GameObject $object, int $level, array $modifierIds): array
    {
        return array_map(function (array $levels) use ($planet, $object, $level): int {
            foreach ($levels as $id => $modifierLevel) {
                $this->setLevel($planet, ObjectService::getObjectById($id), $modifierLevel);
            }
            $this->setLevel($planet, $object, $level);

            return $this->constructionSeconds($planet, $object);
        }, $this->combinations($modifierIds, self::TIME_GRID_LEVEL));
    }

    /**
     * Every assignment of 0..$top to the given ids, the first id varying slowest.
     *
     * @param  list<int>  $ids
     * @return list<array<int, int>>
     */
    private function combinations(array $ids, int $top): array
    {
        $combinations = [[]];
        foreach ($ids as $id) {
            $combinations = array_merge(...array_map(static fn (array $prefix): array => array_map(static fn (int $l): array => $prefix + [$id => $l], range(0, $top)), $combinations));
        }

        return $combinations;
    }

    /** Buildings and stations live on the planet, researches on its owner; both are set unsaved. */
    private function setLevel(PlanetService $planet, GameObject $object, int $level): void
    {
        if ($object->type === GameObjectType::Research) {
            $planet->getPlayer()->setResearchLevel($object->machine_name, $level, false);

            return;
        }
        $planet->setObjectLevel($object->id, $level, false);
    }

    private function getLevel(PlanetService $planet, GameObject $object): int
    {
        return $object->type === GameObjectType::Research ? $planet->getPlayer()->getResearchLevel($object->machine_name) : $planet->getObjectLevel($object->machine_name);
    }

    private function constructionSeconds(PlanetService $planet, GameObject $object): int
    {
        return $object->type === GameObjectType::Research ? (int) $planet->getTechnologyResearchTime($object->machine_name) : $planet->getBuildingConstructionTime($object->machine_name);
    }

    /**
     * Objects whose level divides every build time, with the divisor per level, read from the host's own times.
     *
     * @param  list<GameObject>  $objects  everything zeroed during a probe
     * @param  list<GameObject>  $timed  the objects whose time is probed; one of them is raised to PROBE_LEVEL as the reference
     * @param  list<GameObject>  $candidates  the objects tried as modifiers
     * @return list<array{id: int, divisor: list<float>}>
     */
    private function timeModifiers(PlanetService $planet, array $objects, array $timed, array $candidates, int $maxLevel): array
    {
        $modifiers = [];
        foreach ($candidates as $modifier) {
            $reference = $this->referenceObject($timed, $modifier);
            $base = $this->probeSeconds($planet, $objects, $reference, $modifier, 0);
            if ($this->probeSeconds($planet, $objects, $reference, $modifier, 1) === $base) {
                continue;
            }
            $divisor = [];
            for ($level = 0; $level <= $maxLevel; $level++) {
                $divisor[] = $base / max(1, $this->probeSeconds($planet, $objects, $reference, $modifier, $level));
            }
            $modifiers[] = ['id' => $modifier->id, 'divisor' => $divisor];
        }

        return $modifiers;
    }

    /** @param list<GameObject> $objects */
    private function referenceObject(array $objects, GameObject $modifier): GameObject
    {
        $others = array_values(array_filter($objects, static fn (GameObject $object): bool => $object->id !== $modifier->id));

        return $others[0];
    }

    /** @param list<GameObject> $objects */
    private function probeSeconds(PlanetService $planet, array $objects, GameObject $reference, GameObject $modifier, int $level): int
    {
        return $this->withLevels($planet, $objects, [$reference->id => self::PROBE_LEVEL, $modifier->id => $level], fn (): int => $this->constructionSeconds($planet, $reference));
    }

    /**
     * @param  list<GameObject>  $objects  buildings and stations
     * @param  list<GameObject>  $everything  buildings, stations and researches
     * @param  list<GameObject>  $research
     * @return array<string, mixed>
     */
    private function planet(PlanetService $planet, array $objects, array $everything, array $research, int $maxLevel): array
    {
        $income = $planet->getPlanetBasicIncome();
        $resources = $planet->getResources();
        $exported = array_map(static fn (GameObject $object): int => $object->id, $objects);
        $production = [];
        foreach (ObjectService::getGameObjectsWithProduction() as $producer) {
            if (! in_array($producer->id, $exported, true)) {
                continue;
            }
            $production[(string) $producer->id] = $this->withLevels($planet, $objects, [], static function () use ($planet, $producer, $maxLevel): array {
                $rows = [];
                for ($level = 0; $level <= $maxLevel; $level++) {
                    $planet->setObjectLevel($producer->id, $level, false);
                    $made = $planet->getObjectProduction($producer->machine_name, $level, true);
                    $rows[] = [$made->metal->get(), $made->crystal->get(), $made->deuterium->get(), $made->energy->get()];
                }

                return $rows;
            });
        }

        return [
            'planet_id' => $planet->getPlanetId(),
            'position' => $planet->getPlanetCoordinates()->position,
            'temperature' => $planet->getPlanetTempAvg(),
            'field_max' => $planet->getPlanetFieldMax(),
            'basic_income' => [$income->metal->get(), $income->crystal->get(), $income->deuterium->get(), $income->energy->get()],
            'resources' => [$resources->metal->get(), $resources->crystal->get(), $resources->deuterium->get()],
            'levels' => array_combine(array_map('strval', $exported), array_map(static fn (GameObject $object): int => $planet->getObjectLevel($object->machine_name), $objects))
                + array_combine(array_map(static fn (GameObject $object): string => (string) $object->id, $research), array_map(fn (GameObject $object): int => $this->getLevel($planet, $object), $research)),
            'production' => $production,
            'production_research' => $this->productionResearch($planet, $everything, $research, $objects, $maxLevel),
            'storage' => $this->storage($planet, $objects, $maxLevel),
        ];
    }

    /**
     * The production rows a research changes, found by raising each research one level and asking the host again; a
     * research that moves no row never appears. Rows are indexed [producer level][combination of the `by` levels].
     *
     * @param  list<GameObject>  $everything
     * @param  list<GameObject>  $research
     * @param  list<GameObject>  $buildings
     * @return array<string, array{by: list<int>, rows: list<list<list<float>>>}>
     */
    private function productionResearch(PlanetService $planet, array $everything, array $research, array $buildings, int $maxLevel): array
    {
        $exported = array_map(static fn (GameObject $object): int => $object->id, $buildings);
        $tabulated = [];
        foreach (ObjectService::getGameObjectsWithProduction() as $producer) {
            if (! in_array($producer->id, $exported, true)) {
                continue;
            }
            $by = array_values(array_map(static fn (GameObject $r): int => $r->id, array_filter($research, fn (GameObject $r): bool => $this->researchMovesProduction($planet, $everything, $producer, $r, $maxLevel))));
            if ($by === []) {
                continue;
            }
            $tabulated[(string) $producer->id] = ['by' => $by, 'rows' => $this->productionRows($planet, $everything, $producer, $by, $maxLevel)];
        }

        return $tabulated;
    }

    /** @param list<GameObject> $everything */
    private function researchMovesProduction(PlanetService $planet, array $everything, GameObject $producer, GameObject $research, int $maxLevel): bool
    {
        foreach ([1, $maxLevel] as $level) {
            $without = $this->withLevels($planet, $everything, [$producer->id => $level], fn (): array => $this->productionRow($planet, $producer, $level));
            $with = $this->withLevels($planet, $everything, [$producer->id => $level, $research->id => 1], fn (): array => $this->productionRow($planet, $producer, $level));
            if ($with !== $without) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<GameObject>  $everything
     * @param  list<int>  $by
     * @return list<list<list<float>>>
     */
    private function productionRows(PlanetService $planet, array $everything, GameObject $producer, array $by, int $maxLevel): array
    {
        return $this->withLevels($planet, $everything, [], function () use ($planet, $producer, $by, $maxLevel): array {
            $combinations = $this->combinations($by, self::RESEARCH_GRID_LEVEL);
            $rows = [];
            for ($level = 0; $level <= $maxLevel; $level++) {
                $planet->setObjectLevel($producer->id, $level, false);
                $row = [];
                foreach ($combinations as $levels) {
                    foreach ($levels as $id => $researchLevel) {
                        $this->setLevel($planet, ObjectService::getObjectById($id), $researchLevel);
                    }
                    $row[] = $this->productionRow($planet, $producer, $level);
                }
                $rows[] = $row;
            }

            return $rows;
        });
    }

    /** @return list<float> */
    private function productionRow(PlanetService $planet, GameObject $producer, int $level): array
    {
        $planet->setObjectLevel($producer->id, $level, false);

        return $this->asRow($planet->getObjectProduction($producer->machine_name, $level, true));
    }

    /** @return list<float> */
    private function asRow(Resources $made): array
    {
        return [$made->metal->get(), $made->crystal->get(), $made->deuterium->get(), $made->energy->get()];
    }

    /**
     * The simulator tabulates storage by building level alone, so a research that ever moved it would break parity.
     *
     * @param  list<GameObject>  $everything
     * @param  list<GameObject>  $research
     */
    private function assertStorageIgnoresResearch(PlanetService $planet, array $everything, array $research, int $maxLevel): void
    {
        foreach (ObjectService::getBuildingObjectsWithStorage() as $building) {
            foreach ($research as $candidate) {
                $held = fn (int $researchLevel): array => $this->withLevels($planet, $everything, [$building->id => $maxLevel, $candidate->id => $researchLevel], static function () use ($planet, $building, $maxLevel): array {
                    $stored = $planet->getBuildingMaxStorage($building->machine_name, $maxLevel);

                    return [$stored->metal->get(), $stored->crystal->get(), $stored->deuterium->get()];
                });
                if ($held(0) !== $held(1)) {
                    throw new RuntimeException(sprintf('Storage of %s depends on %s; the catalogue has no table for that.', $building->machine_name, $candidate->machine_name));
                }
            }
        }
    }

    /**
     * @param  list<GameObject>  $objects
     * @return array<string, list<list<float>>>
     */
    private function storage(PlanetService $planet, array $objects, int $maxLevel): array
    {
        $storage = [];
        foreach (ObjectService::getBuildingObjectsWithStorage() as $building) {
            $storage[(string) $building->id] = $this->withLevels($planet, $objects, [], static function () use ($planet, $building, $maxLevel): array {
                $rows = [];
                for ($level = 0; $level <= $maxLevel; $level++) {
                    // The host reads level 0 as "the current level", so the planet holds the level being asked for.
                    $planet->setObjectLevel($building->id, $level, false);
                    $held = $planet->getBuildingMaxStorage($building->machine_name, $level);
                    $rows[] = [$held->metal->get(), $held->crystal->get(), $held->deuterium->get()];
                }

                return $rows;
            });
        }

        return $storage;
    }

    /**
     * @param  list<UnitObject>  $units
     * @return array<int, int> what the planet held, by unit id; it now holds none
     */
    private function takeUnits(PlanetService $planet, array $units): array
    {
        $held = [];
        foreach ($units as $unit) {
            $held[$unit->id] = $planet->getObjectAmount($unit->machine_name);
            $this->setAmount($planet, $unit, 0);
        }

        return $held;
    }

    /** @param array<int, int> $held */
    private function putUnits(PlanetService $planet, array $held): void
    {
        foreach ($held as $id => $amount) {
            $this->setAmount($planet, ObjectService::getUnitObjectById($id), $amount);
        }
        $planet->updateResourceProductionStats(false);
    }

    private function setAmount(PlanetService $planet, UnitObject $unit, int $amount): void
    {
        $planet->addUnit($unit->machine_name, $amount - $planet->getObjectAmount($unit->machine_name), false);
    }

    /**
     * The units whose holding changes any planet's production or energy, asked of the host with every object running.
     *
     * @param  list<PlanetService>  $planets
     * @param  list<GameObject>  $everything
     * @param  list<UnitObject>  $units
     * @return list<UnitObject>
     */
    private function movers(array $planets, array $everything, array $units): array
    {
        $levels = array_fill_keys(array_map(static fn (GameObject $object): int => $object->id, $everything), self::MOVER_PROBE_LEVEL);

        return array_values(array_filter($units, function (UnitObject $unit) use ($planets, $everything, $levels): bool {
            foreach ($planets as $planet) {
                $moved = $this->withLevels($planet, $everything, $levels, function () use ($planet, $unit): bool {
                    $without = $this->totals($planet);
                    $this->setAmount($planet, $unit, self::MOVER_PROBE_UNITS);
                    $with = $this->totals($planet);
                    $this->setAmount($planet, $unit, 0);

                    return $with !== $without;
                });
                if ($moved) {
                    return true;
                }
            }

            return false;
        }));
    }

    /** @return list<float> the planet's rates and energy as the host stores them */
    private function totals(PlanetService $planet): array
    {
        $planet->updateResourceProductionStats(false);

        return [$planet->getMetalProductionPerHour(), $planet->getCrystalProductionPerHour(), $planet->getDeuteriumProductionPerHour(), $planet->energyProduction()->get(), $planet->energyConsumption()->get()];
    }

    /**
     * A unit's table is exported on a planet that can build it or already holds some; elsewhere it changes nothing
     * there and the simulator reads no table as no effect, which is why a probe never holds one there either.
     */
    private function tabulated(PlanetService $planet, UnitObject $unit, int $held): bool
    {
        return $held > 0 || ObjectService::objectCharacterClassMet($unit->machine_name, $planet);
    }

    /**
     * A unit's table on a planet it can neither build nor holds: it changes nothing there, which reads as no row, no
     * bound and no energy rather than as a missing table the simulator would have to special-case.
     *
     * @return array<string, mixed>
     */
    private static function noUnitProduction(UnitObject $unit): array
    {
        return ['id' => $unit->id, 'usable' => null, 'own' => null, 'energy' => [], 'producers' => []];
    }

    /**
     * One unit's tables on one planet, every row indexed by the count the host puts to use (the held count, capped by
     * `usable` when the host bounds it): `own` is its row as a producer, `energy` the [produced, used] energy the
     * host counts outside every producer row, and `producers` the change it makes to a producer's row
     * [level][component][count].
     *
     * @param  list<GameObject>  $objects
     * @param  list<GameObject>  $everything
     * @return array<string, mixed>
     */
    private function unitProduction(PlanetService $planet, UnitObject $unit, array $objects, array $everything, int $maxLevel, int $grid): array
    {
        $usable = $this->usable($planet, $unit, $everything, $maxLevel);
        $top = $usable === null ? $grid : min($grid, array_sum($usable) * $maxLevel);
        // While a table is read, every object that bounds the usable count stands at the top, so the count is the held one.
        $full = $usable === null ? [] : array_map(static fn (): int => $maxLevel, $usable);
        $producerIds = array_map(static fn (GameObject $object): int => $object->id, ObjectService::getGameObjectsWithProduction());

        return [
            'id' => $unit->id,
            'usable' => $usable === null ? null : array_map(static fn (int $id, int $perLevel): array => ['id' => $id, 'per_level' => $perLevel], array_keys($usable), array_values($usable)),
            'own' => in_array($unit->id, $producerIds, true) ? $this->withLevels($planet, $everything, $full, fn (): array => $this->overCounts($planet, $unit, $top, fn (): array => $this->asRow($planet->getObjectProduction($unit->machine_name, null, true)))) : null,
            'energy' => $this->withLevels($planet, $everything, $full, fn (): array => $this->overCounts($planet, $unit, $top, fn (): array => $this->energyOutsideRows($planet))),
            'producers' => $this->movedProducers($planet, $unit, $objects, $everything, $full, $maxLevel, $top),
        ];
    }

    /**
     * How many of a unit the host puts to use per level of each object, or null when it sets no bound. The simulator
     * reads the bound as the sum of level times that number, so a rule of any other shape stops the export.
     *
     * @param  list<GameObject>  $everything
     * @return array<int, int>|null
     */
    private function usable(PlanetService $planet, UnitObject $unit, array $everything, int $maxLevel): array|null
    {
        $cap = fn (array $levels): int|null => $this->withLevels($planet, $everything, $levels, static fn (): int|null => $planet->getUsableUnitCap($unit->machine_name));
        if ($cap([]) === null) {
            return null;
        }
        $perLevel = [];
        foreach ($everything as $object) {
            $usable = (int) $cap([$object->id => 1]);
            if ($usable !== 0) {
                $perLevel[$object->id] = $usable;
            }
        }
        foreach (self::USABLE_CHECKS as $step) {
            $levels = [];
            foreach ($everything as $index => $object) {
                $levels[$object->id] = ($index * $step + $step) % ($maxLevel + 1);
            }
            $expected = array_sum(array_map(static fn (int $id, int $per): int => $per * $levels[$id], array_keys($perLevel), array_values($perLevel)));
            if ($cap($levels) !== $expected) {
                throw new RuntimeException(sprintf('The usable count of %s is not linear in object levels.', $unit->machine_name));
            }
        }

        return $perLevel;
    }

    /**
     * @param  callable(): list<float>  $read
     * @return list<list<float>> the read at every held count from 0 to $top
     */
    private function overCounts(PlanetService $planet, UnitObject $unit, int $top, callable $read): array
    {
        $rows = [];
        for ($count = 0; $count <= $top; $count++) {
            $this->setAmount($planet, $unit, $count);
            $rows[] = $read();
        }
        $this->setAmount($planet, $unit, 0);

        return $rows;
    }

    /** @return list<float> [produced, used]: the planet's energy less basic income and every producer's own row */
    private function energyOutsideRows(PlanetService $planet): array
    {
        $planet->updateResourceProductionStats(false);
        $income = $planet->getPlanetBasicIncome()->energy->get();
        $made = max(0.0, $income);
        $used = max(0.0, -$income);
        foreach (ObjectService::getGameObjectsWithProduction() as $producer) {
            $energy = $planet->getObjectProduction($producer->machine_name, null, true)->energy->get();
            $made += max(0.0, $energy);
            $used += max(0.0, -$energy);
        }

        return [$planet->energyProduction()->get() - $made, $planet->energyConsumption()->get() - $used];
    }

    /**
     * The change the unit makes to each exported producer's row, [producer level][component][count], for the producers
     * it moves. A component it never moves is an empty list, and a level's counts stop at what the host puts to use
     * with the producer at that level and every other bounding object at the top.
     *
     * @param  list<GameObject>  $objects
     * @param  list<GameObject>  $everything
     * @param  array<int, int>  $full
     * @return list<array{id: int, rows: list<list<list<float>>>}>
     */
    private function movedProducers(PlanetService $planet, UnitObject $unit, array $objects, array $everything, array $full, int $maxLevel, int $top): array
    {
        $exported = array_map(static fn (GameObject $object): int => $object->id, $objects);
        $moved = [];
        foreach (ObjectService::getGameObjectsWithProduction() as $producer) {
            if (! in_array($producer->id, $exported, true)) {
                continue;
            }
            $change = fn (int $level): array => $this->withLevels($planet, $everything, [$producer->id => $level] + $full, function () use ($planet, $unit, $producer, $level, $full, $top): array {
                $usable = $full === [] ? $top : min($top, (int) $planet->getUsableUnitCap($unit->machine_name));

                return $this->changeByComponent($this->overCounts($planet, $unit, $usable, fn (): array => $this->productionRow($planet, $producer, $level)));
            });
            if (array_merge(...$change(1), ...$change($maxLevel)) === []) {
                continue;
            }
            $moved[] = ['id' => $producer->id, 'rows' => array_map($change, range(0, $maxLevel))];
        }

        return $moved;
    }

    /**
     * @param  list<list<float>>  $rows  a row per count, the first with no unit
     * @return list<list<float>> per component, the change at every count, or an empty list when it never changes
     */
    private function changeByComponent(array $rows): array
    {
        return array_map(static function (int $component) use ($rows): array {
            $change = array_map(static fn (array $row): float => $row[$component] - $rows[0][$component], $rows);

            return array_filter($change) === [] ? [] : $change;
        }, range(0, 3));
    }

    /**
     * The host's rates and energy at level patterns and unit counts its tables are not tabulated at, so the simulator
     * is judged on how it combines the tables, which is the part of production no table can hold. Levels stay inside
     * the grids: objects up to `maxLevel`, researches up to `RESEARCH_GRID_LEVEL`.
     *
     * @param  list<PlanetService>  $planets
     * @param  list<GameObject>  $objects
     * @param  list<GameObject>  $research
     * @param  list<UnitObject>  $movers
     * @return list<array<string, mixed>>
     */
    private function unitProbes(array $planets, array $objects, array $research, array $movers, int $maxLevel, int $unitGrid): array
    {
        $researchIds = array_flip(array_map(static fn (GameObject $object): int => $object->id, $research));
        $everything = [...$objects, ...$research];
        $probes = [];
        foreach ($planets as $index => $planet) {
            foreach (self::PROBE_STEPS as $step) {
                $levels = [];
                foreach ($everything as $at => $object) {
                    $ceiling = isset($researchIds[$object->id]) ? self::RESEARCH_GRID_LEVEL : $maxLevel;
                    $levels[$object->id] = ($at * $step + $step) % ($ceiling + 1);
                }
                [$counts, $answer] = $this->withLevels($planet, $everything, $levels, function () use ($planet, $movers, $step, $unitGrid): array {
                    $counts = [];
                    foreach ($movers as $unit) {
                        $bound = min($unitGrid, $planet->getUsableUnitCap($unit->machine_name) ?? $unitGrid);
                        $counts[$unit->id] = $this->tabulated($planet, $unit, 0) ? ($step * 37) % ($bound + 1) : 0;
                        $this->setAmount($planet, $unit, $counts[$unit->id]);
                    }
                    $answer = [...$this->totals($planet), $planet->getResourceProductionFactor()];
                    foreach ($movers as $unit) {
                        $this->setAmount($planet, $unit, 0);
                    }

                    return [$counts, $answer];
                });
                $probes[] = ['planet' => $index, 'levels' => $levels, 'units' => $counts, 'answer' => $answer];
            }
        }

        return $probes;
    }

    /**
     * Runs a probe with every exported object at zero except the given levels, then puts the planet back unsaved.
     *
     * @template T
     *
     * @param  list<GameObject>  $objects
     * @param  array<int, int>  $levels
     * @param  callable(): T  $probe
     * @return T
     */
    private function withLevels(PlanetService $planet, array $objects, array $levels, callable $probe): mixed
    {
        $saved = [];
        foreach ($objects as $object) {
            $saved[$object->id] = $this->getLevel($planet, $object);
            $this->setLevel($planet, $object, $levels[$object->id] ?? 0);
        }
        $result = $probe();
        foreach ($objects as $object) {
            $this->setLevel($planet, $object, $saved[$object->id]);
        }

        return $result;
    }
}
