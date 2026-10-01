<?php

use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Domain\Decision\RaidWavePlan;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * Every integer the module assigns to one of the attack-cap constants, keyed by
 * the constant's name: the planner's own bashing limit plus any other statement
 * of the same cap (the daily per-target budget, the wave plan).
 *
 * @return array<string, int>
 */
function raidBashingCapStatedValues(): array
{
    $values = [];

    foreach (raidBashingCapPhpFiles(raidBashingCapModuleRoot() . '/app') as $file) {
        preg_match_all(
            '/(BASHING_LIMIT|MAX_ATTACKS_PER_TARGET_PER_DAY|WAVE_LIMIT)\s*=\s*(\d+)/',
            (string) file_get_contents($file),
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $values[$match[1]] = (int) $match[2];
        }
    }

    return $values;
}

/**
 * @return list<string>
 */
function raidBashingCapPhpFiles(string $directory): array
{
    $files = [];

    foreach (glob($directory . '/*') ?: [] as $path) {
        if (is_dir($path)) {
            $files = [...$files, ...raidBashingCapPhpFiles($path)];
            continue;
        }

        if (str_ends_with($path, '.php')) {
            $files[] = $path;
        }
    }

    return $files;
}

function raidBashingCapModuleRoot(): string
{
    return dirname(__DIR__, 2);
}

test('the wave plan flies the planner bashing limit and no other number', function (): void {
    expect(RaidWavePlan::forTarget(1_000_000)->count())->toBe(RaidPlanner::BASHING_LIMIT);
});

test('the bashing cap is stated once: every stated value is the planner limit', function (): void {
    $stated = raidBashingCapStatedValues();

    expect($stated)->toHaveKey('BASHING_LIMIT');

    foreach ($stated as $name => $value) {
        expect($value)->toBe(RaidPlanner::BASHING_LIMIT, $name . ' states ' . $value);
    }
});
