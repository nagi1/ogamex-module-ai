<?php

use Modules\AI\Support\EmpireSummary;

test('the empire total row is the arithmetic sum of the three per-planet rows', function (): void {
    $planets = [
        ['metal' => 1200, 'crystal' => 800, 'deuterium' => 400],
        ['metal' => 300, 'crystal' => 50, 'deuterium' => 0],
        ['metal' => 7, 'crystal' => 13, 'deuterium' => 29],
    ];

    expect(app(EmpireSummary::class)->totals($planets))->toBe([
        'metal' => 1507,
        'crystal' => 863,
        'deuterium' => 429,
    ]);
});

test('a one-planet empire returns exactly that planet values', function (): void {
    $planet = ['metal' => 1200, 'crystal' => 800, 'deuterium' => 400];

    expect(app(EmpireSummary::class)->totals([$planet]))->toBe($planet);
});

test('an empire with no planets has an empty total row', function (): void {
    expect(app(EmpireSummary::class)->totals([]))->toBe([]);
});

test('planets holding nothing total to zero for every column', function (): void {
    $planets = [
        ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
        ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
        ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
    ];

    expect(app(EmpireSummary::class)->totals($planets))->toBe([
        'metal' => 0,
        'crystal' => 0,
        'deuterium' => 0,
    ]);
});

test('totals keep summing past three planets and are recomputed on every call', function (): void {
    $planets = [
        ['metal' => 10, 'crystal' => 1, 'deuterium' => 0],
        ['metal' => 20, 'crystal' => 2, 'deuterium' => 0],
        ['metal' => 30, 'crystal' => 3, 'deuterium' => 0],
        ['metal' => 40, 'crystal' => 4, 'deuterium' => 0],
    ];

    $summary = app(EmpireSummary::class);

    $expected = ['metal' => 100, 'crystal' => 10, 'deuterium' => 0];

    expect($summary->totals($planets))->toBe($expected);
    expect($summary->totals($planets))->toBe($expected);
});
