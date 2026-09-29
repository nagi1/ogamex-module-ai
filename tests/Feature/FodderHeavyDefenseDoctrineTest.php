<?php

use Illuminate\Foundation\Application;
use Modules\AI\Defense\DefenseCompositionPlanner;
use Modules\AI\Defense\FodderHeavyDefenseDoctrine;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

// The classes sit under app/Ai/Defense while their namespace resolves against app/, so only an
// optimised classmap finds them by path; require_once is a no-op when the autoloader already
// loaded them and cannot declare a class a second time when it did not.
if (! class_exists(FodderHeavyDefenseDoctrine::class)) {
    require_once __DIR__ . '/../../app/Ai/Defense/FodderHeavyDefenseDoctrine.php';
}

if (! class_exists(DefenseCompositionPlanner::class)) {
    require_once __DIR__ . '/../../app/Ai/Defense/DefenseCompositionPlanner.php';
}

class FodderHeavyDoctrineModuleTestCase extends IsolatedAccountTestCase
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

uses(FodderHeavyDoctrineModuleTestCase::class);

it('builds the fodder tier before every support unit of the wall', function () {
    $fodder = 100;

    $plan = app(DefenseCompositionPlanner::class)->plan($fodder);
    $order = array_keys($plan);
    $fodderIndex = array_search(FodderHeavyDefenseDoctrine::FODDER_UNIT, $order, true);

    expect($order)->toContain(FodderHeavyDefenseDoctrine::FODDER_UNIT)
        ->and($plan[FodderHeavyDefenseDoctrine::FODDER_UNIT])->toBe($fodder)
        ->and($fodderIndex)->toBe(0);

    foreach ($order as $index => $unit) {
        if ($unit === FodderHeavyDefenseDoctrine::FODDER_UNIT) {
            continue;
        }

        expect($index)->toBeGreaterThan($fodderIndex);
    }
});

it('matches the fodder ratios exactly once the fodder count is a multiple of the ratio lcm', function () {
    $fodder = 100;

    $plan = app(DefenseCompositionPlanner::class)->plan($fodder);

    expect($plan[FodderHeavyDefenseDoctrine::HEAVY_LASER])->toBe(20)
        ->and($plan[FodderHeavyDefenseDoctrine::GAUSS_CANNON])->toBe(5)
        ->and($plan[FodderHeavyDefenseDoctrine::ION_CANNON])->toBe(5)
        ->and($plan[FodderHeavyDefenseDoctrine::PLASMA_TURRET])->toBe(2)
        ->and($fodder)->toBe(5 * $plan[FodderHeavyDefenseDoctrine::HEAVY_LASER])
        ->and($fodder)->toBe(20 * $plan[FodderHeavyDefenseDoctrine::GAUSS_CANNON])
        ->and($fodder)->toBe(20 * $plan[FodderHeavyDefenseDoctrine::ION_CANNON])
        ->and($fodder)->toBe(50 * $plan[FodderHeavyDefenseDoctrine::PLASMA_TURRET])
        ->and($plan[FodderHeavyDefenseDoctrine::SMALL_SHIELD_DOME])->toBe(1)
        ->and($plan[FodderHeavyDefenseDoctrine::LARGE_SHIELD_DOME])->toBe(1);
});

it('holds the same chained equality past the lcm bound', function () {
    $fodder = 300;

    $plan = app(DefenseCompositionPlanner::class)->plan($fodder);

    expect($fodder)->toBe(5 * $plan[FodderHeavyDefenseDoctrine::HEAVY_LASER])
        ->and($fodder)->toBe(20 * $plan[FodderHeavyDefenseDoctrine::GAUSS_CANNON])
        ->and($fodder)->toBe(20 * $plan[FodderHeavyDefenseDoctrine::ION_CANNON])
        ->and($fodder)->toBe(50 * $plan[FodderHeavyDefenseDoctrine::PLASMA_TURRET])
        ->and($plan[FodderHeavyDefenseDoctrine::SMALL_SHIELD_DOME])->toBe(1)
        ->and($plan[FodderHeavyDefenseDoctrine::LARGE_SHIELD_DOME])->toBe(1);
});

it('still returns the whole wall at zero fodder', function () {
    $plan = app(DefenseCompositionPlanner::class)->plan(0);

    expect($plan[FodderHeavyDefenseDoctrine::FODDER_UNIT])->toBe(0)
        ->and($plan[FodderHeavyDefenseDoctrine::HEAVY_LASER])->toBe(0)
        ->and($plan[FodderHeavyDefenseDoctrine::GAUSS_CANNON])->toBe(0)
        ->and($plan[FodderHeavyDefenseDoctrine::ION_CANNON])->toBe(0)
        ->and($plan[FodderHeavyDefenseDoctrine::PLASMA_TURRET])->toBe(0)
        ->and($plan[FodderHeavyDefenseDoctrine::SMALL_SHIELD_DOME])->toBe(1)
        ->and($plan[FodderHeavyDefenseDoctrine::LARGE_SHIELD_DOME])->toBe(1);
});

it('truncates every count to whole units just below the ratio lcm', function () {
    $plan = app(DefenseCompositionPlanner::class)->plan(99);

    expect($plan[FodderHeavyDefenseDoctrine::HEAVY_LASER])->toBe(19)
        ->and($plan[FodderHeavyDefenseDoctrine::GAUSS_CANNON])->toBe(4)
        ->and($plan[FodderHeavyDefenseDoctrine::ION_CANNON])->toBe(4)
        ->and($plan[FodderHeavyDefenseDoctrine::PLASMA_TURRET])->toBe(1)
        ->and($plan[FodderHeavyDefenseDoctrine::FODDER_UNIT])->toBe(99)
        ->and(5 * $plan[FodderHeavyDefenseDoctrine::HEAVY_LASER])->toBe(95);
});

it('never returns a partial wall for any fodder count', function () {
    $planner = app(DefenseCompositionPlanner::class);

    foreach ([0, 1, 4, 5, 19, 20, 49, 50, 99, 100, 101, 250, 999, 1000] as $fodder) {
        $plan = $planner->plan($fodder);

        expect($plan[FodderHeavyDefenseDoctrine::SMALL_SHIELD_DOME])->toBe(1)
            ->and($plan[FodderHeavyDefenseDoctrine::LARGE_SHIELD_DOME])->toBe(1)
            ->and($plan[FodderHeavyDefenseDoctrine::FODDER_UNIT])->toBe($fodder)
            ->and($plan[FodderHeavyDefenseDoctrine::HEAVY_LASER])->toBe(intdiv($fodder, 5))
            ->and($plan[FodderHeavyDefenseDoctrine::GAUSS_CANNON])->toBe(intdiv($fodder, 20))
            ->and($plan[FodderHeavyDefenseDoctrine::ION_CANNON])->toBe(intdiv($fodder, 20))
            ->and($plan[FodderHeavyDefenseDoctrine::PLASMA_TURRET])->toBe(intdiv($fodder, 50));
    }
});

it('maximizes anti-ballistic missiles for the doctrine at every fodder count', function () {
    $planner = app(DefenseCompositionPlanner::class);

    expect($planner->maximizesAntiBallisticMissiles(100))->toBeTrue()
        ->and($planner->maximizesAntiBallisticMissiles(0))->toBeTrue();
});
