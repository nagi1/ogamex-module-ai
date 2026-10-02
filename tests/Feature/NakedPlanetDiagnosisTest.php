<?php

use Modules\AI\Domain\Decision\DefenseNeedEvaluator;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Tests\Support\Situation;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Planet;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// QUAL-003 at the planner level: the bare sibling's own wall prerequisites are the account's first
// planned step, ahead of the walled homeworld's economy. The pass is what makes the sibling reachable
// in one login instead of waiting behind a stock the account is busy spending elsewhere.
test('the bare sibling takes its wall prerequisites before the homeworld economy', function (): void {
    Situation::of($this)
        ->resources(50_000_000, 50_000_000, 50_000_000)
        ->level('robot_factory', 2)
        ->level('shipyard', 4)
        ->ships('small_cargo', 10)
        ->ships('light_laser', 60)
        ->colony()
        ->stockEveryPlanet(50_000_000, 50_000_000, 50_000_000);

    $naked = nakedSiblingPlanetIds($this->currentUserId);
    $first = app(QueueableBuildingPlanner::class)->steps($this->currentUserId)[0] ?? null;

    expect($naked)->not->toBeEmpty()
        ->and($first)->not->toBeNull()
        ->and($first->planetId)->toBeIn($naked)
        ->and($first->planetId)->not->toBe($this->currentPlanetId)
        ->and($first->reason)->toContain('wall-prerequisite');
});

/** @return list<int> the account's planets that stand no defence at all, built or already ordered */
function nakedSiblingPlanetIds(int $playerId): array
{
    return Planet::query()->where('user_id', $playerId)->where('planet_type', 1)->pluck('id')
        ->map(static fn ($id): int => (int) $id)
        ->filter(static function (int $id): bool {
            $planet = app(PlanetServiceFactory::class)->make($id, true);

            return $planet !== null && app(DefenseNeedEvaluator::class)->standingUnits($planet) === 0;
        })->values()->all();
}
