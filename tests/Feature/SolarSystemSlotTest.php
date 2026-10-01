<?php

use Illuminate\Foundation\Application;
use Modules\AI\Support\SolarSystemSlot;
use Tests\IsolatedAccountTestCase;

class SolarSystemSlotTestCase extends IsolatedAccountTestCase
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

        parent::tearDown();
    }
}

uses(SolarSystemSlotTestCase::class);

it('colonizes every planet slot from 1 through 15', function (int $slot): void {
    expect(SolarSystemSlot::isColonizable($slot))->toBeTrue()
        ->and(SolarSystemSlot::isOuterSpace($slot))->toBeFalse()
        ->and(SolarSystemSlot::isKnownSlot($slot))->toBeTrue();
})->with(range(1, 15));

it('rejects slot 16 as the uncolonizable Outer Space slot', function (): void {
    expect(SolarSystemSlot::isColonizable(16))->toBeFalse()
        ->and(SolarSystemSlot::isOuterSpace(16))->toBeTrue()
        ->and(SolarSystemSlot::isKnownSlot(16))->toBeTrue();
});

it('rejects slot 0, the slot before the first planet slot', function (): void {
    expect(SolarSystemSlot::isColonizable(0))->toBeFalse()
        ->and(SolarSystemSlot::isOuterSpace(0))->toBeFalse()
        ->and(SolarSystemSlot::isKnownSlot(0))->toBeFalse();
});

it('rejects slot 17, the slot after Outer Space', function (): void {
    expect(SolarSystemSlot::isColonizable(17))->toBeFalse()
        ->and(SolarSystemSlot::isOuterSpace(17))->toBeFalse()
        ->and(SolarSystemSlot::isKnownSlot(17))->toBeFalse();
});
