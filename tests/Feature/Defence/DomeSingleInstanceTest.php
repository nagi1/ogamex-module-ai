<?php

use Modules\AI\Ai\Defence\QueueableDefencePlanner;

$scenarioPath = __DIR__ . '/../../../resources/scenarios/defence-dome-single-instance.json';
$scenario = json_decode((string) file_get_contents($scenarioPath), true, flags: JSON_THROW_ON_ERROR);

foreach ($scenario['planets'] as $planet) {
    test('single instance domes — ' . $planet['name'], function () use ($planet): void {
        $queue = app(QueueableDefencePlanner::class)->plan(
            $planet['built'],
            $planet['queued'],
            $planet['requested'],
        );

        expect($queue)->toBe($planet['expected']);
    });
}

test('a dome is one fodder unit, never a multiplier', function (): void {
    $planner = app(QueueableDefencePlanner::class);

    expect($planner->fodderUnits(['RocketLauncher' => 10]))->toBe(10)
        ->and($planner->fodderUnits(['RocketLauncher' => 10, 'LargeShieldDome' => 1]))->toBe(11)
        ->and($planner->fodderUnits(['LargeShieldDome' => 1, 'SmallShieldDome' => 1]))->toBe(2);
});
