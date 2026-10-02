<?php

use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\FleetMission;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// QUAL-5: the daily attack budget the raid planner spends. A farm is worth a fresh report and cargo, but
// only up to RaidPlanner::BASHING_LIMIT arrivals inside the day; the one stated cap decides, and the
// class that lost its only caller with AttackPlanner (DailyAttackBudget) is what counts the budget.

/** The raid story every case shares: an undefended inactive, a report on it, and cargo plus a kill ship. */
function dailyAttackBudgetRaidSeries(object $test): Situation
{
    return Situation::of($test)
        ->archetype(AiArchetype::Fleeter)
        ->research('combustion_drive', 6)
        ->ships('large_cargo', 20)
        ->ships('light_fighter', 10)
        ->resources(200_000, 200_000, 500_000)
        ->inactiveNeighbour(daysQuiet: 10, metal: 400_000, crystal: 200_000)
        ->spyReport();
}

/** The attacks the account already landed on the neighbour, by the host's own mission rows. */
function dailyAttackBudgetSpend(int $playerId, int $planetId, int $targetPlanetId, int $count): void
{
    for ($attack = 0; $attack < $count; $attack++) {
        $mission = new FleetMission();
        $mission->user_id = $playerId;
        $mission->planet_id_from = $planetId;
        $mission->planet_id_to = $targetPlanetId;
        $mission->mission_type = 1;
        $mission->time_departure = now()->subHours(8)->timestamp;
        $mission->time_arrival = now()->subHours(7)->timestamp;
        $mission->time_arrival_ms = 0;
        $mission->processed = 1;
        $mission->canceled = 0;
        $mission->save();
    }
}

test('a farm already hit a full day of times is not raided again', function (): void {
    $situation = dailyAttackBudgetRaidSeries($this);
    dailyAttackBudgetSpend($this->currentUserId, $this->currentPlanetId, $situation->neighbour()->getPlanetId(), RaidPlanner::BASHING_LIMIT);

    $situation->session()->expectNoWork(AiWorkKind::Raid);
});

test('a farm one attack short of the cap is raided', function (): void {
    $situation = dailyAttackBudgetRaidSeries($this);
    dailyAttackBudgetSpend($this->currentUserId, $this->currentPlanetId, $situation->neighbour()->getPlanetId(), RaidPlanner::BASHING_LIMIT - 1);

    $situation->session()->expectWork(AiWorkKind::Raid)->expectMission('Attack');
});

test('an account that has not hit the farm today raids it', function (): void {
    dailyAttackBudgetRaidSeries($this)->session()->expectWork(AiWorkKind::Raid)->expectMission('Attack');
});
