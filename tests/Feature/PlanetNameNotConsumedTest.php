<?php

// A planet name is the label a player gives a planet. The AI decides on planet state, never on that
// label, so no file in the decision runtime may read one.

$moduleRuntimeDirectory = dirname(__DIR__, 2) . '/app';

$planetNameReadPatterns = [
    'getPlanetName(',
    '->planetName',
    'planet_name',
];

// Universe seeding writes labels onto the fixture planets it creates. Writing a label is not
// reading a name as a decision input, so that file sits outside the runtime this guard covers.
$fixtureSeedingFileSuffix = 'SeedAiTestUniverseAction.php';

$runtimeFiles = function (string $directory) use ($fixtureSeedingFileSuffix): array {
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    $files = [];

    /** @var SplFileInfo $entry */
    foreach ($entries as $entry) {
        if (! $entry->isFile() || $entry->getExtension() !== 'php') {
            continue;
        }

        if (str_ends_with($entry->getFilename(), $fixtureSeedingFileSuffix)) {
            continue;
        }

        $files[] = $entry->getPathname();
    }

    sort($files);

    return $files;
};

$planetNameReadIn = function (string $file) use ($planetNameReadPatterns): ?string {
    $contents = (string) file_get_contents($file);

    foreach ($planetNameReadPatterns as $pattern) {
        if (str_contains($contents, $pattern)) {
            return $pattern;
        }
    }

    return null;
};

it('reads no planet name in the AI decision runtime', function () use ($moduleRuntimeDirectory, $runtimeFiles, $planetNameReadIn) {
    $offenders = [];

    foreach ($runtimeFiles($moduleRuntimeDirectory) as $file) {
        $pattern = $planetNameReadIn($file);

        if ($pattern === null) {
            continue;
        }

        $offenders[] = $file . ' reads a planet name (' . $pattern . ')';
    }

    expect($offenders)->toBe([]);
});

it('scans the runtime rather than an empty directory', function () use ($moduleRuntimeDirectory, $runtimeFiles, $fixtureSeedingFileSuffix) {
    expect(is_dir($moduleRuntimeDirectory))->toBeTrue();

    $files = $runtimeFiles($moduleRuntimeDirectory);

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(str_ends_with($file, $fixtureSeedingFileSuffix))->toBeFalse();
    }
});

it('detects a planet-name read when one is present', function () use ($planetNameReadIn) {
    $reading = sys_get_temp_dir() . '/ai-planet-name-read-' . uniqid() . '.php';
    $clean = sys_get_temp_dir() . '/ai-planet-name-clean-' . uniqid() . '.php';

    file_put_contents($reading, "<?php\n\n\$label = \$planetService->getPlanetName();\n");
    file_put_contents($clean, "<?php\n\n\$label = \$planetService->getPlanetId();\n");

    expect($planetNameReadIn($reading))->toBe('getPlanetName(');
    expect($planetNameReadIn($clean))->toBeNull();

    unlink($reading);
    unlink($clean);
});
