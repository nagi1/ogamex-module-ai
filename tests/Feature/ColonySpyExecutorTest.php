<?php

use Modules\AI\Actions\QueueAiColonyAction;
use Modules\AI\Actions\QueueAiSpyAction;
use Modules\AI\Contracts\QueueAiColony;
use Modules\AI\Contracts\QueueAiSpy;
use Modules\AI\Domain\Decision\QueueableColony;
use Modules\AI\Domain\Decision\QueueableColonyPlanner;
use Modules\AI\Domain\Decision\QueueableSpy;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\GameMissions\EspionageMission;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\MessageService;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiColony::class, QueueAiColonyAction::class);
    app()->bind(QueueAiSpy::class, QueueAiSpyAction::class);
});

test('the colony planner picks an empty slot for an account with a colony ship', function (): void {
    colonyProfile($this->currentUserId);
    $this->playerSetResearchLevel('astrophysics', 4);
    $this->planetAddUnit('colony_ship', 1);

    $plan = app(QueueableColonyPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableColony::class)
        ->and($plan->planetId)->toBe($this->currentPlanetId);
});

test('the colony planner plans nothing without a colony ship or an account', function (): void {
    $profile = colonyProfile($this->currentUserId);
    expect(app(QueueableColonyPlanner::class)->plan($this->currentUserId))->toBeNull();

    $profile->update(['enabled' => false]);
    $this->planetAddUnit('colony_ship', 1);
    expect(app(QueueableColonyPlanner::class)->plan($this->currentUserId))->toBeNull();

    $orphaned = $this->currentUserId + 1_000_000;
    colonyProfile($orphaned);
    expect(app(QueueableColonyPlanner::class)->plan($orphaned))->toBeNull();
});

test('the colony planner gives up when every colonisable slot is taken', function (): void {
    colonyProfile($this->currentUserId);
    $this->planetAddUnit('colony_ship', 1);
    app(SettingsService::class)->set('number_of_systems', 1);
    app(SettingsService::class)->set('number_of_galaxies', 1);
    colonyFillSingleSystem();

    expect(app(QueueableColonyPlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('the colony action launches the host colonisation mission', function (): void {
    colonyProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->playerSetResearchLevel('astrophysics', 4);
    $this->planetAddUnit('colony_ship', 1);

    $plan = app(QueueableColonyPlanner::class)->plan($this->currentUserId);
    expect($plan)->not->toBeNull();

    $result = app(QueueAiColony::class)->handle($this->currentUserId, $plan->planetId, $plan->galaxy, $plan->system, $plan->position);

    expect($result->successful)->toBeTrue($result->reason)
        ->and($result->queueId)->not->toBeNull();
});

test('the spy planner picks a legal foreign target', function (): void {
    colonyProfile($this->currentUserId);
    $this->planetAddUnit('espionage_probe', 1);
    $foreign = $this->createForeignPlanet();

    $plan = app(QueueableSpyPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableSpy::class)
        ->and($plan->targetGalaxy)->toBe($foreign->getPlanetCoordinates()->galaxy)
        ->and($plan->targetPosition)->toBe($foreign->getPlanetCoordinates()->position);
});

test('the spy planner skips a target it already holds fresh intel on', function (): void {
    colonyProfile($this->currentUserId);
    $this->planetAddUnit('espionage_probe', 1);

    $probed = $this->createForeignPlanet();
    $unprobed = $this->createForeignPlanet();

    $probedCoordinates = $probed->getPlanetCoordinates();
    $unprobedCoordinates = $unprobed->getPlanetCoordinates();

    spyReport($this->currentUserId, $probedCoordinates->galaxy, $probedCoordinates->system, $probedCoordinates->position);

    $plan = app(QueueableSpyPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableSpy::class)
        ->and($plan->targetGalaxy)->toBe($unprobedCoordinates->galaxy)
        ->and($plan->targetSystem)->toBe($unprobedCoordinates->system)
        ->and($plan->targetPosition)->toBe($unprobedCoordinates->position);
});

test('the spy planner skips a target it already has a probe in flight toward', function (): void {
    colonyProfile($this->currentUserId);
    $this->planetAddUnit('espionage_probe', 1);

    $inFlight = $this->createForeignPlanet();
    $open = $this->createForeignPlanet();

    $inFlightCoordinates = $inFlight->getPlanetCoordinates();
    $openCoordinates = $open->getPlanetCoordinates();

    spyFleetMission($this->currentUserId, $inFlightCoordinates->galaxy, $inFlightCoordinates->system, $inFlightCoordinates->position);

    $plan = app(QueueableSpyPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableSpy::class)
        ->and($plan->targetGalaxy)->toBe($openCoordinates->galaxy)
        ->and($plan->targetSystem)->toBe($openCoordinates->system)
        ->and($plan->targetPosition)->toBe($openCoordinates->position);
});

test('the spy planner skips a target it already has a queued intent toward', function (): void {
    colonyProfile($this->currentUserId);
    $this->planetAddUnit('espionage_probe', 1);

    $queued = $this->createForeignPlanet();
    $open = $this->createForeignPlanet();

    $queuedCoordinates = $queued->getPlanetCoordinates();
    $openCoordinates = $open->getPlanetCoordinates();

    openSpyIntent($this->currentUserId, $queuedCoordinates->galaxy, $queuedCoordinates->system, $queuedCoordinates->position);

    $plan = app(QueueableSpyPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableSpy::class)
        ->and($plan->targetGalaxy)->toBe($openCoordinates->galaxy)
        ->and($plan->targetSystem)->toBe($openCoordinates->system)
        ->and($plan->targetPosition)->toBe($openCoordinates->position);
});

test('the spy planner plans nothing without a probe or a legal target', function (): void {
    $profile = colonyProfile($this->currentUserId);
    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId))->toBeNull();

    $this->planetAddUnit('espionage_probe', 1);
    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId))->toBeNull();

    $profile->update(['enabled' => false]);
    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('the spy action launches the host espionage mission', function (): void {
    colonyProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('espionage_probe', 1);
    $this->createForeignPlanet();

    $plan = app(QueueableSpyPlanner::class)->plan($this->currentUserId);
    expect($plan)->not->toBeNull();

    $result = app(QueueAiSpy::class)->handle($this->currentUserId, $plan->planetId, $plan->targetGalaxy, $plan->targetSystem, $plan->targetPosition, $plan->targetType);

    expect($result->successful)->toBeTrue($result->reason)
        ->and($result->queueId)->not->toBeNull();
});

function colonyProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 10_000 + $playerId,
        'enabled' => true,
    ]);
}

/** A fresh espionage report delivered to the account as the host would after a probe. */
function spyReport(int $playerId, int $galaxy, int $system, int $position): int
{
    $report = new EspionageReport();
    $report->planet_galaxy = $galaxy;
    $report->planet_system = $system;
    $report->planet_position = $position;
    $report->planet_type = 1;
    $report->planet_user_id = null;
    $report->resources = [];
    $report->debris = [];
    $report->buildings = [];
    $report->research = [];
    $report->ships = [];
    $report->defense = [];
    $report->player_info = [];
    $report->save();

    $player = app(\OGame\Factories\PlayerServiceFactory::class)->make($playerId, true);
    app(MessageService::class)->sendEspionageReportMessageToPlayer($player, $report->id);

    return $report->id;
}

/** A queued spy intent the account has decided on but not yet dispatched. */
function openSpyIntent(int $playerId, int $galaxy, int $system, int $position): void
{
    AiWorkItem::query()->create([
        'player_id' => $playerId,
        'kind' => AiWorkKind::Spy,
        'state' => AiWorkState::Pending,
        'due_at' => now(),
        'schedule_generation' => 1,
        'idempotency_key' => 'spy-test:' . $playerId . ':' . $galaxy . ':' . $system . ':' . $position,
        'payload' => [
            'target_galaxy' => $galaxy,
            'target_system' => $system,
            'target_position' => $position,
        ],
    ]);
}

/** An espionage mission still travelling to its target, as the host records one. */
function spyFleetMission(int $playerId, int $galaxy, int $system, int $position): void
{
    $mission = new FleetMission();
    $mission->user_id = $playerId;
    $mission->planet_id_from = null;
    $mission->planet_id_to = null;
    $mission->mission_type = EspionageMission::getTypeId();
    $mission->galaxy_to = $galaxy;
    $mission->system_to = $system;
    $mission->position_to = $position;
    $mission->time_departure = now()->subMinute()->timestamp;
    $mission->time_arrival = now()->addMinute()->timestamp;
    $mission->time_arrival_ms = 0;
    $mission->processed = 0;
    $mission->canceled = 0;
    $mission->save();
}

/** Fill every colonisable position of a single-system universe so no empty slot remains. */
function colonyFillSingleSystem(): void
{
    $foreign = test()->createForeignPlanet();
    $foreignPlayer = $foreign->getPlayer();
    expect($foreignPlayer)->not->toBeNull();

    for ($position = 4; $position <= 12; $position++) {
        if (Planet::query()->where('galaxy', 1)->where('system', 1)->where('planet', $position)->exists()) {
            continue;
        }

        Planet::factory()->create([
            'user_id' => $foreignPlayer->getId(),
            'galaxy' => 1,
            'system' => 1,
            'planet' => $position,
            'time_last_update' => now()->timestamp,
        ]);
    }
}
