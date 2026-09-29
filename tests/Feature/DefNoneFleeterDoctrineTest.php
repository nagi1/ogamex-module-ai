<?php

declare(strict_types=1);

$scenarioFile = dirname(__DIR__, 2) . '/resources/scenarios/def-none-fleeter.json';

$scenarioSteps = static function () use ($scenarioFile): array {
    $contents = file_get_contents($scenarioFile);

    expect($contents)->not->toBeFalse();

    $scenario = json_decode((string) $contents, true, 512, JSON_THROW_ON_ERROR);

    expect($scenario['steps'])->toBeArray();
    expect($scenario['steps'])->not->toBeEmpty();

    return $scenario['steps'];
};

it('declares an empty defense queue in every step', function () use ($scenarioSteps): void {
    foreach ($scenarioSteps() as $step) {
        expect($step['defense_queue'])->toBe([]);
    }
});

// The doctrine states the fleet obligation for the offline state, so only steps recording that state carry it.
it('leaves every fleet of an offline account returning or parked under a fleetsave', function () use ($scenarioSteps): void {
    $offlineSteps = 0;

    foreach ($scenarioSteps() as $step) {
        if (($step['account'] ?? null) !== 'offline') {
            continue;
        }

        $offlineSteps++;

        expect($step['fleet'])->toBeArray();
        expect($step['fleet'])->not->toBeEmpty();

        foreach ($step['fleet'] as $entry) {
            $returning = ($entry['status'] ?? null) === 'returning';
            $fleetsaved = ($entry['fleetsave'] ?? false) === true;

            expect($returning || $fleetsaved)->toBeTrue();
        }
    }

    expect($offlineSteps)->toBeGreaterThan(0);
});
