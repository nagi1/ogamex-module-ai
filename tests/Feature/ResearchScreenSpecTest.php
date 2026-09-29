<?php

use Illuminate\Foundation\Application;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class ResearchScreenModuleTestCase extends IsolatedAccountTestCase
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

uses(ResearchScreenModuleTestCase::class);

$specPath = dirname(__DIR__, 2) . '/plan/research/ogame/specs/research-screen.md';

it('keeps the research screen note free of any number the account could act on', function () use ($specPath) {
    expect(is_file($specPath))->toBeTrue();

    $body = (string) file_get_contents($specPath);

    // The covering principle is named by an identifier, not by a quantity, so it is removed
    // before looking for the cost, level or bonus that would have to be verified first.
    $withoutPrinciples = (string) preg_replace('/\bRES-\d+\b/', '', $body);

    expect($withoutPrinciples)->not->toMatch('/\d/');
});

it('names the principle that covers the research screen', function () use ($specPath) {
    expect(is_file($specPath))->toBeTrue();

    $body = (string) file_get_contents($specPath);

    expect($body)->toMatch('/\bRES-003\b[^.]*\bprinciple\b/i');
});
