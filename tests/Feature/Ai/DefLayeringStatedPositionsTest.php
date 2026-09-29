<?php

// Loaded by module-relative path: the fixture is spec data, quotable without booting the application.
function tp021StatedPositions(): array
{
    $path = dirname(__DIR__, 3) . '/resources/scenarios/def-layering-stated-positions.json';

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

it('records the balanced stated position exactly', function (): void {
    $balanced = tp021StatedPositions()['positions']['balanced'];

    expect($balanced)->toEqual([
        'light_laser' => 100,
        'rocket_launcher' => 20,
        'ion_cannon' => 20,
        'heavy_laser' => 10,
        'gauss_cannon' => 10,
        'plasma_turret' => 5,
    ]);
});

it('records the early game stated position exactly', function (): void {
    $earlyGame = tp021StatedPositions()['positions']['early_game'];

    expect($earlyGame)->toEqual([
        'light_laser' => 200,
        'rocket_launcher' => ['min' => 300, 'max' => 400],
        'heavy_laser' => 20,
        'ion_cannon' => 10,
        'gauss_cannon' => 4,
        'small_shield_dome' => 1,
        'large_shield_dome' => 1,
        'anti_ballistic_missile' => 20,
    ]);
});

it('keeps the early game rocket launcher a bounded band rather than a scalar', function (): void {
    $band = tp021StatedPositions()['positions']['early_game']['rocket_launcher'];

    expect($band)->toBeArray();
    expect($band['min'])->toBe(300);
    expect($band['max'])->toBe(400);
});

it('carries provenance that forbids reading it as corroboration', function (): void {
    $provenance = tp021StatedPositions()['provenance'];

    expect($provenance['source'])->toBe('TP-021');
    expect($provenance['derivative_of'])->toBe('WIK-013');
    expect($provenance['confidence'])->toBe('low');
    expect($provenance['anecdotal'])->toBeTrue();
    expect($provenance['cited_as_corroboration'])->toBeFalse();
});
