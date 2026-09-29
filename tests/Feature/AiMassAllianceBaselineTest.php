<?php

/**
 * WIK-189 — characterisation of the AI module's current silence on "mass alliance".
 *
 * No source states a trigger, a threshold or any other number for the concept, and the
 * module holds no principle or code for it, so nothing may be scored, thresholded or
 * hard-coded yet. The acceptance qualifier "without a source-confirmed number" cannot be
 * encoded: no test can tell a host-confirmed number from an invented one. This file
 * therefore pins total absence, which is the only implementable reading, and the plan's
 * RISKS already accept that the pin goes stale the day a confirmed rule lands — at which
 * point this file must be retired deliberately rather than quietly widened.
 */

/**
 * The AI module root, resolved from this file so the scan needs no booted application and
 * no framework helper that a module rename would silently break.
 */
function aiMassAllianceModuleRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Matches every spelling the concept appears in: MassAlliance, mass_alliance,
 * mass-alliance, mass alliance.
 */
function aiMassAlliancePattern(): string
{
    return '/mass[\s_-]*alliance/i';
}

/**
 * Every file below $root, recursively, excluding test trees and sorted so a failure lists
 * the same offenders in the same order on every run.
 *
 * @return list<string>
 */
function aiMassAllianceFiles(string $root): array
{
    if (! is_dir($root)) {
        return [];
    }

    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $pathname = $file->getPathname();
        $relative = substr($pathname, strlen($prefix));

        if (in_array('tests', explode(DIRECTORY_SEPARATOR, $relative), true)) {
            continue;
        }

        $files[] = $pathname;
    }

    sort($files);

    return $files;
}

/**
 * Files whose name carries the concept — an action class, an enum, a scenario file.
 *
 * @return list<string>
 */
function aiMassAllianceNamed(string $root): array
{
    $offenders = [];

    foreach (aiMassAllianceFiles($root) as $file) {
        if (preg_match(aiMassAlliancePattern(), basename($file)) === 1) {
            $offenders[] = $file;
        }
    }

    return $offenders;
}

/**
 * PHP files whose contents carry the concept — a config key, an enum case added to an
 * existing enum, a constant. A filename-only scan misses all three.
 *
 * @return list<string>
 */
function aiMassAllianceMentioned(string $root): array
{
    $offenders = [];

    foreach (aiMassAllianceFiles($root) as $file) {
        if (! str_ends_with($file, '.php')) {
            continue;
        }

        if (preg_match(aiMassAlliancePattern(), (string) file_get_contents($file)) === 1) {
            $offenders[] = $file;
        }
    }

    return $offenders;
}

function aiMassAllianceRemove(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
            continue;
        }

        unlink($entry->getPathname());
    }

    rmdir($directory);
}

it('ships no action, enum or scenario file named after mass alliance', function (): void {
    expect(aiMassAllianceNamed(aiMassAllianceModuleRoot()))->toBe([]);
});

it('declares no mass alliance config key, enum case or constant in the AI module', function (): void {
    expect(aiMassAllianceMentioned(aiMassAllianceModuleRoot()))->toBe([]);
});

it('flags a planted mass alliance artefact and stays quiet on a clean tree', function (): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ai-mass-alliance-' . bin2hex(random_bytes(8));

    try {
        mkdir($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Actions', 0777, true);
        mkdir($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Enums', 0777, true);
        mkdir($root . DIRECTORY_SEPARATOR . 'config', 0777, true);

        expect(aiMassAllianceNamed($root))->toBe([]);
        expect(aiMassAllianceMentioned($root))->toBe([]);

        file_put_contents(
            $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Actions' . DIRECTORY_SEPARATOR . 'MassAllianceAction.php',
            "<?php\n\nfinal class MassAllianceAction\n{\n}\n",
        );
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Enums' . DIRECTORY_SEPARATOR . 'AiCandidateActionType.php',
            "<?php\n\nenum AiCandidateActionType\n{\n    case MassAlliance;\n}\n",
        );
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ai.php',
            "<?php\n\nreturn ['mass_alliance' => ['members' => 5]];\n",
        );

        expect(aiMassAllianceNamed($root))->toHaveCount(1);
        expect(aiMassAllianceMentioned($root))->toHaveCount(3);
    } finally {
        aiMassAllianceRemove($root);
    }
});
