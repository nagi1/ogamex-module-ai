<?php

namespace Modules\AI\Actions;

use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;

/**
 * Training only (plan/rl, ogsim): the host's economy as tables the fast simulator reads, so the simulator holds no
 * number and names no object of its own. Every value is the host's own answer on a real planet: prices, build
 * times, production and storage per level, requirements and field use. Build time is tabulated with every station
 * at zero, and each object that shortens it is found by asking the host, never by name.
 */
class ExportAiEconomyCatalogueAction
{
    public const VERSION = 1;

    /** The level a build-time probe uses: high enough that the host's whole seconds keep the divisor precise. */
    private const PROBE_LEVEL = 40;

    /** Build times are the host's own for every time modifier up to this level; beyond it the divisor is used. */
    private const TIME_GRID_LEVEL = 12;

    /**
     * @param  list<PlanetService>  $planets  real planets, each becomes one start state of the simulator
     * @return array<string, mixed>
     */
    public function handle(array $planets, int $maxLevel): array
    {
        $objects = $this->objects($planets[0]);
        $modifiers = $this->timeModifiers($planets[0], $objects, $maxLevel);
        $modifierIds = array_column($modifiers, 'id');

        return [
            'version' => self::VERSION,
            'economy_speed' => app(SettingsService::class)->economySpeed(),
            'max_level' => $maxLevel,
            'time_grid_level' => self::TIME_GRID_LEVEL,
            'objects' => array_map(fn (GameObject $object): array => $this->object($object, $planets[0], $objects, $modifierIds, $maxLevel), $objects),
            'time_modifiers' => $modifiers,
            'planets' => array_map(fn (PlanetService $planet): array => $this->planet($planet, $objects, $maxLevel), $planets),
        ];
    }

    /** @return list<GameObject> every building and station the host lets a planet queue */
    private function objects(PlanetService $planet): array
    {
        $objects = [...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()];

        return array_values(array_filter($objects, static fn (GameObject $object): bool => ObjectService::objectValidPlanetType($object->machine_name, $planet)));
    }

    /**
     * @param  list<GameObject>  $objects
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
            'consumes_field' => $object->consumesPlanetField,
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
        $combinations = [[]];
        foreach ($modifierIds as $id) {
            $combinations = array_merge(...array_map(static fn (array $prefix): array => array_map(static fn (int $l): array => $prefix + [$id => $l], range(0, self::TIME_GRID_LEVEL)), $combinations));
        }

        return array_map(static function (array $levels) use ($planet, $object, $level): int {
            foreach ($levels as $id => $modifierLevel) {
                $planet->setObjectLevel($id, $modifierLevel, false);
            }
            $planet->setObjectLevel($object->id, $level, false);

            return $planet->getBuildingConstructionTime($object->machine_name);
        }, $combinations);
    }

    /**
     * Objects whose level divides every build time, with the divisor per level, read from the host's own times.
     *
     * @param  list<GameObject>  $objects
     * @return list<array{id: int, divisor: list<float>}>
     */
    private function timeModifiers(PlanetService $planet, array $objects, int $maxLevel): array
    {
        $modifiers = [];
        foreach ($objects as $modifier) {
            $reference = $this->referenceObject($objects, $modifier);
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
        return $this->withLevels($planet, $objects, [$reference->id => self::PROBE_LEVEL, $modifier->id => $level], static fn (): int => $planet->getBuildingConstructionTime($reference->machine_name));
    }

    /**
     * @param  list<GameObject>  $objects
     * @return array<string, mixed>
     */
    private function planet(PlanetService $planet, array $objects, int $maxLevel): array
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
            'levels' => array_combine(array_map('strval', $exported), array_map(static fn (GameObject $object): int => $planet->getObjectLevel($object->machine_name), $objects)),
            'production' => $production,
            'storage' => $this->storage($planet, $objects, $maxLevel),
        ];
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
            $saved[$object->id] = $planet->getObjectLevel($object->machine_name);
            $planet->setObjectLevel($object->id, $levels[$object->id] ?? 0, false);
        }
        $result = $probe();
        foreach ($saved as $id => $level) {
            $planet->setObjectLevel($id, $level, false);
        }

        return $result;
    }
}
