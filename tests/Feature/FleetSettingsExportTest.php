<?php

use Modules\AI\Actions\ExportAiFleetSettingsAction;
use OGame\Enums\CharacterClass;
use OGame\Factories\GameMissionFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Planet\Coordinate;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// plan/rl/world-sim-spec.md, `fleet`: the simulator's distance, flight time and fuel read nothing but this export and
// the combat catalogue, so each value must be the one FleetMissionService reads.

dataset('fleet universes', [
    'one speed everywhere' => [['fleet_speed' => 1, 'fleet_speed_war' => 1, 'fleet_speed_holding' => 1, 'fleet_speed_peaceful' => 1, 'number_of_galaxies' => 9, 'number_of_systems' => 499, 'ignore_empty_systems_on' => 0, 'ignore_inactive_systems_on' => 0, 'deuterium_consumption' => '1.0']],
    'split speeds, small donut, skipped systems' => [['fleet_speed' => 2, 'fleet_speed_war' => 3, 'fleet_speed_holding' => 5, 'fleet_speed_peaceful' => 7, 'number_of_galaxies' => 4, 'number_of_systems' => 120, 'ignore_empty_systems_on' => 1, 'ignore_inactive_systems_on' => 1, 'deuterium_consumption' => '0.7']],
]);

/** @param array<string, int|string> $values */
function fleetSettingsApply(array $values): void
{
    $settings = app(SettingsService::class);
    foreach ($values as $key => $value) {
        $settings->set($key, $value);
    }
}

test('the export holds the universe settings the fleet equations read', function (array $values): void {
    fleetSettingsApply($values);

    $export = app(ExportAiFleetSettingsAction::class)->handle($this->planetService->getPlayer());

    expect($export['version'])->toBe(1)
        ->and($export['number_of_galaxies'])->toBe($values['number_of_galaxies'])
        ->and($export['number_of_systems'])->toBe($values['number_of_systems'])
        ->and($export['ignore_empty_systems'])->toBe((bool) $values['ignore_empty_systems_on'])
        ->and($export['ignore_inactive_systems'])->toBe((bool) $values['ignore_inactive_systems_on'])
        ->and($export['fleet_speed'])->toBe($values['fleet_speed'])
        ->and($export['deuterium_consumption'])->toBe((float) $values['deuterium_consumption'])
        ->and($export['class_consumption_multiplier'])->toBe(1.0);
})->with('fleet universes');

test('each mission flies at the exported speed, as the host times it', function (array $values): void {
    fleetSettingsApply($values);
    $player = $this->planetService->getPlayer();
    $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
    $units = app(UnitCollection::class);
    $units->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 1);
    $target = new Coordinate($this->planetService->getPlanetCoordinates()->galaxy, $this->planetService->getPlanetCoordinates()->system + 1, 4);

    $export = app(ExportAiFleetSettingsAction::class)->handle($player);

    expect(array_keys($export['mission_fleet_speed']))->not->toContain(10);
    foreach ($export['mission_fleet_speed'] as $typeId => $speed) {
        $hostDuration = $fleetMissions->calculateFleetMissionDuration($this->planetService, $target, $units, GameMissionFactory::getMissionById($typeId, []), 10);
        app(SettingsService::class)->set('fleet_speed', $speed);
        expect($fleetMissions->calculateFleetMissionDuration($this->planetService, $target, $units, null, 10))->toBe($hostDuration, 'mission ' . $typeId);
        app(SettingsService::class)->set('fleet_speed', $values['fleet_speed']);
    }
})->with('fleet universes');

test('a general burns half the deuterium', function (): void {
    $player = $this->planetService->getPlayer();
    $player->getUser()->character_class = CharacterClass::GENERAL->value;

    expect(app(ExportAiFleetSettingsAction::class)->handle($player)['class_consumption_multiplier'])->toBe(0.5);
});
