<?php

use Illuminate\Support\Arr;

/*
 * The host documents hull plating as contributing no numeric modifier of its own: the combat
 * contribution of a hull is the structural integrity already recorded for the ship, and no
 * hull-plating value may be encoded until Armour Technology is ingested. That fact is recorded
 * only in the documented-combat scenario, so the scenario is what these tests pin.
 */

function reaperDocumentedCombatScenarioPath(): string
{
    $relative = '/resources/scenarios/reaper-documented-combat-only.json';

    for ($directory = __DIR__; $directory !== dirname($directory); $directory = dirname($directory)) {
        if (is_file($directory . $relative)) {
            return $directory . $relative;
        }
    }

    throw new RuntimeException('The documented-combat scenario could not be located.');
}

function reaperDocumentedCombatScenario(): array
{
    $scenario = json_decode(
        (string) file_get_contents(reaperDocumentedCombatScenarioPath()),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    expect($scenario)->toBeArray();

    return $scenario;
}

test('the documented combat scenario carries no hull-plating key', function () {
    $scenario = reaperDocumentedCombatScenario();

    $hullKeys = array_values(array_filter(
        array_keys(Arr::dot($scenario)),
        fn (string $key): bool => str_contains(strtolower($key), 'hull')
    ));

    expect($hullKeys)->toBe([])
        ->and(array_keys($scenario['payload']))->toBe(['status', 'mechanics', 'note']);
});

test('the documented combat scenario still resolves no derived stats for the ship', function () {
    $payload = reaperDocumentedCombatScenario()['payload'];

    expect($payload['status'])->toBe('unconfirmed')
        ->and($payload['mechanics'])->toBeNull()
        ->and(array_filter(Arr::dot($payload), 'is_numeric'))->toBe([]);
});

test('the documented combat scenario ties hull plating to structural integrity and Armour Technology', function () {
    $note = reaperDocumentedCombatScenario()['payload']['note'];

    expect($note)
        ->toContain('Hull plating adds no numeric modifier')
        ->toContain('structural integrity')
        ->toContain('metal cost plus its crystal cost')
        ->toContain('Armour Technology is ingested');
});
