<?php

declare(strict_types=1);

/**
 * Reduce a clover report to this module's own PCOV coverage.
 *
 * The module suite runs through the host's PHPUnit configuration, so the report also
 * contains host application files. Only `Modules/AI/app` counts here, and `app/Rules`
 * is excluded because those PHPStan rules are verified by the PHPStan gate — the same
 * scope the module's own phpunit.xml declares.
 *
 * Exits non-zero when the module is not fully covered, so `scripts/ogamex coverage`
 * fails a change that leaves module code untested.
 */

$report = $argv[1] ?? '';

if ($report === '' || !is_file($report)) {
    fwrite(STDERR, "Coverage summary: clover report [{$report}] was not found.\n");

    exit(2);
}

$xml = simplexml_load_file($report);

if ($xml === false) {
    fwrite(STDERR, "Coverage summary: clover report [{$report}] could not be parsed.\n");

    exit(2);
}

$statements = 0;
$covered = 0;
$gaps = [];

foreach ($xml->project->package as $package) {
    foreach ($package->file as $file) {
        $name = (string) $file['name'];

        if (!str_contains($name, '/Modules/AI/app/') || str_contains($name, '/app/Rules/')) {
            continue;
        }

        foreach ($file->class as $class) {
            $statements += (int) $class->metrics['statements'];
            $covered += (int) $class->metrics['coveredstatements'];

            if ((int) $class->metrics['coveredstatements'] < (int) $class->metrics['statements']) {
                $gaps[] = sprintf(
                    '%s: %d/%d',
                    substr($name, (int) strpos($name, '/Modules/') + 1),
                    (int) $class->metrics['coveredstatements'],
                    (int) $class->metrics['statements'],
                );
            }
        }
    }
}

if ($statements === 0) {
    fwrite(STDERR, "Coverage summary: no module statements were found in [{$report}].\n");

    exit(2);
}

$percentage = $covered / $statements * 100;
printf("Module coverage (Modules/AI/app, excluding app/Rules): %d/%d = %.2f%%\n", $covered, $statements, $percentage);

if ($gaps === []) {
    exit(0);
}

fwrite(STDERR, "Uncovered module code:\n");

foreach ($gaps as $gap) {
    fwrite(STDERR, "  {$gap}\n");
}

exit(1);
