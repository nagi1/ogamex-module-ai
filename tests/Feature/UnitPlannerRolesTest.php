<?php

use Modules\AI\Domain\Decision\QueueableUnit;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Domain\Decision\SaveFailurePolicy;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\MessageService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

test('it queues the best cargo ship for a fresh account', function (): void {
    unitProfile($this->currentUserId);
    $this->planetAddResources(unitPlenty());
    $this->planetSetObjectLevel('shipyard', 2);
    $this->playerSetResearchLevel('combustion_drive', 2);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toBe('role:cargo:small_cargo')
        ->and($plan->planetId)->toBe($this->currentPlanetId)
        ->and($plan->amount)->toBe(1);
});

test('it plans nothing for an unmanaged or missing account', function (): void {
    $this->planetAddResources(unitPlenty());
    $this->planetSetObjectLevel('shipyard', 2);

    expect(app(QueueableUnitPlanner::class)->plan($this->currentUserId))->toBeNull();

    unitProfile($this->currentUserId)->update(['enabled' => false]);
    expect(app(QueueableUnitPlanner::class)->plan($this->currentUserId))->toBeNull();

    $orphaned = $this->currentUserId + 1_000_000;
    unitProfile($orphaned);
    expect(app(QueueableUnitPlanner::class)->plan($orphaned))->toBeNull();
});

test('it queues a colony ship once a fleet and room exist', function (): void {
    unitProfile($this->currentUserId);
    $this->planetAddResources(unitPlenty());
    $this->planetSetObjectLevel('shipyard', 4);
    $this->playerSetResearchLevel('impulse_drive', 3);
    $this->playerSetResearchLevel('astrophysics', 4);
    $this->planetAddUnit('small_cargo', 1);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toBe('role:colony');
});

test('it queues a probe once a fleet exists and expansion is underway', function (): void {
    unitProfile($this->currentUserId);
    $this->planetAddResources(unitPlenty());
    $this->planetSetObjectLevel('shipyard', 4);
    $this->playerSetResearchLevel('combustion_drive', 3);
    $this->playerSetResearchLevel('impulse_drive', 3);
    $this->playerSetResearchLevel('espionage_technology', 2);
    $this->playerSetResearchLevel('astrophysics', 1);
    $this->planetAddUnit('small_cargo', 1);
    $this->planetAddUnit('colony_ship', 1);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toBe('role:probe');
});

test('it queues defence when the host says the account is under attack', function (): void {
    unitProfile($this->currentUserId);
    $this->planetAddResources(unitPlenty());
    $this->planetSetObjectLevel('shipyard', 2);
    $this->planetAddUnit('small_cargo', 1);
    unitInboundHostileFleet($this->currentPlanetId);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toBe('role:defense:rocket_launcher');
});

test('it queues a combat escort for a fresh defended target', function (): void {
    unitProfile($this->currentUserId);
    $this->planetAddResources(unitPlenty());
    $this->planetSetObjectLevel('shipyard', 1);
    $this->playerSetResearchLevel('combustion_drive', 1);
    $this->planetAddUnit('small_cargo', 1);
    $this->planetAddUnit('colony_ship', 1);
    $this->planetAddUnit('espionage_probe', 1);
    unitDefendedTargetReport($this->currentUserId);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toBe('role:escort:light_fighter');
});

test('it does not queue an escort the account already matches', function (): void {
    unitProfile($this->currentUserId);
    $this->planetAddResources(unitPlenty());
    $this->planetSetObjectLevel('shipyard', 1);
    $this->playerSetResearchLevel('combustion_drive', 1);
    $this->planetAddUnit('small_cargo', 1);
    $this->planetAddUnit('light_fighter', 1);
    $this->planetAddUnit('colony_ship', 1);
    $this->planetAddUnit('espionage_probe', 1);
    unitDefendedTargetReport($this->currentUserId);

    expect(app(QueueableUnitPlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('the save failure policy skips deterministically and names the gamble', function (): void {
    $policy = app(SaveFailurePolicy::class);

    expect($policy->shouldSkip(null, 1))->toBeNull()
        ->and($policy->shouldSkip(1, 1))->toBeIn([null, 'overnight_gamble']);

    $skipSeed = null;
    for ($seed = 0; $seed < 500; $seed++) {
        if ($policy->shouldSkip($seed, 7) !== null) {
            $skipSeed = $seed;
            break;
        }
    }

    expect($skipSeed)->not->toBeNull()
        ->and($policy->shouldSkip($skipSeed, 7))->toBe('overnight_gamble');
});

function unitProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 8_000 + $playerId,
        'enabled' => true,
    ]);
}

function unitPlenty(): Resources
{
    return new Resources(1_000_000, 1_000_000, 1_000_000);
}

/** A foreign fleet attacking the account's planet, so the host reports it under attack. */
function unitInboundHostileFleet(int $targetPlanetId): FleetMission
{
    $foreign = test()->createForeignPlanet();
    $foreignPlayer = $foreign->getPlayer();
    expect($foreignPlayer)->not->toBeNull();

    $mission = new FleetMission();
    $mission->user_id = $foreignPlayer->getId();
    $mission->planet_id_from = $foreign->getPlanetId();
    $mission->planet_id_to = $targetPlanetId;
    $mission->mission_type = 1;
    $mission->time_departure = now()->subMinute()->timestamp;
    $mission->time_arrival = now()->addHour()->timestamp;
    $mission->time_arrival_ms = 0;
    $mission->processed = 0;
    $mission->canceled = 0;
    $mission->save();

    return $mission;
}

/** A fresh espionage report on a defended target, delivered through the account's messages. */
function unitDefendedTargetReport(int $playerId): void
{
    $foreign = test()->createForeignPlanet();
    $foreignPlayer = $foreign->getPlayer();
    expect($foreignPlayer)->not->toBeNull();

    $report = new EspionageReport();
    $report->planet_galaxy = $foreign->getPlanetCoordinates()->galaxy;
    $report->planet_system = $foreign->getPlanetCoordinates()->system;
    $report->planet_position = $foreign->getPlanetCoordinates()->position;
    $report->planet_type = (int) $foreign->getPlanetType()->value;
    $report->planet_user_id = $foreignPlayer->getId();
    $report->resources = ['metal' => 1000, 'crystal' => 500, 'deuterium' => 100, 'energy' => 1000];
    $report->debris = [];
    $report->buildings = [];
    $report->research = [];
    $report->ships = [];
    $report->defense = ['rocket_launcher' => 10];
    $report->player_info = ['player_name' => 'Defender', 'player_status' => 'inactive'];
    $report->save();

    $player = app(\OGame\Factories\PlayerServiceFactory::class)->make($playerId, true);
    app(MessageService::class)->sendEspionageReportMessageToPlayer($player, $report->id);
}
