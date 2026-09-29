<?php

use Illuminate\Foundation\Application;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class CounterespionageModuleTestCase extends IsolatedAccountTestCase
{
    private string $statusesFile = '';

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

        // The slot registry is static, so enabling the module would leak into the next test.
        ModuleSlotService::resetSlots();

        parent::tearDown();
    }
}

uses(CounterespionageModuleTestCase::class);

/**
 * The unconfirmed counterespionage spec sits beside the module it will one day constrain.
 */
function counterespionageSpecPath(): string
{
    return dirname(__DIR__, 2) . '/plan/specs/counterespionage.md';
}

function counterespionageSpec(): string
{
    $path = counterespionageSpecPath();

    expect(is_file($path))->toBeTrue();

    return (string) file_get_contents($path);
}

/**
 * The "Numbers" section runs up to the next heading of the same rank.
 */
function counterespionageNumbersSection(string $spec): string
{
    $start = strpos($spec, "\n## Numbers");

    expect($start)->not->toBeFalse();

    $section = substr($spec, $start + 1);
    $next = strpos($section, "\n## ", strlen('## Numbers'));

    return $next === false ? $section : substr($section, 0, $next);
}

it('labels counterespionage as documented but unconfirmed', function () {
    $spec = counterespionageSpec();

    expect($spec)->toContain('- claim type: DOCUMENTED');
    expect($spec)->toContain('- confidence: medium');
});

it('refuses invented figures in the counterespionage numbers section', function () {
    expect(counterespionageNumbersSection(counterespionageSpec()))->not->toMatch('/\d/');
});
