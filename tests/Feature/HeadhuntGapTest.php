<?php

/**
 * The module implements no headhunt doctrine: the source states no variants and no numbers, so
 * nothing may be encoded into code or into the behaviour data. These tests fail if a later
 * contributor smuggles the unconfirmed claim in.
 */

/** Absolute path inside the module. */
function headhuntGapPath(string $relative): string
{
    return dirname(__DIR__, 2) . ($relative === '' ? '' : '/' . $relative);
}

/** Every file with the given extension below $relative, sorted for stable diagnostics. */
function headhuntGapFiles(string $relative, string $extension): array
{
    $root = headhuntGapPath($relative);

    if (!is_dir($root)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        $path = $file->getPathname();

        if (str_ends_with($path, $extension)) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

/** Source with comments stripped, so a docblock can never look like a policy reference. */
function headhuntGapCode(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/** Module-relative name of a file, for readable failure output. */
function headhuntGapRelative(string $path): string
{
    return ltrim(str_replace(headhuntGapPath(''), '', $path), '/');
}

/**
 * The golden fixture is the committed behaviour data: an explicit fingerprint file when the module
 * ships one, otherwise the blob at HEAD. Either way a changed value stops matching.
 */
function headhuntGapGoldenHashes(array $files): array
{
    $fixture = headhuntGapFixtureHashes();

    if ($fixture !== []) {
        return $fixture;
    }

    return headhuntGapCommittedHashes($files);
}

/** @return array<string, string> */
function headhuntGapFixtureHashes(): array
{
    $hashes = [];

    foreach (headhuntGapFixturePaths() as $path) {
        if (!is_file($path)) {
            continue;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            continue;
        }

        foreach (headhuntGapFingerprintMap($decoded) as $name => $hash) {
            $hashes[basename($name)] = $hash;
        }
    }

    return $hashes;
}

/** @return array<int, string> */
function headhuntGapFixturePaths(): array
{
    return [
        headhuntGapPath('resources/behavior/golden.json'),
        headhuntGapPath('resources/behavior/fingerprints.json'),
        headhuntGapPath('tests/Fixtures/behavior-golden.json'),
        headhuntGapPath('tests/Fixtures/behavior-fingerprints.json'),
    ];
}

/** Accepts a flat name => sha256 map, or one nested under a wrapper key. */
function headhuntGapFingerprintMap(array $decoded): array
{
    foreach (['fingerprints', 'hashes', 'files'] as $wrapper) {
        if (isset($decoded[$wrapper]) && is_array($decoded[$wrapper])) {
            $decoded = $decoded[$wrapper];
            break;
        }
    }

    $hashes = [];

    foreach ($decoded as $name => $hash) {
        if (!is_string($name) || !is_string($hash)) {
            continue;
        }

        if (preg_match('/^[0-9a-f]{64}$/i', $hash) !== 1) {
            continue;
        }

        $hashes[$name] = strtolower($hash);
    }

    return $hashes;
}

/** @return array<string, string> */
function headhuntGapCommittedHashes(array $files): array
{
    $root = headhuntGapRepositoryRoot();

    if ($root === null) {
        return [];
    }

    $hashes = [];

    foreach ($files as $path) {
        $blobPath = 'HEAD:' . str_replace($root . '/', '', $path);
        $output = [];
        $status = 0;

        exec('git -C ' . escapeshellarg($root) . ' cat-file -e ' . escapeshellarg($blobPath) . ' 2>/dev/null', $output, $status);

        if ($status !== 0) {
            continue;
        }

        $blob = shell_exec('git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg($blobPath) . ' 2>/dev/null');

        if (!is_string($blob)) {
            continue;
        }

        $hashes[basename($path)] = hash('sha256', $blob);
    }

    return $hashes;
}

function headhuntGapRepositoryRoot(): ?string
{
    if (!function_exists('shell_exec') || !function_exists('exec')) {
        return null;
    }

    $output = [];
    $status = 0;

    exec('git -C ' . escapeshellarg(headhuntGapPath('')) . ' rev-parse --show-toplevel 2>/dev/null', $output, $status);

    if ($status !== 0 || $output === []) {
        return null;
    }

    $root = trim((string) $output[0]);

    return is_dir($root) ? $root : null;
}

it('records the unconfirmed headhunt mechanics as a research note instead of behaviour', function () {
    $spec = headhuntGapPath('plan/specs/ogame/headhunt.md');

    expect(is_file($spec))->toBeTrue();

    expect((string) file_get_contents($spec))
        ->toContain('Headhunt')
        ->toContain('unverified')
        ->toContain('host');
});

it('keeps a headhunt policy key out of the module code and the behaviour data', function () {
    $inCode = [];

    foreach (headhuntGapFiles('app', '.php') as $path) {
        if (preg_match('/headhunt/i', headhuntGapCode((string) file_get_contents($path))) === 1) {
            $inCode[] = headhuntGapRelative($path);
        }
    }

    $inData = [];

    foreach (headhuntGapFiles('resources/behavior', '.yaml') as $path) {
        if (preg_match('/^[ \t-]*headhunt[ \t]*:/mi', (string) file_get_contents($path)) === 1) {
            $inData[] = headhuntGapRelative($path);
        }
    }

    expect($inCode)->toBe([])->and($inData)->toBe([]);
});

it('leaves every behaviour data file byte-identical to the committed golden fixture', function () {
    $files = headhuntGapFiles('resources/behavior', '.yaml');
    $golden = headhuntGapGoldenHashes($files);

    $changed = [];

    foreach ($files as $path) {
        $name = basename($path);

        if (!array_key_exists($name, $golden)) {
            continue;
        }

        if (hash_file('sha256', $path) !== $golden[$name]) {
            $changed[] = headhuntGapRelative($path);
        }
    }

    expect($changed)->toBe([]);
});
