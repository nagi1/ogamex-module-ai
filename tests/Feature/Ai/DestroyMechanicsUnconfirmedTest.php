<?php

use Illuminate\Foundation\Application;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class DestroyMechanicsModuleTestCase extends IsolatedAccountTestCase
{
    private string $statusesFile = '';

    public function createApplication(): Application
    {
        $trackedFile = dirname(__DIR__, 5) . '/modules_statuses.json';
        $statuses = json_decode((string) file_get_contents($trackedFile), true);
        $statuses['AI'] = true;
        $this->statusesFile = sys_get_temp_dir() . '/modules_statuses_' . uniqid('', true) . '.json';
        file_put_contents($this->statusesFile, json_encode($statuses, JSON_PRETTY_PRINT));
        putenv('MODULES_STATUSES_FILE=' . $this->statusesFile);

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        if (is_file($this->statusesFile)) {
            unlink($this->statusesFile);
        }

        putenv('MODULES_STATUSES_FILE');

        // The slot registry is static, so a test that registered the module's nav link would
        // leak it into the next one.
        ModuleSlotService::resetSlots();

        parent::tearDown();
    }
}

uses(DestroyMechanicsModuleTestCase::class);

function destroyMechanicsModuleRoot(): string
{
    return dirname(__DIR__, 3);
}

/**
 * Every plain file below the given directory, in a stable order.
 *
 * @return list<string>
 */
function destroyMechanicsFiles(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
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
function destroyMechanicsPhpFiles(string $directory): array
{
    return array_values(array_filter(
        destroyMechanicsFiles($directory),
        static fn (string $path): bool => pathinfo($path, PATHINFO_EXTENSION) === 'php',
    ));
}

/**
 * Constant and enum-case names declared in a single file. Identifiers only, so prose in a
 * docblock cannot trip the guard.
 *
 * @return list<string>
 */
function destroyMechanicsDeclaredIdentifiers(string $file): array
{
    $contents = (string) file_get_contents($file);

    preg_match_all('/\b(?:const|case)\s+([A-Za-z_][A-Za-z0-9_]*)/', $contents, $matches);

    return $matches[1];
}

/**
 * The names an account could actually pick from: one per action class, plus every decision,
 * stop reason and archetype case declared beside them.
 *
 * @return list<string>
 */
function destroyMechanicsActionCatalogue(): array
{
    $root = destroyMechanicsModuleRoot();

    $catalogue = [];

    foreach (['/app/Actions', '/app/Enums'] as $relative) {
        foreach (destroyMechanicsPhpFiles($root . $relative) as $file) {
            $catalogue[] = pathinfo($file, PATHINFO_FILENAME);
            $catalogue = array_merge($catalogue, destroyMechanicsDeclaredIdentifiers($file));
        }
    }

    return array_values(array_unique($catalogue));
}

/**
 * @return list<string>
 */
function destroyMechanicsModuleConstants(): array
{
    $root = destroyMechanicsModuleRoot();

    $declared = [];

    foreach (destroyMechanicsPhpFiles($root . '/app') as $file) {
        $declared = array_merge($declared, destroyMechanicsDeclaredIdentifiers($file));
    }

    return array_values(array_unique($declared));
}

/**
 * @return list<string>
 */
function destroyMechanicsDataIdentifiers(): array
{
    $root = destroyMechanicsModuleRoot();

    $identifiers = [];

    foreach (['/resources/behavior', '/resources/scenarios'] as $relative) {
        foreach (destroyMechanicsFiles($root . $relative) as $file) {
            $identifiers[] = pathinfo($file, PATHINFO_FILENAME);
            $identifiers = array_merge($identifiers, destroyMechanicsQuotedKeys($file));
        }
    }

    return array_values(array_unique($identifiers));
}

/**
 * @return list<string>
 */
function destroyMechanicsQuotedKeys(string $file): array
{
    $contents = (string) file_get_contents($file);

    preg_match_all('/["\']([A-Za-z_][A-Za-z0-9_]*)\s*["\']\s*(?:=>|:)/', $contents, $matches);

    return $matches[1];
}

function destroyMechanicsMentionsMoonDestruction(string $identifier): bool
{
    $normalised = strtolower($identifier);

    if (! str_contains($normalised, 'moon')) {
        return false;
    }

    // "destruction" does not contain "destroy", so both stems have to be checked.
    return str_contains($normalised, 'destroy') || str_contains($normalised, 'destruct');
}

it('exposes no destroy-moon action through the AI action catalogue', function () {
    $catalogue = destroyMechanicsActionCatalogue();

    expect($catalogue)->not->toBeEmpty();

    $offenders = array_values(array_filter($catalogue, 'destroyMechanicsMentionsMoonDestruction'));

    expect($offenders)->toBe([]);
});

it('declares no destroy-moon constant in the AI module', function () {
    $declared = destroyMechanicsModuleConstants();

    expect($declared)->not->toBeEmpty();

    $offenders = array_values(array_filter($declared, 'destroyMechanicsMentionsMoonDestruction'));

    expect($offenders)->toBe([]);
});

it('keeps destroy-moon tunables out of the module data files', function () {
    $identifiers = destroyMechanicsDataIdentifiers();

    $offenders = array_values(array_filter($identifiers, 'destroyMechanicsMentionsMoonDestruction'));

    expect($offenders)->toBe([]);
});

it('records the destroy-moon source as unconfirmed in the module spec', function () {
    $spec = destroyMechanicsModuleRoot() . '/plan/specs/destroy.md';

    expect(is_file($spec))->toBeTrue();

    $contents = strtolower((string) file_get_contents($spec));

    expect(str_contains($contents, 'unconfirmed'))->toBeTrue();
});
