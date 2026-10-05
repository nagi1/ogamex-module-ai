<?php

use OGame\Services\ObjectService;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

// One defence unit was spelled "Light Laser" in the doctrine, `small_shield_dome` in the behaviour data and "SmallShieldDome" in
// scenarios, and nothing checked they were the same object. A misspelling then fails only when that doctrine is chosen.

function defenceMachineNames(): array
{
    return array_map(static fn ($object): string => $object->machine_name, ObjectService::getDefenseObjects());
}

function asMachineName(string $name): string
{
    return strtolower(preg_replace('/[\s-]+/', '_', $name) ?? '');
}

test('every defence unit the doctrines, the fodder wall and the scenarios name is a host defence object', function (): void {
    $host = defenceMachineNames();
    $named = [];

    $doctrines = Yaml::parseFile(module_path('AI', '/resources/behavior/defence-doctrines.yaml'))['doctrines'];
    foreach ($doctrines as $doctrine) {
        $named[] = $doctrine['anchor']['unit'];
        array_push($named, ...array_keys($doctrine['ratio']));
    }

    $fodder = Yaml::parseFile(module_path('AI', '/resources/behavior/defence-fodder-heavy.yaml'));
    array_push($named, ...array_keys($fodder['fodder_per']), ...array_keys($fodder['domes_per_wall']));

    $positions = json_decode((string) file_get_contents(module_path('AI', '/resources/scenarios/def-layering-stated-positions.json')), true)['positions'];
    foreach ($positions as $position) {
        array_push($named, ...array_keys($position));
    }

    $unknown = array_values(array_unique(array_filter(
        array_map(asMachineName(...), $named),
        static fn (string $name): bool => !in_array($name, $host, true),
    )));

    expect($unknown)->toBe([]);
});
