<?php

use Modules\AI\Ai\Defence\DefenceLayerValuation;

it('prices a destroyed defence rebuild at the 30% gap, not the full build cost', function (): void {
    // 100 Ion Cannons at 2,000 / 6,000: full rebuild is 200,000 / 600,000.
    $budget = app(DefenceLayerValuation::class)->rebuildBudget([
        'ion_cannon' => ['metal' => 2000, 'crystal' => 6000, 'destroyed' => 100],
    ]);

    expect($budget['metal'])->toBe(60000)
        ->and($budget['crystal'])->toBe(180000)
        ->and($budget['metal'] + $budget['crystal'])->toBe(240000)
        ->and($budget['deuterium'])->toBe(0);
});

it('budgets nothing when no defensive unit was destroyed', function (): void {
    $budget = app(DefenceLayerValuation::class)->rebuildBudget([
        'ion_cannon' => ['metal' => 2000, 'crystal' => 6000, 'deuterium' => 0, 'destroyed' => 0],
    ]);

    expect($budget)->toBe(['metal' => 0, 'crystal' => 0, 'deuterium' => 0]);
});

it('requires Anti-Ballistic Missiles one-for-one against observed Interplanetary Missiles', function (): void {
    $valuation = app(DefenceLayerValuation::class);

    expect($valuation->requiredAntiBallisticMissiles(20))->toBe(20)
        ->and($valuation->requiredAntiBallisticMissiles(21))->toBe(21)
        ->and($valuation->requiredAntiBallisticMissiles(0))->toBe(0);
});

it('withholds shield dome and Plasma Turret layering until the ABM count matches the observed missiles', function (): void {
    $valuation = app(DefenceLayerValuation::class);

    expect($valuation->layeringUnlocked(20, 19))->toBeFalse()
        ->and($valuation->endgameLayeringQueue(20, 19))->toBe([]);

    $queue = $valuation->endgameLayeringQueue(20, 20);

    expect($valuation->layeringUnlocked(20, 20))->toBeTrue()
        ->and($queue['plasma_turret'])->toBeGreaterThanOrEqual(50)
        ->and($queue['small_shield_dome'])->toBeGreaterThanOrEqual(1)
        ->and($queue['large_shield_dome'])->toBeGreaterThanOrEqual(1);
});

it('allows layering past the 1:1 bound and when no missile is incoming', function (): void {
    $valuation = app(DefenceLayerValuation::class);

    expect($valuation->endgameLayeringQueue(20, 21))->toHaveKey('plasma_turret')
        ->and($valuation->endgameLayeringQueue(0, 0))->toHaveKey('large_shield_dome')
        ->and($valuation->endgameLayeringQueue(1, 0))->toBe([]);
});
