<?php

namespace Modules\AI\Actions;

use OGame\GameMissions\EspionageMission;
use OGame\GameMissions\MissileMission;
use OGame\GameMissions\MoonDestructionMission;
use OGame\GameMissions\RecycleMission;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\UnitObject;
use OGame\Services\CharacterClassService;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use ReflectionMethod;

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
            'strike' => $this->strike($player, $research, $maxLevel),
            'expedition' => $this->expedition($player),
        ];
    }

    /** The priority codes the missile form sends (MissileMission::decodePriority knows 0..9; anything else is the cheapest). */
    private const MISSILE_PRIORITY_CODES = 10;

    /**
     * What the missile and moon-destruction missions read, by id, so the strike rules (`rl/ogsim/src/strike.rs`) name no
     * unit: the missile and the interceptor, the moon destroyer, the defences in the order the mission walks them with
     * the structure and price it reads, the priority codes, the research behind range and damage, and the range per
     * impulse level (asked of the host, not derived). The damage and chance constants are literals in the mission
     * classes (no accessor), so they are written here once and `CombatCatalogueExportTest` checks them against the host.
     *
     * @param  list<GameObject>  $research
     * @return array<string, mixed>
     */
    private function strike(PlayerService $player, array $research, int $maxLevel): array
    {
        $priorities = new ReflectionMethod(MissileMission::class, 'decodePriority');
        $mission = app(MissileMission::class);
        $modes = [];
        for ($code = 0; $code < self::MISSILE_PRIORITY_CODES; $code++) {
            $name = $priorities->invoke($mission, $code);
            $modes[] = in_array($name, ['cheapest', 'expensive'], true) ? $name : ObjectService::getUnitObjectByMachineName($name)->id;
        }

        $range = [];
        $impulse = ObjectService::getResearchObjectByMachineName('impulse_drive');
        for ($level = 0; $level <= $maxLevel; $level++) {
            $range[] = $this->withResearch($player, $research, [$impulse->id => $level], static fn (): int => $player->getMissileRange());
        }

        return [
            // MissileMission and GalaxyController::missileAttack read these two defences and these three researches by name.
            'missile' => ObjectService::getUnitObjectByMachineName('interplanetary_missile')->id,
            'interceptor' => ObjectService::getUnitObjectByMachineName('anti_ballistic_missile')->id,
            'weapon_research' => ObjectService::getResearchObjectByMachineName('weapon_technology')->id,
            'armour_research' => ObjectService::getResearchObjectByMachineName('armor_technology')->id,
            'range_research' => $impulse->id,
            'range_by_level' => $range,
            'priorities' => $modes,
            'defences' => array_map(static fn (UnitObject $defense): array => [
                'id' => $defense->id,
                'structure' => $defense->properties->structural_integrity->rawValue,
                'cost' => $defense->price->resources->metal->get() + $defense->price->resources->crystal->get(),
            ], array_values(ObjectService::getDefenseObjects())),
            // GalaxyController::missileAttack: (30 + 60 x systems) / 1; MissileMission::calculateDefenseDestruction.
            'flight' => ['base_seconds' => 30, 'seconds_per_system' => 60],
            'damage' => ['per_missile' => 12000, 'weapon_bonus' => 0.1, 'armour_bonus' => 0.1, 'structure_divisor' => 10],
            'moon_destroyer' => ObjectService::getUnitObjectByMachineName(MoonDestructionMission::getRequiredShipMachineNames()[0])->id,
            // MoonDestructionMission::calculateMoonDestructionChance and calculateDeathstarLossChance, both clamped to 0..100.
            'moon_destruction' => ['chance_base' => 100, 'loss_divisor' => 2, 'roll_max' => 100],
        ];
    }

    /**
     * What `ExpeditionMission` and `NPCFleetGeneratorService` read, by id, so the expedition rules (`rl/ogsim/src/expedition.rs`)
     * name no object. The host keeps the ship tiers, the NPC pools and the draw tables as literals inside the mission, so they
     * are restated here (ponytail: replace with a query if the host ever exposes one); the settings, the class multipliers
     * and the weights are the host's live answers for this player. Pairs keep the host's order, which the draws follow.
     *
     * @return array<string, mixed>
     */
    private function expedition(PlayerService $player): array
    {
        $settings = app(SettingsService::class);
        $classes = app(CharacterClassService::class);
        $user = $player->getUser();
        $id = static fn (string $name): int => ObjectService::getUnitObjectByMachineName($name)->id;
        $ids = static fn (array $names): array => array_map($id, $names);
        $enemy = $classes->getExpeditionEnemyChanceMultiplier($user);
        $weights = [
            'dark_matter' => $settings->expeditionWeightDarkMatter(), 'ships' => $settings->expeditionWeightShips(),
            'resources' => $settings->expeditionWeightResources(), 'delay' => $settings->expeditionWeightDelay(),
            'speedup' => $settings->expeditionWeightSpeedup(), 'nothing' => $settings->expeditionWeightNothing(),
            'black_hole' => $settings->expeditionWeightBlackHole(), 'pirates' => $settings->expeditionWeightPirates(),
            'aliens' => $settings->expeditionWeightAliens(), 'merchant' => $settings->expeditionWeightMerchant(),
        ];
        foreach (['pirates', 'aliens'] as $name) {
            $weights[$name] *= $enemy < 1.0 ? $enemy : 1.0;
        }
        $weightPairs = [];
        foreach ($weights as $name => $weight) {
            $weightPairs[] = [$name, $weight];
        }
        $tiers = [];
        foreach ([2 => ['battle_ship', 'battlecruiser', 'bomber'], 3 => ['destroyer'], 4 => ['deathstar']] as $tier => $names) {
            foreach ($ids($names) as $unit) {
                $tiers[(string) $unit] = $tier;
            }
        }

        return [
            'outcome_weights' => $weightPairs,
            'fleet_speed_peaceful' => $settings->fleetSpeedPeaceful(),
            'reward_multiplier' => ['resources' => $settings->expeditionRewardMultiplierResources(), 'ships' => $settings->expeditionRewardMultiplierShips(), 'dark_matter' => $settings->expeditionRewardMultiplierDarkMatter()],
            'class_resource_multiplier' => $classes->getExpeditionResourceMultiplier($user, $settings->economySpeed()),
            // The class's own expedition slots on top of the colonisation table's research slots (PlayerService::getExpeditionSlotsMax).
            'class_slots' => $classes->getExpeditionSlotsBonus($user),
            // [weight, multiplier minimum, maximum] for normal, rare and exceptional finds.
            'variants' => [[89, 1, 1], [10, 2, 3], [1, 5, 10]],
            // [rank-1 score the bracket stays below, largest metal find].
            'find_brackets' => [[10_000, 40_000], [100_000, 500_000], [1_000_000, 1_200_000], [5_000_000, 1_800_000], [25_000_000, 2_400_000], [50_000_000, 3_000_000], [75_000_000, 3_600_000], [100_000_000, 4_200_000], [PHP_INT_MAX, 5_000_000]],
            'find_minimum_share' => 0.1,
            // The share of the metal find a crystal and a deuterium find get.
            'resource_shares' => [1.0, 2 / 3, 1 / 3],
            'delay' => ['weights' => [[2, 89], [3, 10], [5, 1]]],
            'speedup_percent' => [5, 10],
            'dark_matter' => [
                'minimum' => (int) $settings->get('expedition_dark_matter_min_no_pathfinder', 150),
                'maximum' => (int) $settings->get('expedition_dark_matter_max_no_pathfinder', 200),
                'multiplier' => (float) $settings->get('expedition_dark_matter_multiplier', '1.0'),
            ],
            // Ships a find can name: tiers in order (each tier adds the next one above the fleet's best), and the tier a
            // ship needs in the fleet before it can be found. The ship that doubles every find is the one needing tier 4.
            'ship_levels' => array_map($ids, [['small_cargo', 'light_fighter', 'espionage_probe'], ['large_cargo'], ['heavy_fighter'], ['cruiser'], ['pathfinder'], ['battle_ship'], ['battlecruiser'], ['bomber'], ['destroyer'], ['reaper']]),
            'ship_needs' => [(string) $id('pathfinder') => 4, (string) $id('reaper') => 9],
            'find_doubler' => $id('pathfinder'),
            'ship_types_max' => 6,
            // A find's share per ship type is drawn between these shares of the even split.
            'ship_split' => [0.25, 1.75],
            'fight' => [
                'debris_rate' => 0.10,
                'tech_research' => [ObjectService::getResearchObjectByMachineName('weapon_technology')->id, ObjectService::getResearchObjectByMachineName('shielding_technology')->id, ObjectService::getResearchObjectByMachineName('armor_technology')->id],
                'npc' => [
                    'size_weights' => [89, 10, 1],
                    'player_tiers' => $tiers,
                    'extra_ship_chance' => 70,
                    'extra_ship_count' => [1, 2],
                    'probe_chance' => 50,
                    'probe' => $id('espionage_probe'),
                    'cargo' => [$id('large_cargo'), $id('small_cargo')],
                    // pirate, then alien. `tech` is the offset from the player's weapon, shield and armour levels.
                    'kinds' => [
                        'pirate' => [
                            'tech' => -3, 'round_up' => false,
                            'sizes' => [[0.30, 0.03, $id('light_fighter'), 5], [0.50, 0.05, $id('cruiser'), 3], [0.80, 0.08, $id('battle_ship'), 2]],
                            // [player tier needed, ship]
                            'pool' => [[1, $id('light_fighter')], [1, $id('heavy_fighter')], [1, $id('cruiser')], [2, $id('battle_ship')]],
                        ],
                        'alien' => [
                            'tech' => 3, 'round_up' => true,
                            'sizes' => [[0.40, 0.04, $id('heavy_fighter'), 5], [0.60, 0.06, $id('battlecruiser'), 3], [0.90, 0.09, $id('destroyer'), 2]],
                            'pool' => [[1, $id('heavy_fighter')], [1, $id('cruiser')], [1, $id('battlecruiser')], [2, $id('battle_ship')], [3, $id('destroyer')], [3, $id('reaper')], [3, $id('pathfinder')]],
                        ],
                    ],
                ],
            ],
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
