<?php

use Illuminate\Foundation\Application;
use Modules\AI\Ai\Defence\DefenceLayerValuation;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class DefenceLayerValuationModuleTestCase extends IsolatedAccountTestCase
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

uses(DefenceLayerValuationModuleTestCase::class);

it('prices 100 destroyed Ion Cannons at the 30% rebuild gap and not the full build cost', function (): void {
    // 100 Ion Cannons at 2,000 / 6,000: a full rebuild would be 200,000 / 600,000.
    $budget = app(DefenceLayerValuation::class)->rebuildBudget([
        'ion_cannon' => ['metal' => 2000, 'crystal' => 6000, 'destroyed' => 100],
    ]);

    expect($budget)->toBe(['metal' => 60000, 'crystal' => 180000, 'deuterium' => 0])
        ->and($budget['metal'])->not->toBe(200000)
        ->and($budget['crystal'])->not->toBe(600000);
});

it('budgets nothing for destroyed-count zero', function (): void {
    $budget = app(DefenceLayerValuation::class)->rebuildBudget([
        'ion_cannon' => ['metal' => 2000, 'crystal' => 6000, 'destroyed' => 0],
    ]);

    expect($budget)->toBe(['metal' => 0, 'crystal' => 0, 'deuterium' => 0]);
});

it('requires one Anti-Ballistic Missile per observed Interplanetary Missile at the 1:1 bound', function (): void {
    $valuation = app(DefenceLayerValuation::class);

    expect($valuation->requiredAntiBallisticMissiles(20))->toBe(20)
        ->and($valuation->requiredAntiBallisticMissiles(21))->toBe(21)
        ->and($valuation->requiredAntiBallisticMissiles(1))->toBe(1)
        ->and($valuation->requiredAntiBallisticMissiles(0))->toBe(0);
});

it('withholds shield dome and Plasma Turret layering until the ABM count matches the observed missiles', function (): void {
    $valuation = app(DefenceLayerValuation::class);

    expect($valuation->layeringUnlocked(20, 19))->toBeFalse()
        ->and($valuation->endgameLayeringQueue(20, 19))->toBe([])
        ->and($valuation->layeringUnlocked(1, 0))->toBeFalse()
        ->and($valuation->endgameLayeringQueue(1, 0))->toBe([]);
});

it('emits 50+ Plasma Turrets with both shield domes at, past and without the missile bound', function (): void {
    $valuation = app(DefenceLayerValuation::class);

    $atBound = $valuation->endgameLayeringQueue(20, 20);

    expect($valuation->layeringUnlocked(20, 20))->toBeTrue()
        ->and($atBound)->toHaveKeys(['plasma_turret', 'small_shield_dome', 'large_shield_dome'])
        ->and($atBound['plasma_turret'])->toBeGreaterThanOrEqual(50)
        ->and($atBound['small_shield_dome'])->toBeGreaterThanOrEqual(1)
        ->and($atBound['large_shield_dome'])->toBeGreaterThanOrEqual(1);

    $pastBound = $valuation->endgameLayeringQueue(20, 21);

    expect($valuation->layeringUnlocked(20, 21))->toBeTrue()
        ->and($pastBound)->toHaveKeys(['plasma_turret', 'small_shield_dome', 'large_shield_dome']);

    $noMissiles = $valuation->endgameLayeringQueue(0, 0);

    expect($valuation->layeringUnlocked(0, 0))->toBeTrue()
        ->and($noMissiles)->toHaveKeys(['plasma_turret', 'small_shield_dome', 'large_shield_dome']);
});
