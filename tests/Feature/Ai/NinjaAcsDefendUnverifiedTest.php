<?php

declare(strict_types=1);

// WIK-126 is wiki-confidence-only and states no numbers, so no ACS quantity may be
// encoded: no timing, no defend window, no support speed, no ship count. The plan
// names no ninja-evaluation component and changes only this fixture, so the bait
// verdict and the ACS flag are asserted on the scenario contract the fixture
// defines; that contract is the whole of the behaviour WIK-126 introduces.

function wik126ScenarioPath(): string
{
    return dirname(__DIR__, 3) . '/resources/scenarios/ninja_acs_defend_unverified.json';
}

function wik126Scenario(): array
{
    $path = wik126ScenarioPath();

    expect(file_exists($path))->toBeTrue();

    $raw = file_get_contents($path);
    expect($raw)->toBeString()->not->toBe('');

    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Every scalar leaf in the fixture, keyed by its dotted path.
 *
 * @return array<string, mixed>
 */
function wik126ScalarPaths(array $node, string $prefix = ''): array
{
    $paths = [];

    foreach ($node as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

        if (is_array($value)) {
            $paths = array_merge($paths, wik126ScalarPaths($value, $path));
            continue;
        }

        $paths[$path] = $value;
    }

    return $paths;
}

/**
 * Paths whose key name reads like an ACS quantity field. Any hit means a timing or
 * ship-count slot was reserved, which WIK-126 states is unverifiable.
 *
 * @return list<string>
 */
function wik126ForbiddenQuantityKeys(array $scenario): array
{
    $pattern = '/(timing|duration|second|minute|hour|speed|arrival|arrive|window|eta|ship|count|amount|quantity|size)/i';

    $offending = [];

    foreach (array_keys(wik126ScalarPaths($scenario)) as $path) {
        foreach (explode('.', $path) as $segment) {
            if (preg_match($pattern, $segment) === 1) {
                $offending[] = $path;
                continue 2;
            }
        }
    }

    return $offending;
}

it('loads the ninja ACS defend scenario fixture', function (): void {
    $scenario = wik126Scenario();

    expect($scenario['id'] ?? null)->toBe('ninja_acs_defend_unverified')
        ->and($scenario['principle'] ?? null)->toBe('NIN-005');
});

it('returns the bait verdict with alliance combat support reported unverified', function (): void {
    $scenario = wik126Scenario();

    $expect = $scenario['expect'] ?? [];
    $situation = $scenario['situation'] ?? [];
    $support = $situation['alliance_combat_support'] ?? [];

    expect($expect['verdict'] ?? null)->toBe('bait')
        ->and($expect['bait_permitted'] ?? null)->toBeTrue()
        ->and($situation['defender_posture'] ?? null)->toBe('ninja_bait')
        ->and($support['confidence'] ?? null)->toBe('unverified')
        ->and($expect['acs_flag'] ?? null)->toBe('unverified');
});

it('never reports alliance combat support as confirmed', function (): void {
    $scenario = wik126Scenario();

    expect($scenario['expect']['acs_flag'] ?? null)->not->toBe('confirmed')
        ->and($scenario['expect']['acs_confirmed'] ?? null)->not->toBeTrue()
        ->and($scenario['situation']['alliance_combat_support']['confirmed'] ?? null)->not->toBeTrue();
});

it('emits zero ACS quantities, numeric or written out', function (): void {
    $scalars = wik126ScalarPaths(wik126Scenario());

    $numeric = array_keys(array_filter($scalars, static fn (mixed $value): bool => is_int($value) || is_float($value)));

    expect($numeric)->toBe([]);
});

it('carries digits only in the principle identifier, never in an ACS value', function (): void {
    $scalars = wik126ScalarPaths(wik126Scenario());

    $withDigits = array_keys(array_filter($scalars, static fn (mixed $value): bool => is_string($value) && preg_match('/\d/', $value) === 1));

    expect($withDigits)->toBe(['principle']);
});

it('declares no ACS timing or ship-count field', function (): void {
    expect(wik126ForbiddenQuantityKeys(wik126Scenario()))->toBe([]);
});
