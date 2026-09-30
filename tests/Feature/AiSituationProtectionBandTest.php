<?php

use Illuminate\Foundation\Application;
use Modules\AI\Domain\Operability\AiSituationOverview;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class AiProtectionBandOverviewTestCase extends IsolatedAccountTestCase
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
}

uses(AiProtectionBandOverviewTestCase::class);

it('bands a target from zero points up to the end of the 1:5 band', function () {
    $overview = app(AiSituationOverview::class);

    expect($overview->protectionBandFor(0))->toBe('general protection with a 1:5 ratio')
        ->and($overview->protectionBandFor(25000))->toBe('general protection with a 1:5 ratio')
        ->and($overview->protectionBandFor(49999))->toBe('general protection with a 1:5 ratio');
});

it('bands a target across the point where the source bands meet', function () {
    $overview = app(AiSituationOverview::class);

    expect($overview->protectionBandFor(50000))->toBe('general protection with a 1:10 ratio')
        ->and($overview->protectionBandFor(50001))->toBe('general protection with a 1:10 ratio')
        ->and($overview->protectionBandFor(500000))->toBe('general protection with a 1:10 ratio');
});

it('claims no band above the points the source covers', function () {
    $overview = app(AiSituationOverview::class);

    expect($overview->protectionBandFor(500001))->toBe('unknown');
});

it('carries the band on every scanned player before an attack is planned', function () {
    $overview = app()->make(AiSituationOverview::class, [
        'scannedPlayers' => [
            ['name' => 'Newbie', 'points' => 0],
            ['name' => 'Veteran', 'points' => 500000],
            ['name' => 'Untouchable', 'points' => 800000],
        ],
    ]);

    expect($overview->scannedPlayersWithProtectionBand())->toBe([
        [
            'name' => 'Newbie',
            'points' => 0,
            'protection_band' => 'general protection with a 1:5 ratio',
        ],
        [
            'name' => 'Veteran',
            'points' => 500000,
            'protection_band' => 'general protection with a 1:10 ratio',
        ],
        [
            'name' => 'Untouchable',
            'points' => 800000,
            'protection_band' => 'unknown',
        ],
    ]);
});
