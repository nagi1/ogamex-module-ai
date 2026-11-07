<?php

use Modules\AI\Actions\ExportAiCombatCatalogueAction;
use OGame\GameMissions\EspionageMission;
use OGame\GameMissions\MissileMission;
use OGame\GameMissions\MoonDestructionMission;
use OGame\GameMissions\RecycleMission;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\UnitObject;
use OGame\Factories\GameMissionFactory;
use OGame\GameMissions\ExpeditionMission;
use OGame\Models\FleetMission;
use OGame\Services\CharacterClassService;
use OGame\Services\FleetMissionService;
use OGame\Services\MessageService;
use OGame\Services\SettingsService;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// plan/rl M8: the raid simulator reads nothing but these tables, so every cell must be the host's own answer for the
// research levels it stands for, the same value both battle engines feed a fight.

/**
 * The cell of a property table for the player's current research levels, the first `by` id varying slowest.
 *
 * @param  array{by: list<int>, values: list<int>}  $table
 */
function combatCatalogueCell(array $table, PlayerService $player, int $maxLevel): int
{
    $index = 0;
    foreach ($table['by'] as $id) {
        $index = $index * ($maxLevel + 1) + $player->getResearchLevel(ObjectService::getObjectById($id)->machine_name);
    }

    return $table['values'][$index];
}

/** @param array<string, int> $levels */
function combatCatalogueSetResearch(PlayerService $player, array $levels): void
{
    foreach (ObjectService::getResearchObjects() as $research) {
        $player->setResearchLevel($research->machine_name, $levels[$research->machine_name] ?? 0, false);
    }
}

dataset('combat research', [
    'no research' => [[]],
    'weapons, shielding and armour' => [['weapon_technology' => 3, 'shielding_technology' => 2, 'armor_technology' => 5]],
    'cargo research' => [['hyperspace_technology' => 4]],
    'drives below the upgrades' => [['combustion_drive' => 4, 'impulse_drive' => 3, 'hyperspace_drive' => 2]],
    'drives past the upgrades' => [['combustion_drive' => 6, 'impulse_drive' => 6, 'hyperspace_drive' => 6, 'weapon_technology' => 6]],
]);

test('the catalogue holds every ship and defence with the host\'s price, rapid fire and fuel', function (): void {
    $catalogue = app(ExportAiCombatCatalogueAction::class)->handle($this->planetService->getPlayer(), 2);
    $units = array_column($catalogue['units'], null, 'id');

    expect($catalogue['version'])->toBe(1)
        ->and(array_keys($units))->toEqualCanonicalizing(array_map(static fn (UnitObject $u): int => $u->id, ObjectService::getUnitObjects()));

    foreach (ObjectService::getUnitObjects() as $unit) {
        $row = $units[$unit->id];
        $price = $unit->price->resources;
        expect($row['price'])->toBe([$price->metal->get(), $price->crystal->get(), $price->deuterium->get()], $unit->machine_name)
            ->and($row['type'])->toBe($unit->type->value)
            ->and($row['fuel'])->toBe($unit->properties->fuel->rawValue);
        foreach ($unit->rapidfire as $shot) {
            expect($row['rapidfire'][(string) ObjectService::getUnitObjectByMachineName($shot->object_machine_name)->id])->toBe($shot->amount);
        }
        expect($row['rapidfire'])->toHaveCount(count($unit->rapidfire));
    }
});

test('every property cell is the host\'s value at those research levels', function (array $levels): void {
    $player = $this->planetService->getPlayer();
    $maxLevel = 6;
    $catalogue = app(ExportAiCombatCatalogueAction::class)->handle($player, $maxLevel);
    combatCatalogueSetResearch($player, $levels);

    foreach (array_column($catalogue['units'], null, 'id') as $id => $row) {
        $unit = ObjectService::getUnitObjectById($id);
        foreach ($row['properties'] as $name => $table) {
            expect(combatCatalogueCell($table, $player, $maxLevel))->toEqual($unit->properties->{$name}->calculate($player)->totalValue, $unit->machine_name . ' ' . $name);
        }
    }
})->with('combat research');

test('the researches that move a value are found by asking the host', function (): void {
    $catalogue = app(ExportAiCombatCatalogueAction::class)->handle($this->planetService->getPlayer(), 6);
    $units = array_column($catalogue['units'], null, 'machine_name');
    $id = static fn (string $name): int => ObjectService::getObjectByMachineName($name)->id;

    expect($units['light_fighter']['properties']['attack']['by'])->toBe([$id('weapon_technology')])
        ->and($units['light_fighter']['properties']['shield']['by'])->toBe([$id('shielding_technology')])
        ->and($units['light_fighter']['properties']['structural_integrity']['by'])->toBe([$id('armor_technology')])
        ->and($units['small_cargo']['properties']['capacity']['by'])->toBe([$id('hyperspace_technology')])
        ->and($units['small_cargo']['properties']['speed']['by'])->toEqualCanonicalizing([$id('combustion_drive'), $id('impulse_drive')])
        ->and($units['rocket_launcher']['properties']['speed']['by'])->toBe([]);
});

test('exporting leaves the player\'s research as it found it', function (): void {
    $player = $this->planetService->getPlayer();
    combatCatalogueSetResearch($player, ['weapon_technology' => 2, 'impulse_drive' => 1]);
    $before = array_map(static fn (GameObject $r): int => $player->getResearchLevel($r->machine_name), ObjectService::getResearchObjects());

    $catalogue = app(ExportAiCombatCatalogueAction::class)->handle($player, 2);

    expect(array_map(static fn (GameObject $r): int => $player->getResearchLevel($r->machine_name), ObjectService::getResearchObjects()))->toBe($before)
        ->and(array_values($catalogue['research']))->toBe($before);
});

test('the mission block names, by id, the units and research the host\'s missions read', function (): void {
    $missions = app(ExportAiCombatCatalogueAction::class)->handle($this->planetService->getPlayer(), 1)['missions'];
    $id = static fn (string $name): int => ObjectService::getObjectByMachineName($name)->id;

    expect($missions['espionage_units'])->toBe(array_map($id, EspionageMission::getRequiredShipMachineNames()))
        ->and($missions['espionage_research'])->toBe($id('espionage_technology'))
        ->and($missions['harvesters'])->toHaveCount(16)
        ->and($missions['harvesters']['3'])->toBe($id(RecycleMission::getHarvesterMachineNameForPosition(3)))
        ->and($missions['harvesters']['16'])->toBe($id(RecycleMission::getHarvesterMachineNameForPosition(16)));
});

test('the strike block names, by id, what the missile and moon-destruction missions read', function (): void {
    $player = $this->planetService->getPlayer();
    $strike = app(ExportAiCombatCatalogueAction::class)->handle($player, 4)['strike'];
    $id = static fn (string $name): int => ObjectService::getObjectByMachineName($name)->id;

    expect($strike['missile'])->toBe($id('interplanetary_missile'))
        ->and($strike['interceptor'])->toBe($id('anti_ballistic_missile'))
        ->and($strike['weapon_research'])->toBe($id('weapon_technology'))
        ->and($strike['armour_research'])->toBe($id('armor_technology'))
        ->and($strike['range_research'])->toBe($id('impulse_drive'))
        ->and($strike['moon_destroyer'])->toBe($id(MoonDestructionMission::getRequiredShipMachineNames()[0]))
        ->and(array_column($strike['defences'], 'id'))->toBe(array_map(static fn (UnitObject $d): int => $d->id, array_values(ObjectService::getDefenseObjects())));

    foreach ($strike['defences'] as $row) {
        $defense = ObjectService::getUnitObjectById($row['id']);
        expect($row['structure'])->toBe($defense->properties->structural_integrity->rawValue)
            ->and($row['cost'])->toEqual($defense->price->resources->metal->get() + $defense->price->resources->crystal->get());
    }

    // The range per impulse level is the host's own answer, and the priority codes are its own decoding.
    foreach ($strike['range_by_level'] as $level => $range) {
        combatCatalogueSetResearch($player, ['impulse_drive' => $level]);
        expect($range)->toBe($player->getMissileRange());
    }
    $decode = new ReflectionMethod(MissileMission::class, 'decodePriority');
    foreach ($strike['priorities'] as $code => $mode) {
        $name = $decode->invoke(app(MissileMission::class), $code);
        expect($mode)->toBe(in_array($name, ['cheapest', 'expensive'], true) ? $name : $id($name));
    }
});

test('the strike constants are the ones the host\'s missile and moon-destruction formulas use', function (): void {
    $player = $this->planetService->getPlayer();
    $strike = app(ExportAiCombatCatalogueAction::class)->handle($player, 4)['strike'];
    $missile = ObjectService::getUnitObjectByMachineName('interplanetary_missile');

    // A missile's attack is the per-missile damage, raised by the weapon bonus a level.
    combatCatalogueSetResearch($player, []);
    $plain = $missile->properties->attack->calculate($player)->totalValue;
    combatCatalogueSetResearch($player, ['weapon_technology' => 1]);
    expect($plain)->toBe($strike['damage']['per_missile'])
        ->and($missile->properties->attack->calculate($player)->totalValue)->toBe((int) round($plain * (1 + $strike['damage']['weapon_bonus'])));

    $mission = app(MoonDestructionMission::class);
    $destroy = new ReflectionMethod(MoonDestructionMission::class, 'calculateMoonDestructionChance');
    $lose = new ReflectionMethod(MoonDestructionMission::class, 'calculateDeathstarLossChance');
    foreach ([[3392, 3], [8944, 20], [100, 1], [30000, 5]] as [$diameter, $ships]) {
        $constants = $strike['moon_destruction'];
        expect($destroy->invoke($mission, $diameter, $ships))->toEqual(max(0, min(100, ($constants['chance_base'] - sqrt($diameter)) * sqrt($ships))))
            ->and($lose->invoke($mission, $diameter))->toEqual(max(0, min(100, sqrt($diameter) / $constants['loss_divisor'])));
    }
    expect($strike['flight'])->toBe(['base_seconds' => 30, 'seconds_per_system' => 60]);
});

test('the expedition block holds the host\'s weights, class multipliers and the ships and enemies it names, by id', function (): void {
    $player = $this->planetService->getPlayer();
    $expedition = app(ExportAiCombatCatalogueAction::class)->handle($player, 1)['expedition'];
    $settings = app(SettingsService::class);
    $classes = app(CharacterClassService::class);
    $id = static fn (string $name): int => ObjectService::getObjectByMachineName($name)->id;

    $mission = new ReflectionMethod(ExpeditionMission::class, 'getOutcomeWeights');
    $fleet = new FleetMission();
    $fleet->user_id = $player->getId();
    $hosts = $mission->invoke(app(GameMissionFactory::class)->getMissionById(15, ['fleetMissionService' => app()->makeWith(FleetMissionService::class, ['player' => $player]), 'messageService' => app(MessageService::class)]), $fleet);

    expect($expedition['outcome_weights'])->toBe(array_map(static fn (string $name, float $weight): array => [$name, $weight], array_keys($hosts), array_values($hosts)))
        ->and($expedition['class_resource_multiplier'])->toEqual($classes->getExpeditionResourceMultiplier($player->getUser(), $settings->economySpeed()))
        ->and($expedition['fleet_speed_peaceful'])->toBe($settings->fleetSpeedPeaceful())
        ->and($expedition['reward_multiplier'])->toBe(['resources' => $settings->expeditionRewardMultiplierResources(), 'ships' => $settings->expeditionRewardMultiplierShips(), 'dark_matter' => $settings->expeditionRewardMultiplierDarkMatter()])
        ->and($expedition['ship_levels'][0])->toBe([$id('small_cargo'), $id('light_fighter'), $id('espionage_probe')])
        ->and($expedition['ship_levels'])->toHaveCount(10)
        ->and($expedition['ship_needs'])->toBe([(string) $id('pathfinder') => 4, (string) $id('reaper') => 9])
        ->and($expedition['find_doubler'])->toBe($id('pathfinder'))
        ->and($expedition['fight']['tech_research'])->toBe([$id('weapon_technology'), $id('shielding_technology'), $id('armor_technology')])
        ->and($expedition['fight']['npc']['kinds']['pirate']['sizes'][0][2])->toBe($id('light_fighter'))
        ->and($expedition['fight']['npc']['kinds']['alien']['pool'][0])->toBe([1, $id('heavy_fighter')])
        ->and($expedition['fight']['npc']['player_tiers'][(string) $id('destroyer')])->toBe(3);
});
