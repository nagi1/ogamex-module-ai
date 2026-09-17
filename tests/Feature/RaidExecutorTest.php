<?php

use Modules\AI\Actions\QueueAiRaidAction;
use Modules\AI\Contracts\QueueAiRaid;
use Modules\AI\Domain\Decision\QueueableRaid;
use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Infrastructure\Battle\NativeRaidEstimator;
use Modules\AI\Models\AiProfile;
use OGame\GameMissions\AttackMission;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\MessageService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiRaid::class, QueueAiRaidAction::class);
});

test('the estimator reports no samples when there is no fleet', function (): void {
    raidProfile($this->currentUserId);
    $foreign = $this->createForeignPlanet();

    $noFleet = app(NativeRaidEstimator::class)->estimate($this->currentUserId, $this->currentPlanetId, $foreign->getPlanetId(), 1);

    expect($noFleet->samples)->toBe(0)
        ->and($noFleet->p20NetProfit)->toBe(0.0);
});

test('the raid planner plans nothing for an unmanaged account, a missing report or no fleet', function (): void {
    $profile = raidProfile($this->currentUserId);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, 999_999))->toBeNull();

    $reportId = raidReport($this->currentUserId, 1, 1, 4, 1);
    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeNull();

    $profile->update(['enabled' => false]);
    $this->planetAddUnit('small_cargo', 1);
    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeNull();
});

test('the raid planner refuses a target it already bashed six times', function (): void {
    raidProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 1);
    $foreign = $this->createForeignPlanet();
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $reportId = raidReport($this->currentUserId, $foreign->getPlanetCoordinates()->galaxy, $foreign->getPlanetCoordinates()->system, $foreign->getPlanetCoordinates()->position, (int) $foreign->getPlanetType()->value);

    for ($i = 0; $i < 6; $i++) {
        raidAttackMission($this->currentUserId, $foreign->getPlanetId());
    }

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeNull();
});

test('the raid planner schedules a raid when the sampled profit clears', function (): void {
    raidProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 1);
    $foreign = $this->createForeignPlanet();
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $reportId = raidReport($this->currentUserId, $foreign->getPlanetCoordinates()->galaxy, $foreign->getPlanetCoordinates()->system, $foreign->getPlanetCoordinates()->position, (int) $foreign->getPlanetType()->value);

    $plan = app(RaidPlanner::class)->plan($this->currentUserId, $reportId);

    expect($plan)->toBeInstanceOf(QueueableRaid::class)
        ->and($plan->originPlanetId)->toBe($this->currentPlanetId)
        ->and($plan->targetGalaxy)->toBe($foreign->getPlanetCoordinates()->galaxy);
});

test('the raid action launches the host attack mission', function (): void {
    raidProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 1);
    $foreign = $this->createForeignPlanet();
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $reportId = raidReport($this->currentUserId, $foreign->getPlanetCoordinates()->galaxy, $foreign->getPlanetCoordinates()->system, $foreign->getPlanetCoordinates()->position, (int) $foreign->getPlanetType()->value);

    $plan = app(RaidPlanner::class)->plan($this->currentUserId, $reportId);
    expect($plan)->not->toBeNull();

    // A raid flies at a quiet target; the just-created fixture is touched now,
    // so age its activity star before dispatch (the active-target refusal is
    // its own test in RaidDepthTest).
    Planet::query()->whereKey($foreign->getPlanetId())->update(['time_last_update' => now()->subMinutes(30)->getTimestamp()]);

    $result = app(QueueAiRaid::class)->handle($this->currentUserId, $plan->originPlanetId, $plan->targetGalaxy, $plan->targetSystem, $plan->targetPosition, $plan->targetType);

    expect($result->successful)->toBeTrue($result->reason)
        ->and($result->queueId)->not->toBeNull();
});

test('the raid action refuses a planet it does not own', function (): void {
    raidProfile($this->currentUserId);
    $this->planetAddUnit('small_cargo', 1);
    $foreign = $this->createForeignPlanet();

    $result = app(QueueAiRaid::class)->handle($this->currentUserId, $foreign->getPlanetId(), 1, 1, 4, 1);

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe(\Modules\AI\Enums\AiQueueActionReason::PlanetNotOwned->value);
});

function raidProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 11_000 + $playerId,
        'enabled' => true,
    ]);
}

function raidReport(int $playerId, int $galaxy, int $system, int $position, int $planetType): int
{
    $report = new EspionageReport();
    $report->planet_galaxy = $galaxy;
    $report->planet_system = $system;
    $report->planet_position = $position;
    $report->planet_type = $planetType;
    $report->planet_user_id = null;
    $report->resources = ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000, 'energy' => 0];
    $report->debris = [];
    $report->buildings = [];
    $report->research = [];
    $report->ships = [];
    $report->defense = [];
    $report->player_info = ['player_name' => 'Target', 'player_status' => 'inactive'];
    $report->save();

    $player = app(\OGame\Factories\PlayerServiceFactory::class)->make($playerId, true);
    app(MessageService::class)->sendEspionageReportMessageToPlayer($player, $report->id);

    return $report->id;
}

function raidAttackMission(int $playerId, int $targetPlanetId): void
{
    $mission = new FleetMission();
    $mission->user_id = $playerId;
    $mission->planet_id_from = null;
    $mission->planet_id_to = $targetPlanetId;
    $mission->mission_type = AttackMission::getTypeId();
    $mission->time_departure = now()->subMinute()->timestamp;
    $mission->time_arrival = now()->timestamp;
    $mission->time_arrival_ms = 0;
    $mission->processed = 0;
    $mission->canceled = 0;
    $mission->save();
}
