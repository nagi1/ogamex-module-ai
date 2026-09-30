<?php

use Illuminate\Foundation\Application;
use Modules\AI\Actions\BuildAiPilotReportAction;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class CollectorCrawlerRuntimeTestCase extends IsolatedAccountTestCase
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

uses(CollectorCrawlerRuntimeTestCase::class);

/**
 * Drive the report the pilot is planned from -- the account executes this, not the calculator.
 *
 * @return array{crawler_production_bonus_percent: float, crawler_energy_multiplier: float}
 */
function collectorCrawlerReport(int $crawlers, int $efficiency): array
{
    return app(BuildAiPilotReportAction::class)->execute($crawlers, $efficiency);
}

it('values one crawler at 0.045 percent of base production for a Collector', function () {
    $report = collectorCrawlerReport(1, 150);

    expect($report['crawler_production_bonus_percent'])->toEqualWithDelta(0.045, 0.0000001);
});

it('grows the crawler bonus in step with the wing', function () {
    $report = collectorCrawlerReport(400, 150);

    expect($report['crawler_production_bonus_percent'])->toEqualWithDelta(18, 0.0000001);
});

it('caps the crawler wing at half of base production', function () {
    // 0.045 percent per crawler crosses the cap between the 1111th and the 1112th crawler.
    expect(collectorCrawlerReport(1111, 150)['crawler_production_bonus_percent'])->toEqualWithDelta(49.995, 0.0000001)
        ->and(collectorCrawlerReport(1112, 150)['crawler_production_bonus_percent'])->toEqualWithDelta(50, 0.0000001)
        ->and(collectorCrawlerReport(100000, 150)['crawler_production_bonus_percent'])->toEqualWithDelta(50, 0.0000001);
});

it('adds nothing to base production without crawlers', function () {
    $report = collectorCrawlerReport(0, 150);

    expect($report['crawler_production_bonus_percent'])->toEqualWithDelta(0, 0.0000001);
});

it('doubles the crawler energy draw at 150 percent efficiency without scaling the bonus', function () {
    $standard = collectorCrawlerReport(200, 100);
    $boosted = collectorCrawlerReport(200, 150);

    expect($boosted['crawler_energy_multiplier'])
        ->toEqualWithDelta($standard['crawler_energy_multiplier'] * 2, 0.0000001)
        ->and($boosted['crawler_production_bonus_percent'])->toEqualWithDelta(9, 0.0000001);
});
