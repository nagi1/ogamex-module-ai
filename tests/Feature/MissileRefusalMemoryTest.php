<?php

use Modules\AI\Domain\Decision\QueueableMissile;
use Modules\AI\Domain\Decision\QueueableMissilePlanner;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// STUCK-DispatchFleet: a volley the host refused is not a mission, so nothing the planners read
// remembered it and the same silo aimed at the same wall on the next login, where the host refused the
// same dispatch again. A refused dispatch cools the body it left from and the target it blamed, for as
// long as any other refusal does (resources/behavior/dispatch-refusals.yaml), so the account waits
// instead of repeating the order the game has already answered.

/** A planet that can fire interplanetary missiles at a probed wall in its range: the refusal's situation. */
function missileRefusalSituation(object $test): Situation
{
    return Situation::of($test)
        ->research('impulse_drive', 3)
        ->ships('interplanetary_missile', 2)
        ->inactiveNeighbour(metal: 300_000)
        ->neighbourUnits('rocket_launcher', 40)
        ->spyReport();
}

/** The coordinate the planted situation probed, so a story can blame it or leave it alone. */
function missileRefusalTarget(Situation $situation): string
{
    $at = $situation->neighbour()?->getPlanetCoordinates() ?? throw new \LogicException('the situation reports on a neighbour');

    return "{$at->galaxy}:{$at->system}:{$at->position}";
}

test('a planet in range of a probed wall offers its volley', function (): void {
    $situation = missileRefusalSituation($this);
    $plan = app(QueueableMissilePlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableMissile::class)
        ->and($plan->originPlanetId)->toBe($this->currentPlanetId)
        ->and("{$plan->targetGalaxy}:{$plan->targetSystem}:{$plan->targetPosition}")->toBe(missileRefusalTarget($situation));
});

test('a target the gate just refused is not offered again', function (): void {
    $situation = missileRefusalSituation($this);
    [$galaxy, $system, $position] = explode(':', missileRefusalTarget($situation));

    $situation->refusedDispatch(galaxy: (int) $galaxy, system: (int) $system, position: (int) $position, reason: 'nothing_queueable');

    expect(app(QueueableMissilePlanner::class)->plan($this->currentUserId))->toBeNull('expected no volley at a target the gate just refused, but ' . $situation->account());
});

test('a planet the gate just refused a dispatch from fires no volley again', function (): void {
    $situation = missileRefusalSituation($this);
    $situation->refusedDispatch(planetId: $this->currentPlanetId, reason: 'no_disposable_fleet');

    expect(app(QueueableMissilePlanner::class)->plan($this->currentUserId))->toBeNull('expected no volley from a refused body, but ' . $situation->account());
});

// The bound: cooling is per body and per target, so a refusal somewhere else in the universe leaves
// this volley alone — the account fires what it can rather than standing down for six hours.
test('a refusal that blamed another coordinate does not cool this target', function (): void {
    $situation = missileRefusalSituation($this);
    $situation->refusedDispatch(galaxy: 1, system: 400, position: 5, reason: 'nothing_queueable');

    expect(app(QueueableMissilePlanner::class)->plan($this->currentUserId))->toBeInstanceOf(QueueableMissile::class);
});

// The raids spared a mate and the volleys did not: mates who fired at each other walked out of the club (9427).
test('a probed wall in range is spared when its owner is an alliance mate', function (): void {
    $situation = missileRefusalSituation($this);
    $alliance = app(OGame\Services\AllianceService::class)->createAlliance($this->currentUserId, 'MATE', 'Mates');
    OGame\Models\User::query()->whereKey($situation->neighbour()?->getPlayer()?->getId())->update(['alliance_id' => $alliance->id]);

    expect(app(QueueableMissilePlanner::class)->plan($this->currentUserId))->toBeNull('a volley was offered at a mate, ' . $situation->account());
});

// A player thins the wall the fleet is about to hit, and at war that is the enemy's, even when a bigger wall stands beside it.
test('a volley goes to the enemy wall over a bigger wall that is not an enemy', function (): void {
    $situation = missileRefusalSituation($this);
    $enemyId = $situation->neighbour()?->getPlayer()?->getId();
    $enemyReport = OGame\Models\EspionageReport::query()->where('planet_user_id', $enemyId)->latest('id')->firstOrFail();
    $biggerWall = $enemyReport->replicate();
    $biggerWall->planet_user_id = OGame\Models\User::factory()->create()->id;
    $biggerWall->planet_position = $enemyReport->planet_position === 15 ? 14 : (int) $enemyReport->planet_position + 1;
    $biggerWall->defense = ['rocket_launcher' => 400];
    $biggerWall->save();
    app(OGame\Services\MessageService::class)->sendEspionageReportMessageToPlayer(app(OGame\Factories\PlayerServiceFactory::class)->make($this->currentUserId, true), $biggerWall->id);
    $chosen = fn (): string => ($plan = app(QueueableMissilePlanner::class)->plan($this->currentUserId)) === null ? '' : "{$plan->targetGalaxy}:{$plan->targetSystem}:{$plan->targetPosition}";

    expect($chosen())->toBe("{$biggerWall->planet_galaxy}:{$biggerWall->planet_system}:{$biggerWall->planet_position}");

    Modules\AI\Models\AiRelationship::unguarded(fn () => Modules\AI\Models\AiRelationship::create([
        'player_id' => $this->currentUserId, 'other_player_id' => $enemyId, 'threat' => '0.9000', 'trust' => '-0.5000',
        'affinity' => '0.0000', 'revision' => 0, 'last_interaction_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]));

    expect($chosen())->toBe(missileRefusalTarget($situation));
});
