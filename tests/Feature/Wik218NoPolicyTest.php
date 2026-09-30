<?php

use Illuminate\Foundation\Application;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

const AI_GUARDED_POLICY_KEY = 'wik_218';

class AiPolicyDataGuardTestCase extends IsolatedAccountTestCase
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

        // The slot registry is static, so a test that registered the module's nav link would
        // leak it into the next one.
        ModuleSlotService::resetSlots();

        parent::tearDown();
    }

    /**
     * @return array<int, string>
     */
    protected function policyDataFiles(string $directory, string $extension): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = glob($directory . DIRECTORY_SEPARATOR . '*.' . $extension);

        return $files === false ? [] : array_values($files);
    }

    /**
     * @return array<int, string>
     */
    protected function wikiDerivedPolicyOffenders(string $directory, string $extension): array
    {
        $pattern = '/' . str_replace('_', '[^a-z0-9]*', AI_GUARDED_POLICY_KEY) . '/i';

        $offenders = [];
        foreach ($this->policyDataFiles($directory, $extension) as $file) {
            if (preg_match($pattern, (string) file_get_contents($file)) === 1) {
                $offenders[] = $file;
            }
        }

        return $offenders;
    }
}

uses(AiPolicyDataGuardTestCase::class);

it('keeps wiki derived policy out of the behaviour data', function () {
    $offenders = $this->wikiDerivedPolicyOffenders(dirname(__DIR__, 2) . '/resources/behavior', 'yaml');

    expect($offenders)->toBe([]);
});

it('keeps wiki derived policy out of the scenarios', function () {
    $offenders = $this->wikiDerivedPolicyOffenders(dirname(__DIR__, 2) . '/resources/scenarios', 'json');

    expect($offenders)->toBe([]);
});

it('detects a planted wiki derived policy key inside a data file', function () {
    $directory = sys_get_temp_dir() . '/ai_policy_guard_' . uniqid('', true);
    mkdir($directory);
    $planted = $directory . '/planted.yaml';
    file_put_contents($planted, AI_GUARDED_POLICY_KEY . ": 1\n");

    $offenders = $this->wikiDerivedPolicyOffenders($directory, 'yaml');

    unlink($planted);
    rmdir($directory);

    expect($offenders)->toBe([$planted]);
});
