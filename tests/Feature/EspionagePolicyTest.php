<?php

use Symfony\Component\Yaml\Yaml;

function aiEspionagePolicyPath(): string
{
    return dirname(__DIR__, 2) . '/resources/behavior/espionage.yaml';
}

function aiEspionagePolicy(): array
{
    return Yaml::parseFile(aiEspionagePolicyPath());
}

function aiEspionageLeafValues(array $node): array
{
    $values = [];

    foreach ($node as $value) {
        if (is_array($value)) {
            $values = array_merge($values, aiEspionageLeafValues($value));

            continue;
        }

        $values[] = $value;
    }

    return $values;
}

function aiEspionageHardcodedOffenders(): array
{
    $offenders = [];
    $root = dirname(__DIR__, 2) . '/app';

    if (!is_dir($root)) {
        return $offenders;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if (!str_contains(strtolower($file->getPathname()), 'spionage')) {
            continue;
        }

        foreach (file($file->getPathname()) ?: [] as $number => $line) {
            if (preg_match('/=>\s*-?\d/', $line) !== 1) {
                continue;
            }

            $offenders[] = $file->getPathname() . ':' . ($number + 1);
        }
    }

    return $offenders;
}

test('the module ships the espionage policy file', function () {
    expect(is_file(aiEspionagePolicyPath()))->toBeTrue();
});

test('the parsed document carries the espionage mission policy root key', function () {
    $document = aiEspionagePolicy();

    expect($document)->toBeArray()->toHaveKey('espionage');
    expect($document['espionage'])->toBeArray()->not->toBeEmpty();
});

test('the espionage mission policy declares the mission the module resolves', function () {
    $mission = aiEspionagePolicy()['espionage'];

    expect($mission)->toHaveKey('mission');
    expect($mission['mission'])->toBe('espionage');
});

test('every declared espionage value is read back from the file', function () {
    $mission = aiEspionagePolicy()['espionage'];
    $raw = (string) file_get_contents(aiEspionagePolicyPath());

    $values = aiEspionageLeafValues($mission);

    expect($values)->not->toBeEmpty();

    foreach ($values as $value) {
        if (!is_string($value)) {
            continue;
        }

        expect($raw)->toContain($value);
    }
});

test('the espionage policy declares no number the source does not state', function () {
    $values = aiEspionageLeafValues(aiEspionagePolicy()['espionage']);

    expect($values)->not->toBeEmpty();

    foreach ($values as $value) {
        expect(is_int($value) || is_float($value))->toBeFalse();
    }
});

test('no espionage threshold is hardcoded in the module PHP', function () {
    expect(aiEspionageHardcodedOffenders())->toBe([]);
});
