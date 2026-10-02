<?php

use Modules\AI\Domain\Decision\QueueableRaid;
use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\EspionageReport;
use OGame\Models\Planet;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// ATK-001's ladder at the rung a colony opens: the cohort lives in a universe where every
// neighbour plays, so a Mid-phase account farms a working neighbour whose stock pays for the trip,
// while the opening (one planet) still farms inactives only. The ladder is asserted on the planner
// rather than on a session, because a session's seeded no-op draw may spend any single login on
// doing nothing (FS-018); the session that flies the raid is RaidFromReportSituationTest.
test('a colonised account plans a raid on a profitable active neighbour', function (): void {
    Situation::of($this)
        ->archetype(AiArchetype::Fleeter)
        ->research('combustion_drive', 6)
        ->resources(200_000, 200_000, 500_000)
        ->ships('large_cargo', 20)
        ->ships('light_fighter', 10)
        ->colony()
        ->inactiveNeighbour(daysQuiet: 1, metal: 400_000, crystal: 200_000)
        ->spyReport();

    $reportId = (int) EspionageReport::query()->orderByDesc('id')->value('id');

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeInstanceOf(QueueableRaid::class);
});

test('an opening account with one planet refuses the same active neighbour', function (): void {
    Planet::query()->where('user_id', $this->currentUserId)->where('id', '!=', $this->currentPlanetId)->update(['destroyed' => 1]);

    Situation::of($this)
        ->archetype(AiArchetype::Fleeter)
        ->research('combustion_drive', 6)
        ->resources(200_000, 200_000, 500_000)
        ->ships('large_cargo', 20)
        ->ships('light_fighter', 10)
        ->inactiveNeighbour(daysQuiet: 1, metal: 400_000, crystal: 200_000)
        ->spyReport();

    $reportId = (int) EspionageReport::query()->orderByDesc('id')->value('id');

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeNull();
});
