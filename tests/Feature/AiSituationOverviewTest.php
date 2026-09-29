<?php

use Illuminate\Foundation\Application;
use Modules\AI\Domain\Operability\AiSituationOverview;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class AiSituationOverviewModuleTestCase extends IsolatedAccountTestCase
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

uses(AiSituationOverviewModuleTestCase::class);

it('marks a hostile fleet seen through a phalanx unverified and reports no return time', function () {
    $overview = new AiSituationOverview(observations: [
        [
            'label' => 'Hostile fleet inbound at 4:123:8',
            'source' => AiSituationOverview::PHALANX_SOURCE,
            'return_at' => 1_759_000_000,
        ],
    ]);

    $rendered = $overview->renderedObservations();

    expect($rendered)->toHaveCount(1)
        ->and($rendered[0]['label'])->toBe('Hostile fleet inbound at 4:123:8')
        ->and($rendered[0]['provenance'])->toBe('unverified')
        ->and($rendered[0]['return_at'])->toBeNull();
});

it('reports the return time of a hostile fleet read from a direct scan unchanged', function () {
    $scannedReturn = 1_759_000_100;

    $overview = new AiSituationOverview(observations: [
        [
            'label' => 'Hostile fleet inbound at 4:123:8',
            'source' => AiSituationOverview::PHALANX_SOURCE,
            'return_at' => 1_759_000_000,
        ],
        [
            'label' => 'Hostile fleet returning to 4:123:8',
            'source' => AiSituationOverview::DIRECT_SCAN_SOURCE,
            'return_at' => $scannedReturn,
        ],
    ]);

    $rendered = $overview->renderedObservations();

    expect($rendered)->toHaveCount(2)
        ->and($rendered[0]['provenance'])->toBe('unverified')
        ->and($rendered[0]['return_at'])->toBeNull()
        ->and($rendered[1]['provenance'])->not->toBe('unverified')
        ->and($rendered[1]['return_at'])->toBe($scannedReturn);
});

it('reports a scanned return time of zero instead of dropping it as unknown', function () {
    $overview = new AiSituationOverview(observations: [
        [
            'label' => 'Hostile fleet returning to 4:123:8',
            'source' => AiSituationOverview::DIRECT_SCAN_SOURCE,
            'return_at' => 0,
        ],
    ]);

    $rendered = $overview->renderedObservations();

    expect($rendered)->toHaveCount(1)
        ->and($rendered[0]['return_at'])->toBe(0);
});
