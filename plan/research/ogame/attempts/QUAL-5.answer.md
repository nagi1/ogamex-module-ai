### EDIT: app/Domain/Decision/WaveFarmPlanner.php
<<<<<<< SEARCH
namespace Modules\AI\Domain\Decision;
=======
namespace Modules\AI\Domain\Decision;

use Modules\AI\Support\SpaceDockRepair;
>>>>>>> REPLACE

<<<<<<< SEARCH
        $frontlineUnits = $fleetUnits + intdiv($defenseUnits * (100 - self::DEFENSE_SURVIVAL_PERCENT), 100);
        $followUpUnits = intdiv($frontlineUnits * self::FOLLOW_UP_SHARE, self::FRONTLINE_SHARE);
=======
        $frontlineUnits = $fleetUnits + intdiv($defenseUnits * (100 - self::DEFENSE_SURVIVAL_PERCENT), 100);
        // The defender's space dock puts a share of its fleet back between the two waves,
        // so the follow-up is sized against the survivors plus what the dock restores.
        $followUpUnits = intdiv($frontlineUnits * self::FOLLOW_UP_SHARE, self::FRONTLINE_SHARE)
            + SpaceDockRepair::repairedUnits($fleetUnits);
>>>>>>> REPLACE

### FILE: tests/Feature/WaveRepairShareTest.php
```php
<?php

use Modules\AI\Domain\Decision\WaveFarmPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// The space dock hands the defender a share of its fleet back between the frontline wave and
// the follow-up, so the follow-up is sized against the survivors plus that share. The story
// ends by flying the real session path that reaches the wave planner.

test('the follow-up wave covers the fleet the space dock restores between waves', function (): void {
    [$frontline, $followUp] = app(WaveFarmPlanner::class)->plan(10, 0, 0, 0, 0);

    // Ten fleet units are a frontline of ten, a 7:5 follow-up of seven, and one repaired unit.
    expect($frontline['light_fighters'] + $frontline['heavy_fighters'])->toBe(10)
        ->and($followUp['light_fighters'] + $followUp['heavy_fighters'])->toBe(8);
});

test('a fleet below ten units repairs nothing and adds nothing to the follow-up', function (): void {
    $followUp = app(WaveFarmPlanner::class)->plan(9, 0, 0, 0, 0)[1];

    expect($followUp['light_fighters'] + $followUp['heavy_fighters'])->toBe(6);
});

test('a defender with no fleet holds no wave and repairs nothing', function (): void {
    [$frontline, $followUp] = app(WaveFarmPlanner::class)->plan(0, 0, 0, 0, 0);

    expect($frontline['light_fighters'] + $frontline['heavy_fighters'])->toBe(0)
        ->and($followUp['light_fighters'] + $followUp['heavy_fighters'])->toBe(0);
});

test('an account with a report on an inactive neighbour flies the attack', function (): void {
    Situation::of($this)
        ->archetype(AiArchetype::Fleeter)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('light_fighter', 200)
        ->ships('small_cargo', 100)
        ->inactiveNeighbour()
        ->spyReport()
        ->sessions(2)
        ->expectMission('Attack');
});
```