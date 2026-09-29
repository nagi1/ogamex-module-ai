<?php

use Illuminate\Foundation\Application;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

/*
 * The plan's acceptance asked for this check under tests/Unit. Unit tests are not accepted here: a
 * Feature test boots the app and reads the module tree the account actually runs, so it fails when
 * the module starts treating an unconfirmed concept as a mechanic. The clauses are the plan's.
 */

class DiplomatConceptModuleTestCase extends IsolatedAccountTestCase
{
    private string $statusesFile;

    public function createApplication(): Application
    {
        $trackedFile = dirname(__DIR__, 4) . '/modules_statuses.json';
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

uses(DiplomatConceptModuleTestCase::class);

function diplomatConceptModuleRoot(): string
{
    return dirname(__DIR__, 2);
}

function diplomatConceptSpecBody(): string
{
    $path = diplomatConceptModuleRoot() . '/plan/ai/diplomat-concept.md';

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * Every file under the given directory that names the concept.
 *
 * @return list<string>
 */
function diplomatReferencesUnder(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    $references = [];

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if (stripos((string) file_get_contents($file->getPathname()), 'diplomat') === false) {
            continue;
        }

        $references[] = $file->getPathname();
    }

    return $references;
}

it('ships the Diplomat concept spec', function () {
    expect(is_file(diplomatConceptModuleRoot() . '/plan/ai/diplomat-concept.md'))->toBeTrue();
});

it('records the concept as community-wiki documentation the host must confirm', function () {
    expect(diplomatConceptSpecBody())
        ->toContain('DOCUMENTED (community wiki)')
        ->toContain('medium confidence — host must confirm');
});

it('declares no numeric gameplay values in the numbers section', function () {
    preg_match('/^## Numbers\s*$(.*?)(?=^## |\z)/ms', diplomatConceptSpecBody(), $numbers);

    expect($numbers)->toHaveCount(2)
        ->and(preg_match('/[0-9]/', $numbers[1] ?? ''))->toBe(0);
});

it('carries no numeral other than the source id', function () {
    expect(preg_replace('/WIK-\d+/', '', diplomatConceptSpecBody()))->not->toMatch('/[0-9]/');
});

it('keeps the Diplomat out of the AI module runtime', function () {
    $moduleRoot = diplomatConceptModuleRoot();

    expect(diplomatReferencesUnder($moduleRoot . '/app'))->toBe([])
        ->and(diplomatReferencesUnder($moduleRoot . '/config'))->toBe([]);
});
