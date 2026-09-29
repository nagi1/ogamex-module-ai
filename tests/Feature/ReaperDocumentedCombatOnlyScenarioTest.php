<?php

/**
 * The Reaper entry is a community-wiki claim, not host-confirmed mechanics: nothing in this module
 * may build doctrine on it. These assertions fail the moment a number is written into the scenario,
 * which is the point at which the host confirmation the plan waits on would be required.
 */

function reaperCombatOnlyScenario(): array
{
    $path = dirname(__DIR__, 2) . '/resources/scenarios/reaper-documented-combat-only.json';

    expect(is_file($path))->toBeTrue();

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

it('keeps the Reaper claim as documented community-wiki provenance, not confirmed mechanics', function () {
    $scenario = reaperCombatOnlyScenario();

    expect($scenario['claim_type'])->toBe('DOCUMENTED')
        ->and($scenario['confidence'])->toBe('medium')
        ->and($scenario['payload']['status'])->toBe('unconfirmed')
        ->and($scenario['payload']['mechanics'])->toBeNull();
});

it('carries no combat numbers for the Reaper while the mechanics stay unconfirmed', function () {
    $combatFields = ['damage', 'speed', 'cargo', 'cost'];
    $offendingPaths = [];

    $scan = function (array $node, array $path) use (&$scan, &$offendingPaths, $combatFields): void {
        foreach ($node as $key => $value) {
            $trail = [...$path, (string) $key];

            if ($value !== null && in_array(strtolower((string) $key), $combatFields, true)) {
                $offendingPaths[] = implode('.', $trail);
            }

            if (is_array($value)) {
                $scan($value, $trail);
                continue;
            }

            if (is_int($value) || is_float($value)) {
                $offendingPaths[] = implode('.', $trail);
            }
        }
    };

    $scan(reaperCombatOnlyScenario(), []);

    expect($offendingPaths)->toBe([]);
});
