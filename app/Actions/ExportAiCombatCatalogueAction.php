<?php

namespace Modules\AI\Actions;

use OGame\GameMissions\EspionageMission;
use OGame\GameMissions\RecycleMission;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\UnitObject;
use OGame\Services\CharacterClassService;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

/**
 * Training only (plan/rl, M8 raids in ogsim): every ship and defence the host has, with the combat and flight values
 * both host battle engines feed a fight (`properties->...->calculate($player)`), so the raid simulator names no unit
 * and holds no formula. Each value is tabulated over the levels of the researches that move it, and those researches
 * are found by raising each one and asking the host again, never by name.
 */
class ExportAiCombatCatalogueAction
{
    public const VERSION = 1;

    /** The properties the engines and the fleet dispatch read through `calculate($player)`. */
    private const CALCULATED = ['structural_integrity', 'shield', 'attack', 'capacity', 'speed', 'fuel_capacity'];

    /** Every slot a fleet can target in a system; the last is deep space (expedition debris). */
    private const POSITIONS = 16;

    /**
     * @param  PlayerService  $player  the owner whose class bonuses apply; its research levels are restored unsaved
     * @return array<string, mixed>
     */
    public function handle(PlayerService $player, int $maxLevel): array
    {
        $research = array_values(ObjectService::getResearchObjects());
        $levels = $this->levels($player, $research);
        $classes = app(CharacterClassService::class);
        $settings = app(SettingsService::class);

        return [
            'version' => self::VERSION,
            'max_level' => $maxLevel,
            'player_id' => $player->getId(),
            'research' => array_combine(array_map(static fn (GameObject $object): string => (string) $object->id, $research), $levels),
            'rules' => [
                'loot_percentage' => (int) ($classes->getInactiveLootPercentage($player->getUser()) * 100),
                'debris_ships_percentage' => $settings->debrisFieldFromShips(),
                'debris_defense_percentage' => $settings->debrisFieldFromDefense(),
                'debris_deuterium' => (bool) $settings->debrisFieldDeuteriumOn(),
                'defense_repair_rate' => $settings->defenseRepairRate(),
                'maximum_moon_chance' => $settings->maximumMoonChance(),
                'fleet_speed_war' => $settings->fleetSpeedWar(),
                'deuterium_consumption' => $settings->deuteriumConsumption() * $classes->getDeuteriumConsumptionMultiplier($player->getUser()),
                'reaper_debris_percentage' => (int) ($classes->getReaperDebrisCollectionPercentage($player->getUser()) * 100),
                // ponytail: the host names the unit that hauls debris in by machine name only (AttackMission); if it ever
                // grows a query for "which unit collects debris", ask that instead of the name.
                'debris_collector' => ObjectService::getShipObjectByMachineName('reaper')->id,
            ],
            'units' => array_map(fn (UnitObject $unit): array => $this->unit($unit, $player, $research, $maxLevel), ObjectService::getUnitObjects()),
            'missions' => $this->missions(),
        ];
    }

    /**
     * The units and research the mission classes read, by id: the ships an espionage fleet needs, the research
     * whose levels decide counter-espionage and the report's depth, and the ship that harvests debris at each slot.
     *
     * @return array<string, mixed>
     */
    private function missions(): array
    {
        $harvesters = [];
        for ($position = 1; $position <= self::POSITIONS; $position++) {
            $harvesters[(string) $position] = ObjectService::getUnitObjectByMachineName(RecycleMission::getHarvesterMachineNameForPosition($position))->id;
        }

        return [
            'espionage_units' => array_map(static fn (string $name): int => ObjectService::getUnitObjectByMachineName($name)->id, EspionageMission::getRequiredShipMachineNames()),
            // EspionageMission reads this research by name and exposes no accessor for it.
            'espionage_research' => ObjectService::getResearchObjectByMachineName('espionage_technology')->id,
            'harvesters' => $harvesters,
        ];
    }

    /**
     * @param  list<GameObject>  $research
     * @return array<string, mixed>
     */
    private function unit(UnitObject $unit, PlayerService $player, array $research, int $maxLevel): array
    {
        $price = $unit->price->resources;
        $rapidfire = [];
        foreach ($unit->rapidfire as $shot) {
            $rapidfire[(string) ObjectService::getUnitObjectByMachineName($shot->object_machine_name)->id] = $shot->amount;
        }

        $properties = [];
        foreach (self::CALCULATED as $name) {
            $properties[$name] = $this->table($player, $research, $maxLevel, static fn (): int => $unit->properties->{$name}->calculate($player)->totalValue);
        }

        return [
            'id' => $unit->id,
            'machine_name' => $unit->machine_name,
            'type' => $unit->type->value,
            'price' => [$price->metal->get(), $price->crystal->get(), $price->deuterium->get()],
            'rapidfire' => $rapidfire,
            // The host's fuel formula reads the raw value, not a calculated one (FleetMissionService::calculateConsumption).
            'fuel' => $unit->properties->fuel->rawValue,
            'properties' => $properties,
        ];
    }

    /**
     * The value for every combination of the levels of the researches that move it, the first varying slowest; a
     * value no research moves is a single cell.
     *
     * @param  list<GameObject>  $research
     * @param  callable(): int  $value
     * @return array{by: list<int>, values: list<int>}
     */
    private function table(PlayerService $player, array $research, int $maxLevel, callable $value): array
    {
        $by = [];
        foreach ($research as $candidate) {
            $low = $this->withResearch($player, $research, [], $value);
            if ($this->withResearch($player, $research, [$candidate->id => $maxLevel], $value) !== $low) {
                $by[] = $candidate->id;
            }
        }

        $values = array_map(fn (array $levels): int => $this->withResearch($player, $research, $levels, $value), $this->combinations($by, $maxLevel));

        return ['by' => $by, 'values' => $values];
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
            $combinations = array_merge(...array_map(static fn (array $prefix): array => array_map(static fn (int $level): array => $prefix + [$id => $level], range(0, $top)), $combinations));
        }

        return $combinations;
    }

    /**
     * @param  list<GameObject>  $research
     * @return list<int>
     */
    private function levels(PlayerService $player, array $research): array
    {
        return array_map(static fn (GameObject $object): int => $player->getResearchLevel($object->machine_name), $research);
    }

    /**
     * Runs a probe with every research at zero except the given levels, then puts the player back unsaved.
     *
     * @param  list<GameObject>  $research
     * @param  array<int, int>  $levels
     * @param  callable(): int  $probe
     */
    private function withResearch(PlayerService $player, array $research, array $levels, callable $probe): int
    {
        $saved = $this->levels($player, $research);
        foreach ($research as $object) {
            $player->setResearchLevel($object->machine_name, $levels[$object->id] ?? 0, false);
        }
        $result = $probe();
        foreach ($research as $index => $object) {
            $player->setResearchLevel($object->machine_name, $saved[$index], false);
        }

        return $result;
    }
}
