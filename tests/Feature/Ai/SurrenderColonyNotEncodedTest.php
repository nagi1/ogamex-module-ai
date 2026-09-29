<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class AiSurrenderColonyGuardTestCase extends IsolatedAccountTestCase
{
    private string $statusesFile;

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

uses(AiSurrenderColonyGuardTestCase::class);

test('behaviour data declares no surrender colony key', function () {
    $behaviourDirectory = dirname(__DIR__, 3) . '/resources/behavior';

    $behaviourFiles = [];
    if (is_dir($behaviourDirectory)) {
        foreach (File::allFiles($behaviourDirectory) as $behaviourFile) {
            if (in_array($behaviourFile->getExtension(), ['yaml', 'yml'], true)) {
                $behaviourFiles[] = $behaviourFile;
            }
        }
    }

    // An empty list would make the guard vacuous, so a missing behaviour directory fails too.
    expect($behaviourFiles)->not->toBeEmpty();

    $declarations = [];
    foreach ($behaviourFiles as $behaviourFile) {
        // Matching the raw text is deliberately stricter than matching keys alone: a key, a value
        // or a comment naming the mechanic is already a declaration a planner can branch on.
        if (preg_match('/surrender/i', $behaviourFile->getContents()) === 1) {
            $declarations[] = $behaviourFile->getFilename();
        }
    }

    expect($declarations)->toBe([]);
});

test('the module runtime holds no surrender colony branch or config key', function () {
    $moduleRoot = dirname(__DIR__, 3);

    $runtimeDirectories = array_values(array_filter(
        [$moduleRoot . '/app', $moduleRoot . '/config'],
        static fn (string $directory): bool => is_dir($directory),
    ));

    $runtimeFiles = [];
    foreach ($runtimeDirectories as $runtimeDirectory) {
        foreach (File::allFiles($runtimeDirectory) as $runtimeFile) {
            $runtimeFiles[] = $runtimeFile;
        }
    }

    expect($runtimeFiles)->not->toBeEmpty();

    $offenders = [];
    foreach ($runtimeFiles as $runtimeFile) {
        if (preg_match('/surrender/i', $runtimeFile->getContents()) === 1) {
            $offenders[] = $runtimeFile->getRelativePathname();
        }
    }

    // No data key and no code branch means the action set for a colony under attack — and for
    // every other situation — is identical to what it was before WIK-239 was recorded.
    expect($offenders)->toBe([]);
});

test('the surrender colony spec stays unverified documentation', function () {
    $specPath = dirname(__DIR__, 3) . '/plan/specs/ogame/surrender-colony.md';

    expect(is_file($specPath))->toBeTrue();

    $spec = (string) file_get_contents($specPath);

    expect($spec)->toContain('Status: unverified');
    expect($spec)->toContain('Nothing in this file may be encoded');
});
