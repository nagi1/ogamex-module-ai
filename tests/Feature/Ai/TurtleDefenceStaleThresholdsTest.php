<?php

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/*
 * The AI module must not treat the older defence/IPM tallies as current doctrine. The source
 * documents a turtle-defence figure of 37 and an anti-ballistic total of 100 or more as
 * superseded practice, so a named threshold in the module's own source built on either figure
 * fails here and an edit that reintroduces them is caught in CI.
 *
 * The guard was filed under tests/Unit; this module rejects tests there, so it lives under
 * tests/Feature and drives the scan over the real module source instead of a private copy.
 */

/** The superseded turtle-defence figure. */
const LEGACY_TURTLE_DEFENCE_UNITS = 37;

/** The superseded anti-ballistic total, stated by the source as 100 or more. */
const LEGACY_ANTI_BALLISTIC_MINIMUM_UNITS = 100;

/** Identifier words that make an assigned number a turtle-defence threshold. */
const TURTLE_DEFENCE_IDENTIFIER = '/TURTLE|DEFEN[CS]E/i';

/** Identifier words that make an assigned number an anti-ballistic target. */
const ANTI_BALLISTIC_IDENTIFIER = '/ABM|ANTI_?BALLISTIC|INTERCEPTOR/i';

it('leaves the superseded defence tallies out of the module source', function () {
    expect(aiScannedSourceFiles())->not->toBeEmpty();

    expect(aiLegacyThresholdViolations())->toBe([]);
});

it('flags a threshold built on a superseded tally injected into the module source', function () {
    $fixture = aiModuleRoot() . '/app/LegacyDefenceThresholdFixture.php';

    file_put_contents($fixture, aiLegacyThresholdFixtureSource());

    try {
        $flagged = array_column(aiLegacyThresholdViolations(), 'identifier');
    } finally {
        unlink($fixture);
    }

    sort($flagged);

    expect($flagged)->toBe([
        'ABM_TARGET_CEILING',
        'ABM_TARGET_UNITS',
        'TURTLE_DEFENCE_UNITS',
    ]);
});

function aiModuleRoot(): string
{
    return dirname(__DIR__, 3) . '/Modules/AI';
}

/**
 * The directories a threshold would land in; tests and migrations are not doctrine.
 *
 * @return list<string>
 */
function aiScannedSourceDirectories(): array
{
    $module = aiModuleRoot();

    return array_values(array_filter(
        [$module . '/app', $module . '/config', $module . '/resources'],
        is_dir(...)
    ));
}

/**
 * @return list<string>
 */
function aiScannedSourceFiles(): array
{
    $files = [];

    foreach (aiScannedSourceDirectories() as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * @return list<array{file: string, line: int, identifier: string, value: int}>
 */
function aiLegacyThresholdViolations(): array
{
    $violations = [];

    foreach (aiScannedSourceFiles() as $file) {
        $violations = [...$violations, ...aiFileLegacyThresholdViolations($file)];
    }

    return $violations;
}

/**
 * @return list<array{file: string, line: int, identifier: string, value: int}>
 */
function aiFileLegacyThresholdViolations(string $file): array
{
    $violations = [];

    foreach (aiAssignedNumbersByLine((string) file_get_contents($file)) as $line => $numbers) {
        foreach (aiLegacyThresholdsAmong($numbers) as $threshold) {
            $violations[] = [
                'file' => $file,
                'line' => $line + 1,
                'identifier' => $threshold['identifier'],
                'value' => $threshold['value'],
            ];
        }
    }

    return $violations;
}

/**
 * @param  list<array{identifier: string, value: int}>  $numbers
 * @return list<array{identifier: string, value: int}>
 */
function aiLegacyThresholdsAmong(array $numbers): array
{
    return array_values(array_filter(
        $numbers,
        static fn (array $number): bool => aiIsLegacyDefenceThreshold($number['identifier'], $number['value'])
    ));
}

/**
 * @return array<int, list<array{identifier: string, value: int}>>
 */
function aiAssignedNumbersByLine(string $source): array
{
    $byLine = [];

    foreach (explode("\n", aiCodeWithoutComments($source)) as $line => $code) {
        $numbers = aiNumbersAssignedToNames($code);

        if ($numbers !== []) {
            $byLine[$line] = $numbers;
        }
    }

    return $byLine;
}

/**
 * Documentation may name the legacy figures; only code that assigns them counts.
 */
function aiCodeWithoutComments(string $source): string
{
    $withoutBlockComments = (string) preg_replace('#/\*.*?\*/#s', '', $source);

    return (string) preg_replace('#//[^\n]*#', '', $withoutBlockComments);
}

/**
 * @return list<array{identifier: string, value: int}>
 */
function aiNumbersAssignedToNames(string $code): array
{
    $pattern = '/(?<identifier>[A-Za-z_][A-Za-z0-9_]*)["\']?\s*(?:=>|>=|<=|==|=|>|<)\s*(?<value>\d+)/';

    if (preg_match_all($pattern, $code, $matches, PREG_SET_ORDER) === 0) {
        return [];
    }

    $assigned = [];

    foreach ($matches as $match) {
        $assigned[] = [
            'identifier' => $match['identifier'],
            'value' => (int) $match['value'],
        ];
    }

    return $assigned;
}

function aiIsLegacyDefenceThreshold(string $identifier, int $value): bool
{
    if (aiNamesTurtleDefence($identifier) && $value === LEGACY_TURTLE_DEFENCE_UNITS) {
        return true;
    }

    return aiNamesAntiBallisticDefence($identifier) && $value >= LEGACY_ANTI_BALLISTIC_MINIMUM_UNITS;
}

function aiNamesTurtleDefence(string $identifier): bool
{
    return preg_match(TURTLE_DEFENCE_IDENTIFIER, $identifier) === 1;
}

function aiNamesAntiBallisticDefence(string $identifier): bool
{
    return preg_match(ANTI_BALLISTIC_IDENTIFIER, $identifier) === 1;
}

/**
 * Names carry the whole assertion: the figure at its bound, past it, at zero, and a number
 * that shares the legacy figure but names nothing defensive.
 */
function aiLegacyThresholdFixtureSource(): string
{
    return <<<'PHP'
<?php

final class LegacyDefenceThresholdFixture
{
    public const TURTLE_DEFENCE_UNITS = 37;
    public const TURTLE_DEFENCE_FLOOR = 38;
    public const TURTLE_DEFENCE_DISABLED = 0;
    public const ABM_TARGET_UNITS = 100;
    public const ABM_TARGET_CEILING = 101;
    public const ABM_TARGET_BELOW_LEGACY = 99;
    public const ABM_TARGET_DISABLED = 0;
    public const MAX_STORAGE_LEVEL = 37;
}
PHP;
}
