<?php

use Symfony\Component\Finder\Finder;

/**
 * WIK-119 states no numbers and no doctrine, so the module may not cite it anywhere.
 * Keeping the citation out of behaviour data and out of code is the whole delivery.
 */
function unconfirmedSourceId(): string
{
    return 'WIK-119';
}

function guardModuleRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * The files that decide what the account does: behaviour data and module code.
 *
 * @return array<int, string>
 */
function guardBehaviourFiles(string $moduleRoot): array
{
    return array_merge(
        guardYamlFiles($moduleRoot . '/resources/behavior'),
        guardFilesIn($moduleRoot . '/app'),
    );
}

/**
 * @return array<int, string>
 */
function guardYamlFiles(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    return guardPathsOf(Finder::create()->files()->name('*.yaml')->in($directory));
}

/**
 * @return array<int, string>
 */
function guardFilesIn(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    return guardPathsOf(Finder::create()->files()->in($directory));
}

/**
 * @return array<int, string>
 */
function guardPathsOf(Finder $finder): array
{
    $paths = [];

    foreach ($finder as $file) {
        $paths[] = $file->getPathname();
    }

    sort($paths);

    return $paths;
}

/**
 * @return array<int, string>
 */
function guardCitationsOf(string $sourceId, string $moduleRoot): array
{
    $citations = [];

    foreach (guardBehaviourFiles($moduleRoot) as $path) {
        if (! str_contains((string) file_get_contents($path), $sourceId)) {
            continue;
        }

        $citations[] = str_replace($moduleRoot . '/', '', $path);
    }

    sort($citations);

    return $citations;
}

function guardFixtureRoot(): string
{
    $root = sys_get_temp_dir() . '/ai-unconfirmed-source-' . bin2hex(random_bytes(6));

    mkdir($root, 0777, true);

    return $root;
}

function guardWriteFixture(string $moduleRoot, string $relativePath, string $contents): void
{
    $path = $moduleRoot . '/' . $relativePath;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, $contents);
}

function guardRemoveTree(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
            continue;
        }

        unlink($item->getPathname());
    }

    rmdir($directory);
}

it('ships no behaviour or doctrine that cites the unconfirmed source', function () {
    $moduleRoot = guardModuleRoot();

    expect(guardBehaviourFiles($moduleRoot))->not->toBe([])
        ->and(guardCitationsOf(unconfirmedSourceId(), $moduleRoot))->toBe([]);
});

it('flags the citation the moment it lands in behaviour data or in code', function () {
    $moduleRoot = guardFixtureRoot();
    guardWriteFixture($moduleRoot, 'resources/behavior/defence.yaml', 'source: ' . unconfirmedSourceId() . "\n");
    guardWriteFixture($moduleRoot, 'app/Ai/DefenceValuation.php', "<?php\n\n// derived from " . unconfirmedSourceId() . "\n");

    try {
        expect(guardCitationsOf(unconfirmedSourceId(), $moduleRoot))->toBe([
            'app/Ai/DefenceValuation.php',
            'resources/behavior/defence.yaml',
        ]);
    } finally {
        guardRemoveTree($moduleRoot);
    }
});

it('leaves a behaviour file that cites a different source unflagged', function () {
    $moduleRoot = guardFixtureRoot();
    guardWriteFixture($moduleRoot, 'resources/behavior/defence.yaml', "source: WIK-042\n");
    guardWriteFixture($moduleRoot, 'app/Ai/DefenceValuation.php', "<?php\n\n// derived from WIK-042\n");

    try {
        expect(guardCitationsOf(unconfirmedSourceId(), $moduleRoot))->toBe([]);
    } finally {
        guardRemoveTree($moduleRoot);
    }
});
