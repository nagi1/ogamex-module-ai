<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Modules\AI\Support\Profit;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class RaidProfitGateModuleTestCase extends IsolatedAccountTestCase
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

uses(RaidProfitGateModuleTestCase::class);

it('scores the source example as 974 profit', function () {
    $profit = app(Profit::class);

    expect($profit->amount(loot: 1000, flightCost: 26))->toBe(974);
    expect($profit->isWorthwhile(loot: 1000, flightCost: 26))->toBeTrue();
});

it('is not worthwhile when the flight cost is above the loot', function () {
    $profit = app(Profit::class);

    expect($profit->amount(loot: 26, flightCost: 1000))->toBe(-974);
    expect($profit->isWorthwhile(loot: 26, flightCost: 1000))->toBeFalse();
});

it('treats break-even as not worthwhile', function () {
    $profit = app(Profit::class);

    expect($profit->amount(loot: 26, flightCost: 26))->toBe(Profit::BREAK_EVEN);
    expect($profit->isWorthwhile(loot: 26, flightCost: 26))->toBeFalse();
});

it('sees one unit above break-even as worthwhile', function () {
    $profit = app(Profit::class);

    expect($profit->amount(loot: 27, flightCost: 26))->toBe(Profit::BREAK_EVEN + 1);
    expect($profit->isWorthwhile(loot: 27, flightCost: 26))->toBeTrue();
});

it('equals the loot when the injected flight cost is zero', function () {
    $profit = app(Profit::class);

    expect($profit->amount(loot: 1000, flightCost: 0))->toBe(1000);
    expect($profit->isWorthwhile(loot: 1000, flightCost: 0))->toBeTrue();
});
