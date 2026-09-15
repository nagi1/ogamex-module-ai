#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Gate 2 review — the deterministic half of the over-engineering gate.
 *
 * Runs on the host with no framework boot. It reports the three Gate-2 shapes a machine can prove
 * exactly; readability and "smallest mechanism" stay with the reviewer agent and the spec
 * (plan/details/specs/overengineering-gate.md). Exit code 1 means at least one non-allowlisted
 * finding, so the script sits in `scripts/ogamex gate` and `scripts/ogamex quality`.
 */

$moduleRoot = dirname(__DIR__);

/**
 * Deliberate seams: contracts with one implementation the plan keeps replaceable, with the reason
 * recorded in plan/details/GATE-AUDIT.md. A new entry here needs that same written reason.
 */
const ALLOWED_SINGLE_IMPLEMENTATION = [
    'ArchetypePolicyResolver', // model-backed policy selection, a test override point
    'ContextBuilder',          // model-backed context packing, replaced in tests
    'RunAiSession',            // the host session gateway
    'QueueAiBuilding',         // host action gateways: the host change or test must replace them
    'QueueAiResearch',
    'QueueAiUnits',
    'QueueAiColony',
    'QueueAiExpedition',
    'QueueAiFleetSave',
    'QueueAiRecall',
    'QueueAiRaid',
    'QueueAiSpy',
    'QueueAiTransfer',
];

$moduleFiles = modulePhpFiles($moduleRoot);
$appFiles = phpFiles($moduleRoot.'/app');

/** @var list<array{kind: string, verdict: string, message: string}> $findings */
$findings = array_merge(
    singleImplementationFindings($appFiles),
    deadEnumFindings($moduleFiles),
    deadConfigFindings($moduleRoot.'/config', $appFiles),
);

if ($findings === []) {
    fwrite(STDOUT, "Gate 2 review: clean — no mechanical findings.\n");

    exit(0);
}

$failed = false;

foreach ($findings as $finding) {
    fwrite(STDOUT, sprintf("[%s] %s — %s\n", $finding['verdict'], $finding['kind'], $finding['message']));

    if ($finding['verdict'] !== 'allowed') {
        $failed = true;
    }
}

exit($failed ? 1 : 0);

/**
 * @param  list<string>  $files
 * @return list<array{kind: string, verdict: string, message: string}>
 */
function singleImplementationFindings(array $files): array
{
    $contracts = [];

    foreach ($files as $file) {
        if (!str_contains($file, '/Contracts/')) {
            continue;
        }

        $source = codeOnly((string) file_get_contents($file));

        if (preg_match('/\binterface\s+([A-Za-z_][A-Za-z0-9_]*)/', $source, $match) === 1) {
            $contracts[$match[1]] = 0;
        }
    }

    foreach ($files as $file) {
        foreach (implementedNames($file) as $name) {
            if (array_key_exists($name, $contracts)) {
                $contracts[$name]++;
            }
        }
    }

    $findings = [];
    ksort($contracts);

    foreach ($contracts as $name => $count) {
        if ($count !== 1) {
            continue;
        }

        $allowed = in_array($name, ALLOWED_SINGLE_IMPLEMENTATION, true);
        $findings[] = [
            'kind' => 'single-implementation contract',
            'verdict' => $allowed ? 'allowed' : 'must-fix',
            'message' => sprintf(
                '%s has one implementation%s.',
                $name,
                $allowed ? ' (deliberate seam)' : ' and no test override — collapse it or document the seam'
            ),
        ];
    }

    return $findings;
}

/**
 * @return list<string>
 */
function implementedNames(string $file): array
{
    $source = codeOnly((string) file_get_contents($file));
    $found = preg_match_all(
        '/\bimplements\s+([A-Za-z_][A-Za-z0-9_]*(?:\s*,\s*[A-Za-z_][A-Za-z0-9_]*)*)/',
        $source,
        $matches
    );

    if ($found === false || $found === 0) {
        return [];
    }

    $names = [];

    foreach ($matches[1] as $list) {
        foreach (preg_split('/\s*,\s*/', $list) ?: [] as $name) {
            $names[] = trim($name);
        }
    }

    return $names;
}

/**
 * @param  list<string>  $files
 * @return list<array{kind: string, verdict: string, message: string}>
 */
function deadEnumFindings(array $files): array
{
    // ponytail: O(enums × files) full-file substring scan; instant at this module's size — switch to
    // a tokenised symbol index only if it ever stops being instant.
    $findings = [];

    foreach ($files as $file) {
        if (!str_contains($file, '/Enums/')) {
            continue;
        }

        $source = codeOnly((string) file_get_contents($file));

        if (preg_match('/\benum\s+([A-Za-z_][A-Za-z0-9_]*)/', $source, $match) !== 1) {
            continue;
        }

        $enum = $match[1];
        $references = 0;

        foreach ($files as $candidate) {
            if ($candidate === $file) {
                continue;
            }

            $references += substr_count((string) file_get_contents($candidate), $enum.'::');
        }

        if ($references === 0) {
            $findings[] = [
                'kind' => 'dead enum',
                'verdict' => 'must-fix',
                'message' => sprintf('%s is referenced nowhere.', $enum),
            ];
        }
    }

    return $findings;
}

/**
 * @param  list<string>  $appFiles
 * @return list<array{kind: string, verdict: string, message: string}>
 */
function deadConfigFindings(string $configDir, array $appFiles): array
{
    $findings = [];

    foreach (glob($configDir.'/*.php') ?: [] as $file) {
        $name = basename($file, '.php');

        if ($name === 'config') {
            // Module identity, read by the framework's module loader rather than by app code.
            continue;
        }

        $needle = 'ai.'.$name;
        $referenced = false;

        foreach ($appFiles as $candidate) {
            if (str_contains((string) file_get_contents($candidate), $needle)) {
                $referenced = true;

                break;
            }
        }

        if (!$referenced) {
            $findings[] = [
                'kind' => 'dead config',
                'verdict' => 'must-fix',
                'message' => sprintf('%s.php is read nowhere (no `%s` reference).', $name, $needle),
            ];
        }
    }

    return $findings;
}

/**
 * The source with comments removed, so a word inside a docblock cannot match as code.
 */
function codeOnly(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

/**
 * @return list<string>
 */
function phpFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $paths = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $paths[] = $file->getPathname();
        }
    }

    sort($paths);

    return $paths;
}

/**
 * Every module PHP file except vendored dependencies.
 *
 * @return list<string>
 */
function modulePhpFiles(string $root): array
{
    return array_values(array_filter(
        phpFiles($root),
        static fn (string $path): bool => !str_contains($path, '/vendor/')
    ));
}
