<?php

use Modules\AI\Actions\QueueAiRaidAction;
use Modules\AI\Contracts\QueueAiRaid;
use Modules\AI\Domain\Decision\Enemies;
use Modules\AI\Domain\Decision\QueueableRaid;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\MoonDestructionMission;
use OGame\Models\Enums\PlanetType;
use OGame\Models\EspionageReport;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\AllianceService;
use OGame\Services\MessageService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// Late empires raided each other into threat 1.0 all round, yet scouted the nearest farm and never fought a rival's fleet or
// moon (9372: 101 of 104 battles one-sided, no moon destruction). The war is the existing grudge, pointed.

function warProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 31_000 + $playerId,
        'enabled' => true,
    ]);
}

function warGrudge(int $holderId, int $enemyId): void
{
    AiRelationship::unguarded(fn () => AiRelationship::create([
        'player_id' => $holderId,
        'other_player_id' => $enemyId,
        'threat' => '1.0000',
        'trust' => '-1.0000',
        'revision' => 0,
    ]));
}

function warQuiet(int $planetId): void
{
    Planet::query()->whereKey($planetId)->update(['time_last_update' => now()->subMinutes(30)->getTimestamp()]);
}

function warReport(int $playerId, Planet $target, array $ships, int $type = 1): int
{
    $report = new EspionageReport();
    $report->planet_galaxy = $target->galaxy;
    $report->planet_system = $target->system;
    $report->planet_position = $target->planet;
    $report->planet_type = $type;
    $report->planet_user_id = $target->user_id;
    $report->resources = ['metal' => 1000, 'crystal' => 0, 'deuterium' => 0, 'energy' => 0];
    $report->debris = [];
    $report->buildings = [];
    $report->research = [];
    $report->ships = $ships;
    $report->defense = [];
    $report->player_info = ['player_name' => 'Target', 'player_status' => 'active'];
    $report->save();
    app(MessageService::class)->sendEspionageReportMessageToPlayer(app(PlayerServiceFactory::class)->make($playerId, true), $report->id);

    return $report->id;
}

test('whoever raided an ally is the enemy of the whole alliance, and no member is', function (): void {
    $ally = User::factory()->create();
    $raider = User::factory()->create();
    $alliance = app(AllianceService::class)->createAlliance($this->currentUserId, 'WAR', 'War');
    User::query()->whereKey($ally->id)->update(['alliance_id' => $alliance->id]);
    warGrudge($ally->id, $raider->id);
    warGrudge($ally->id, $this->currentUserId);

    $enemies = app(Enemies::class)->of($this->currentUserId);

    expect($enemies)->toHaveKey($raider->id)
        ->and($enemies)->not->toHaveKey($this->currentUserId)
        ->and($enemies)->not->toHaveKey($ally->id);
});

test('an account with a war fleet scouts its enemy before the nearer farm', function (): void {
    warProfile($this->currentUserId);
    $this->planetAddUnit('espionage_probe', 1);
    $this->planetAddUnit('cruiser', 10);
    $this->planetAddResources(new Resources(0, 0, 1_000_000));

    $farm = $this->createForeignPlanet();
    warQuiet($farm->getPlanetId());
    $enemy = User::factory()->create();
    Planet::factory()->create(['user_id' => $enemy->id, 'galaxy' => 3, 'system' => 10, 'planet' => 15]);
    warGrudge($this->currentUserId, $enemy->id);

    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId)?->targetGalaxy)->toBe(3);
});

test('a deathstar holder scouts the enemy moon its planet report never covered', function (): void {
    warProfile($this->currentUserId);
    $this->planetAddUnit('espionage_probe', 1);
    $this->planetAddUnit('deathstar', 1);
    $this->planetAddResources(new Resources(0, 0, 1_000_000));

    $enemyPlanet = $this->createForeignPlanet();
    warGrudge($this->currentUserId, $enemyPlanet->getPlayer()->getId());
    app(PlanetServiceFactory::class)->createMoonForPlanet($enemyPlanet, 2_000_000, 20);
    warReport($this->currentUserId, Planet::query()->findOrFail($enemyPlanet->getPlanetId()), []);

    $plan = app(QueueableSpyPlanner::class)->plan($this->currentUserId);

    expect($plan?->targetPosition)->toBe($enemyPlanet->getPlanetCoordinates()->position)
        ->and($plan?->targetType)->toBe(PlanetType::Moon->value);
});

test('a deathstar holder scouts the enemy moon before the planet beneath it', function (): void {
    warProfile($this->currentUserId);
    $this->planetAddUnit('espionage_probe', 1);
    $this->planetAddUnit('deathstar', 1);
    $this->planetAddResources(new Resources(0, 0, 1_000_000));

    $enemyPlanet = $this->createForeignPlanet();
    warGrudge($this->currentUserId, $enemyPlanet->getPlayer()->getId());
    app(PlanetServiceFactory::class)->createMoonForPlanet($enemyPlanet, 2_000_000, 20);

    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId)?->targetType)->toBe(PlanetType::Moon->value);
});

dataset('war standing', [
    'a neutral fleet that leaves only wreckage is not worth the losses' => [false, false],
    'the enemy fleet is fought for its destruction' => [true, true],
]);

test('a costly fight with no loot', function (bool $enemy, bool $fought): void {
    warProfile($this->currentUserId);
    $this->playerSetResearchLevel('astrophysics', 23);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 5);
    $this->planetAddUnit('cruiser', 60);
    $target = $this->createForeignPlanet();
    User::query()->whereKey($target->getPlayer()->getId())->update(['time' => (string) now()->timestamp]);
    $target->addUnit('light_fighter', 150);
    if ($enemy) {
        warGrudge($this->currentUserId, $target->getPlayer()->getId());
    }

    $reportId = warReport($this->currentUserId, Planet::query()->findOrFail($target->getPlanetId()), ['light_fighter' => 150]);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId) instanceof QueueableRaid)->toBe($fought);
})->with('war standing');

beforeEach(function (): void {
    app()->bind(QueueAiRaid::class, QueueAiRaidAction::class);
});

test('a deathstar holder flies a strike on an enemy moon that carries no loot', function (bool $enemy, bool $struck): void {
    warProfile($this->currentUserId);
    $this->playerSetResearchLevel('astrophysics', 23);
    $this->planetAddResources(new Resources(0, 0, 5_000_000));
    $this->planetAddUnit('deathstar', 2);
    $this->planetAddUnit('cruiser', 40);
    $enemyPlanet = $this->createForeignPlanet();
    User::query()->whereKey($enemyPlanet->getPlayer()->getId())->update(['time' => (string) now()->timestamp]);
    app(PlanetServiceFactory::class)->createMoonForPlanet($enemyPlanet, 2_000_000, 20);
    $moon = Planet::query()->where('user_id', $enemyPlanet->getPlayer()->getId())->where('planet_type', PlanetType::Moon->value)->firstOrFail();
    if ($enemy) {
        warGrudge($this->currentUserId, $enemyPlanet->getPlayer()->getId());
    }

    $plan = app(RaidPlanner::class)->plan($this->currentUserId, warReport($this->currentUserId, $moon, [], PlanetType::Moon->value));

    expect($plan?->missionType === MoonDestructionMission::getTypeId())->toBe($struck);
})->with([
    'an enemy moon: the gate and phalanx it hosts are the point' => [true, true],
    'a neutral moon is not worth the trip' => [false, false],
]);

test('a moon strike is dispatched at an online defender, a raid is not', function (bool $strike, bool $flies): void {
    warProfile($this->currentUserId);
    $this->playerSetResearchLevel('astrophysics', 23);
    $this->planetAddResources(new Resources(0, 0, 5_000_000));
    $this->planetAddUnit('deathstar', 2);
    $this->planetAddUnit('cruiser', 40);
    $enemyPlanet = $this->createForeignPlanet();
    app(PlanetServiceFactory::class)->createMoonForPlanet($enemyPlanet, 2_000_000, 20);
    $moon = Planet::query()->where('user_id', $enemyPlanet->getPlayer()->getId())->where('planet_type', PlanetType::Moon->value)->firstOrFail();
    warGrudge($this->currentUserId, $enemyPlanet->getPlayer()->getId());
    $plan = app(RaidPlanner::class)->plan($this->currentUserId, warReport($this->currentUserId, $moon, [], PlanetType::Moon->value));
    expect($plan)->not->toBeNull();
    // The defender touches the moon between the plan and the launch.
    Planet::query()->whereKey($moon->id)->update(['time_last_update' => now()->getTimestamp()]);

    $result = app(QueueAiRaid::class)->handle(
        $this->currentUserId,
        $plan->originPlanetId,
        $plan->targetGalaxy,
        $plan->targetSystem,
        $plan->targetPosition,
        $plan->targetType,
        $plan->launchUnits,
        $strike ? $plan->missionType : AttackMission::getTypeId(),
    );

    expect($result->successful)->toBe($flies, $result->reason);
})->with([
    'the moon cannot be saved by logging in' => [true, true],
    'an attack meets a saved fleet and spent stock' => [false, false],
]);
