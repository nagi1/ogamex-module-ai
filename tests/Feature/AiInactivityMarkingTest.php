<?php

use Illuminate\Foundation\Application;
use Modules\AI\Domain\Operability\AiSituationOverview;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class AiInactivityModuleTestCase extends IsolatedAccountTestCase
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

uses(AiInactivityModuleTestCase::class);

it('shows no inactivity marker below one week of inactivity', function (int $days): void {
    $overview = app(AiSituationOverview::class);

    expect($overview->inactivityMarkerFor($days))->toBe('');
})->with([0, 1, 6]);

it('shows (i) at exactly one week of inactivity', function (): void {
    $overview = app(AiSituationOverview::class);

    expect($overview->inactivityMarkerFor(7))->toBe('(i)');
});

it('keeps (i) through the following three weeks of inactivity', function (int $days): void {
    $overview = app(AiSituationOverview::class);

    expect($overview->inactivityMarkerFor($days))->toBe('(i)');
})->with([8, 14, 21, 27]);

it('shows (I) at exactly 28 days of inactivity', function (): void {
    $overview = app(AiSituationOverview::class);

    expect($overview->inactivityMarkerFor(28))->toBe('(I)');
});

it('keeps (I) past the fourth week of inactivity', function (int $days): void {
    $overview = app(AiSituationOverview::class);

    expect($overview->inactivityMarkerFor($days))->toBe('(I)');
})->with([29, 35, 365]);
