<?php

use Illuminate\Foundation\Application;
use OGame\Services\ModuleSlotService;
use Symfony\Component\Yaml\Yaml;
use Tests\IsolatedAccountTestCase;

class ReaperPolicyModuleTestCase extends IsolatedAccountTestCase
{
    private string $statusesFile;

    public function createApplication(): Application
    {
        $trackedFile = dirname(__DIR__, 4) . '/modules_statuses.json';
        $statuses = is_file($trackedFile)
            ? (json_decode((string) file_get_contents($trackedFile), true) ?? [])
            : [];
        $statuses['AI'] = true;

        $this->statusesFile = sys_get_temp_dir() . '/modules_statuses_' . uniqid('', true) . '.json';
        file_put_contents($this->statusesFile, json_encode($statuses, JSON_PRETTY_PRINT));
        putenv('MODULES_STATUSES_FILE=' . $this->statusesFile);

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        if (isset($this->statusesFile) && is_file($this->statusesFile)) {
            unlink($this->statusesFile);
        }

        putenv('MODULES_STATUSES_FILE');

        // The slot registry is static, so a test that registered the module's nav link would
        // leak it into the next one.
        ModuleSlotService::resetSlots();

        parent::tearDown();
    }
}

uses(ReaperPolicyModuleTestCase::class);

it('keeps the unmodelled reaper out of fleet composition', function () {
    $policy = Yaml::parseFile(dirname(__DIR__, 2) . '/resources/behavior/reaper.yaml');

    expect($policy['unit'])->toBe('reaper')
        ->and($policy['fleet_composition']['enabled'])->toBeFalse()
        ->and($policy['stats'])->toBe([]);
});

it('adds the reaper to fleet composition only once a policy fixture enables it', function () {
    $shipped = Yaml::parseFile(dirname(__DIR__, 2) . '/resources/behavior/reaper.yaml');
    $fixture = tempnam(sys_get_temp_dir(), 'reaper_policy_');

    file_put_contents($fixture, Yaml::dump([
        'unit' => $shipped['unit'],
        'fleet_composition' => ['enabled' => true],
        'stats' => $shipped['stats'],
    ], 4));

    $enabled = Yaml::parseFile($fixture);
    unlink($fixture);

    expect($enabled['unit'])->toBe($shipped['unit'])
        ->and($enabled['stats'])->toBe($shipped['stats'])
        ->and($enabled['fleet_composition']['enabled'])->toBeTrue();
});
