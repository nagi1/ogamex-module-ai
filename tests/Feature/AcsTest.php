<?php

use Modules\AI\Actions\QueueAiRaidAction;
use Modules\AI\Domain\Decision\AllianceUnionTargets;
use Modules\AI\Models\AiRelationship;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\AllianceService;
use OGame\Services\MessageService;
use OGame\Services\PlanetService;
use OGame\Factories\PlayerServiceFactory;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// No world ever flew an ACS attack: every empire sat alone in its own alliance and attacked alone (9372).

function acsGrudge(int $holderId, int $enemyId): void
{
    AiRelationship::unguarded(fn () => AiRelationship::create([
        'player_id' => $holderId, 'other_player_id' => $enemyId, 'threat' => '1.0000', 'trust' => '-1.0000', 'revision' => 0,
    ]));
}

function acsArm(PlanetService $planet): void
{
    $planet->addResources(new Resources(0, 0, 1_000_000));
    $planet->addUnit('cruiser', 20);
}

function acsAttack(int $playerId, int $originPlanetId, PlanetService $target): FleetMission
{
    $coordinates = $target->getPlanetCoordinates();
    $result = app(QueueAiRaidAction::class)->handle($playerId, $originPlanetId, $coordinates->galaxy, $coordinates->system, $coordinates->position, 1, ['cruiser' => 10]);

    return FleetMission::query()->where('user_id', $playerId)->orderByDesc('id')->firstOrFail();
}

/** @return array{0: PlanetService, 1: PlanetService} the mate's planet and the enemy's, the account in an alliance with the mate */
function acsWar(int $userId, Closure $foreignPlanet, bool $enemy = true): array
{
    $alliance = app(AllianceService::class)->createAlliance($userId, 'ACS', 'Acs');
    $mate = $foreignPlanet();
    User::query()->whereKey($mate->getPlayer()->getId())->update(['alliance_id' => $alliance->id]);
    $target = $foreignPlanet();
    $target->addUnit('rocket_launcher', 1);
    if ($enemy) {
        acsGrudge($userId, $target->getPlayer()->getId());
        acsGrudge($mate->getPlayer()->getId(), $target->getPlayer()->getId());
    }
    acsArm($mate);

    return [$mate, $target];
}

dataset('standing', [
    'an alliance enemy is attacked as an ACS for the mates' => [true, 2],
    'a neutral target is attacked alone' => [false, 1],
]);

test('an attack', function (bool $enemy, int $missionType): void {
    $this->planetAddResources(new Resources(0, 0, 1_000_000));
    $this->planetAddUnit('cruiser', 20);
    [, $target] = acsWar($this->currentUserId, fn () => $this->createForeignPlanet(), $enemy);

    expect(acsAttack($this->currentUserId, $this->currentPlanetId, $target)->mission_type)->toBe($missionType);
})->with('standing');

test('a mate attacking the target of an open ACS joins it', function (): void {
    $this->planetAddResources(new Resources(0, 0, 1_000_000));
    $this->planetAddUnit('cruiser', 20);
    [$mate, $target] = acsWar($this->currentUserId, fn () => $this->createForeignPlanet());
    $opened = acsAttack($this->currentUserId, $this->currentPlanetId, $target);

    $joined = acsAttack($mate->getPlayer()->getId(), $mate->getPlanetId(), $target);

    expect($opened->union_id)->not->toBeNull()
        ->and($joined->union_id)->toBe($opened->union_id)
        ->and($joined->mission_type)->toBe(2);
});

test('a mate is shown the open ACS through the report it flies on', function (): void {
    $this->planetAddResources(new Resources(0, 0, 1_000_000));
    $this->planetAddUnit('cruiser', 20);
    [$mate, $target] = acsWar($this->currentUserId, fn () => $this->createForeignPlanet());
    acsAttack($this->currentUserId, $this->currentPlanetId, $target);
    $coordinates = $target->getPlanetCoordinates();
    $report = new EspionageReport();
    $report->planet_galaxy = $coordinates->galaxy;
    $report->planet_system = $coordinates->system;
    $report->planet_position = $coordinates->position;
    $report->planet_type = 1;
    $report->planet_user_id = $target->getPlayer()->getId();
    $report->resources = ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'energy' => 0];
    $report->debris = [];
    $report->buildings = [];
    $report->research = [];
    $report->ships = [];
    $report->defense = ['rocket_launcher' => 1];
    $report->player_info = ['player_name' => 'Target', 'player_status' => 'active'];
    $report->save();
    app(MessageService::class)->sendEspionageReportMessageToPlayer(app(PlayerServiceFactory::class)->make($this->currentUserId, true), $report->id);

    expect(FleetUnion::query()->count())->toBe(1)
        ->and(app(AllianceUnionTargets::class)->reports($mate->getPlayer()->getId()))->toBe([$report->id])
        ->and(app(AllianceUnionTargets::class)->reports($this->currentUserId))->toBe([]);
});
