<?php

use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * A behaviour key can only encode a solar-system rule if it says where a planet sits, how big it
 * is or how hot it is. The source states none of those numbers and says the mechanics still need
 * host confirmation, so a match here means unconfirmed host geography was inlined into the module.
 */
const SOLAR_SYSTEM_RULE_PATTERN = '/temperature|(solar|galaxy|orbit|planet|system)s?(position|slot|size|diameter)|(position|slot|size|diameter)s?(in|of|per|within)(solar|galaxy|orbit|planet|system)/';

/** Bare segments that name solar-system geography on their own, whatever their parent is. */
const SOLAR_SYSTEM_BARE_KEYS = ['galaxy', 'position', 'temperature'];

/** Bare segments that are only geographic when a solar-system word appears somewhere in the path. */
const SOLAR_SYSTEM_QUALIFIED_KEYS = ['system', 'size', 'slot'];

const SOLAR_SYSTEM_LOCUS_PATTERN = '/solar|galaxy|orbit|planet|system/';

function solarSystemModuleRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @param  list<string>  $extensions
 * @return list<string>
 */
function solarSystemFilesUnder(string $directory, array $extensions): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! in_array(strtolower($file->getExtension()), $extensions, true)) {
            continue;
        }

        $files[] = $file->getPathname();
    }

    sort($files);

    return $files;
}

/**
 * @return list<string>
 */
function solarSystemBehaviourFiles(): array
{
    return solarSystemFilesUnder(solarSystemModuleRoot() . '/resources/behavior', ['yaml', 'yml']);
}

/**
 * @return list<string>
 */
function solarSystemConfigFiles(): array
{
    return solarSystemFilesUnder(solarSystemModuleRoot() . '/config', ['php']);
}

/**
 * @return array<mixed>
 */
function solarSystemLoadConfig(string $file): array
{
    $config = require $file;

    return is_array($config) ? $config : [];
}

function solarSystemNormaliseKey(string $key): string
{
    return (string) preg_replace('/[^a-z0-9]/', '', strtolower($key));
}

function solarSystemKeyIsForbidden(string $key): bool
{
    $normalised = solarSystemNormaliseKey($key);

    if (preg_match(SOLAR_SYSTEM_RULE_PATTERN, $normalised) === 1) {
        return true;
    }

    $segments = explode('.', strtolower($key));
    $last = solarSystemNormaliseKey((string) end($segments));

    if (in_array($last, SOLAR_SYSTEM_BARE_KEYS, true)) {
        return true;
    }

    if (! in_array($last, SOLAR_SYSTEM_QUALIFIED_KEYS, true)) {
        return false;
    }

    return preg_match(SOLAR_SYSTEM_LOCUS_PATTERN, $normalised) === 1;
}

/**
 * Dotted key paths of a YAML document, built from indentation so a nested rule reads the same as a
 * flat one: "planets:" + "position:" is the rule "planet_position".
 *
 * @return list<string>
 */
function solarSystemYamlKeyPaths(string $file): array
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        return [];
    }

    $stack = [];
    $paths = [];

    foreach ($lines as $line) {
        $key = solarSystemYamlLineKey($line);

        if ($key === null) {
            continue;
        }

        $indent = strlen($line) - strlen(ltrim($line, " \t"));

        while ($stack !== [] && $stack[count($stack) - 1][0] >= $indent) {
            array_pop($stack);
        }

        $prefix = implode('.', array_column($stack, 1));
        $paths[] = $prefix === '' ? $key : $prefix . '.' . $key;
        $stack[] = [$indent, $key];
    }

    return $paths;
}

function solarSystemYamlLineKey(string $line): ?string
{
    $trimmed = trim($line);

    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
        return null;
    }

    if (str_starts_with($trimmed, '- ')) {
        $trimmed = trim(substr($trimmed, 2));
    }

    $colon = strpos($trimmed, ':');

    if ($colon === false) {
        return null;
    }

    $key = trim(substr($trimmed, 0, $colon), " \t\"'");

    return $key === '' ? null : $key;
}

/**
 * @param  array<mixed>  $data
 * @return list<string>
 */
function solarSystemArrayKeyPaths(array $data, string $prefix = ''): array
{
    $paths = [];

    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        $paths[] = $path;

        if (is_array($value)) {
            $paths = array_merge($paths, solarSystemArrayKeyPaths($value, $path));
        }
    }

    return $paths;
}

/**
 * @param  list<string>  $paths
 * @return list<string>
 */
function solarSystemForbiddenPaths(array $paths): array
{
    return array_values(array_filter($paths, 'solarSystemKeyIsForbidden'));
}

/**
 * @return list<string>
 */
function solarSystemScanFixture(string $yaml): array
{
    $file = sys_get_temp_dir() . '/ai-solar-system-' . bin2hex(random_bytes(8)) . '.yaml';
    file_put_contents($file, $yaml);

    try {
        return solarSystemForbiddenPaths(solarSystemYamlKeyPaths($file));
    } finally {
        unlink($file);
    }
}

it('keeps solar-system rules out of the behaviour data', function () {
    $files = solarSystemBehaviourFiles();

    expect($files)->not->toBeEmpty();

    $offenders = [];

    foreach ($files as $file) {
        foreach (solarSystemForbiddenPaths(solarSystemYamlKeyPaths($file)) as $path) {
            $offenders[] = basename($file) . ' -> ' . $path;
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps solar-system rules out of the module config', function () {
    $offenders = [];

    foreach (solarSystemConfigFiles() as $file) {
        $paths = solarSystemArrayKeyPaths(solarSystemLoadConfig($file));

        foreach (solarSystemForbiddenPaths($paths) as $path) {
            $offenders[] = basename($file) . ' -> ' . $path;
        }
    }

    expect($offenders)->toBe([]);
});

it('catches a solar-system rule the moment one is introduced', function (string $yaml, string $flagged) {
    expect(solarSystemScanFixture($yaml))->toBe([$flagged]);
})->with([
    'temperature' => ["max_temperature: 30\n", 'max_temperature'],
    'nested position' => ["planets:\n  position: 3\n", 'planets.position'],
    'nested galaxy' => ["home:\n  galaxy: 1\n", 'home.galaxy'],
    'system slot' => ["system_slot_count: 15\n", 'system_slot_count'],
    'planet size' => ["planet_size: 4\n", 'planet_size'],
    'position in system' => ["position_in_system: 3\n", 'position_in_system'],
]);

it('leaves keys that say nothing about the solar system alone', function (string $yaml) {
    expect(solarSystemScanFixture($yaml))->toBe([]);
})->with([
    'ratio policy' => ["miner_ratio: 0.6\n"],
    'target selection' => ["target_planet_id: 5\n"],
    'module slots' => ["module_slot_count: 3\n"],
    'fleet slots' => ["fleet_slots: 5\n"],
    'queue position' => ["queue_position: 2\n"],
    'prompt choice' => ["system_prompt: aggressive\n"],
]);

it('catches a solar-system rule hidden in a nested config key', function () {
    $config = ['ai' => ['placement' => ['galaxy_position' => 3]]];

    expect(solarSystemForbiddenPaths(solarSystemArrayKeyPaths($config)))
        ->toBe(['ai.placement.galaxy_position']);
});

it('leaves a nested config of ordinary AI policy alone', function () {
    $config = ['ai' => ['miner_ratio' => 0.6, 'target_planet_id' => 5, 'slots' => ['module' => 3]]];

    expect(solarSystemForbiddenPaths(solarSystemArrayKeyPaths($config)))->toBe([]);
});
