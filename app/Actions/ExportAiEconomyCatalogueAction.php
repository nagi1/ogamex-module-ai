<?php

namespace Modules\AI\Actions;

use Modules\AI\Domain\Choice\EconomyChoiceEncoder;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\Enums\GameObjectType;
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
 */
class ExportAiEconomyCatalogueAction
{
    public const VERSION = 3;

    /** The level a build-time probe uses: high enough that the host's whole seconds keep the divisor precise. */
    private const PROBE_LEVEL = 40;

    /** Build times are the host's own for every time modifier up to this level; beyond it the divisor is used. */
    private const TIME_GRID_LEVEL = 12;

    /** Production that depends on researches is tabulated for every combination of their levels up to this level. */
    private const RESEARCH_GRID_LEVEL = 12;

    /**
     * @param  list<PlanetService>  $planets  real planets, each becomes one start state of the simulator
     * @return array<string, mixed>
     */
    public function handle(array $planets, int $maxLevel): array
    {
        $objects = $this->objects($planets[0]);
        $modifiers = $this->timeModifiers($planets[0], $objects, $objects, $objects, $maxLevel);
        $modifierIds = array_column($modifiers, 'id');
        $research = ObjectService::getResearchObjects();
        $everything = [...$objects, ...$research];
        $researchModifiers = $this->timeModifiers($planets[0], $everything, $research, $everything, $maxLevel);
        $researchModifierIds = array_column($researchModifiers, 'id');
        $this->assertStorageIgnoresResearch($planets[0], $everything, $research, $maxLevel);

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
            'planets' => array_map(fn (PlanetService $planet): array => $this->planet($planet, $objects, $everything, $research, $maxLevel), $planets),
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
        $made = $planet->getObjectProduction($producer->machine_name, $level, true);

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
