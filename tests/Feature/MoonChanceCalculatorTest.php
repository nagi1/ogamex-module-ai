<?php

use Illuminate\Foundation\Application;
use Modules\AI\Support\MoonChanceCalculator;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

/**
 * The module only loads when the host's status file says so, so the calculator is
 * resolved out of the container the same way an account would resolve it.
 */
class MoonChanceCalculatorModuleTestCase extends IsolatedAccountTestCase
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

        // The slot registry is static, so a registered nav link would leak into the next test.
        ModuleSlotService::resetSlots();

        parent::tearDown();
    }
}

uses(MoonChanceCalculatorModuleTestCase::class);

beforeEach(function () {
    $this->calculator = app(MoonChanceCalculator::class);
});

it('demands 1250 light fighters for a 20 percent moon chance on a 40 percent debris universe', function () {
    expect($this->calculator->requiredLightFighters(20.0, 0.40))->toEqualWithDelta(1250.0, 1.0e-9);
});

it('demands three quarters of the reference-ratio fleet on a 40 percent debris universe', function () {
    $atReferenceRatio = $this->calculator->requiredLightFighters(20.0, MoonChanceCalculator::REFERENCE_DEBRIS_RATIO);
    $atFortyPercent = $this->calculator->requiredLightFighters(20.0, 0.40);

    expect($atFortyPercent)->toEqualWithDelta($atReferenceRatio * 0.75, 1.0e-9);
});

it('scales a fleet computed for the reference ratio by three quarters at 40 percent debris', function () {
    $atReferenceRatio = $this->calculator->requiredLightFighters(20.0, MoonChanceCalculator::REFERENCE_DEBRIS_RATIO);

    expect($this->calculator->scaleFleetSize($atReferenceRatio, 0.40))
        ->toEqualWithDelta($atReferenceRatio * 0.75, 1.0e-9);
});

it('leaves a fleet computed for the reference ratio untouched', function () {
    expect($this->calculator->scaleFleetSize(2000.0, MoonChanceCalculator::REFERENCE_DEBRIS_RATIO))
        ->toEqualWithDelta(2000.0, 1.0e-9);
});

it('needs only half the fleet when the universe returns twice the reference debris', function () {
    expect($this->calculator->scaleFleetSize(1000.0, 0.60))->toEqualWithDelta(500.0, 1.0e-9);
});

it('needs double the fleet when the universe returns half the reference debris', function () {
    expect($this->calculator->scaleFleetSize(1000.0, 0.15))->toEqualWithDelta(2000.0, 1.0e-9);
});

it('demands a fleet proportional to the moon chance asked for', function () {
    expect($this->calculator->requiredLightFighters(10.0, 0.40))->toEqualWithDelta(625.0, 1.0e-9);
});

it('refuses to size a fleet for a universe that returns no debris at all', function () {
    expect(fn () => $this->calculator->requiredLightFighters(20.0, 0.0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->calculator->scaleFleetSize(1250.0, 0.0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->calculator->scaleFleetSize(1250.0, -0.40))
        ->toThrow(InvalidArgumentException::class);
});
