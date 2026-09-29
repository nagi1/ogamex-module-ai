<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class ReaperClaimDocumentedOnlyTestCase extends IsolatedAccountTestCase
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

uses(ReaperClaimDocumentedOnlyTestCase::class);

// The pinned artifact is read from the module root, derived from this file so the scenario is
// found wherever the module is installed.
function reaperClaimDocumentedOnlyScenarioPath(): string
{
    return dirname(__DIR__, 2) . '/resources/scenarios/reaper-documented-combat-only.json';
}

it('records the documented reaper claim as documentation only', function () {
    expect(is_file(reaperClaimDocumentedOnlyScenarioPath()))->toBeTrue();

    $claim = json_decode(
        (string) file_get_contents(reaperClaimDocumentedOnlyScenarioPath()),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    expect(strtolower((string) $claim['claim_type']))->toBe('documented')
        ->and($claim['payload']['status'])->toBe('unconfirmed')
        ->and($claim['payload']['mechanics'])->toBeNull();
});

it('produces no combat rule the engine could act on', function () {
    $claim = json_decode(
        (string) file_get_contents(reaperClaimDocumentedOnlyScenarioPath()),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    $numbers = [];
    $collect = function (array $node) use (&$collect, &$numbers): void {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $collect($value);
                continue;
            }

            if (is_int($value) || is_float($value)) {
                $numbers[$key] = $value;
            }
        }
    };

    $collect($claim);

    expect($claim)->not->toHaveKey('expect')
        ->and($numbers)->toBe([]);
});

it('never reflects the documented text as an executable rule', function () {
    $moduleRoot = dirname(__DIR__, 2);

    $files = collect(File::allFiles($moduleRoot . '/app'));

    $behaviorDirectory = $moduleRoot . '/resources/behavior';

    if (is_dir($behaviorDirectory)) {
        $files = $files->merge(File::allFiles($behaviorDirectory));
    }

    $offenders = $files
        ->filter(fn ($file) => str_contains(strtolower((string) file_get_contents($file->getPathname())), 'wik-097'))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
