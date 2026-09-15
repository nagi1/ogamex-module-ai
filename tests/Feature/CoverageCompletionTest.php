<?php

use Modules\AI\Actions\ExecuteAiIntentAction;
use Modules\AI\Actions\ScheduleAiIntentAction;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Domain\Decision\QueueableUnit;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Domain\Perception\PlayerObservationService;
use Modules\AI\Domain\Perception\PlayerPerceptionBuilder;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\MessageService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

test('each executor re-plans or drops a work item without a binding', function (): void {
    $profile = completionProfile($this->currentUserId);
    $this->planetAddResources(completionPlenty());

    $research = completionWorkItem($profile, AiWorkKind::QueueResearch, []);
    expect(app(ExecuteAiIntentAction::class)->execute($research, $this->currentPlanetId)[0])->toBeNull();

    $units = completionWorkItem($profile, AiWorkKind::QueueUnits, []);
    expect(app(ExecuteAiIntentAction::class)->execute($units, $this->currentPlanetId)[0])->toBeNull();

    $colony = completionWorkItem($profile, AiWorkKind::Colonize, []);
    expect(app(ExecuteAiIntentAction::class)->execute($colony, $this->currentPlanetId)[0])->toBeNull();

    $fleetSave = completionWorkItem($profile, AiWorkKind::FleetSave, []);
    expect(app(ExecuteAiIntentAction::class)->execute($fleetSave, $this->currentPlanetId)[0])->toBeNull();

    $spy = completionWorkItem($profile, AiWorkKind::Spy, []);
    expect(app(ExecuteAiIntentAction::class)->execute($spy, $this->currentPlanetId)[0])->toBeNull();
});

test('a raid selection without a report id schedules nothing', function (): void {
    $profile = completionProfile($this->currentUserId);
    $this->planetAddResources(completionPlenty());
    $session = completionSession($profile, 'raid-no-report');

    app(ScheduleAiIntentAction::class)->handle($profile, $session, completionTrace($profile->player_id, $this->currentPlanetId, AiCandidateActionType::Raid));

    expect(AiWorkItem::query()->where('idempotency_key', 'intent:session:' . $session->id)->exists())->toBeFalse();
});

test('a raid selection whose report no longer plans schedules nothing', function (): void {
    $profile = completionProfile($this->currentUserId);
    $this->planetAddResources(completionPlenty());
    $session = completionSession($profile, 'raid-missing-report');

    app(ScheduleAiIntentAction::class)->handle($profile, $session, completionTrace($profile->player_id, $this->currentPlanetId, AiCandidateActionType::Raid, ['report_id' => 999_999_999]));

    expect(AiWorkItem::query()->where('idempotency_key', 'intent:session:' . $session->id)->exists())->toBeFalse();
});

test('the unit planner plans nothing when the account owns no planets', function (): void {
    completionProfile($this->currentUserId);
    Planet::query()->where('user_id', $this->currentUserId)->update(['destroyed' => 1]);

    expect(app(QueueableUnitPlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('the cargo ranking passes over ships with a worse payback', function (): void {
    completionProfile($this->currentUserId);
    $this->planetAddResources(completionPlenty());
    $this->planetSetObjectLevel('shipyard', 4);
    $this->playerSetResearchLevel('combustion_drive', 6);
    $this->playerSetResearchLevel('impulse_drive', 3);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toStartWith('role:cargo:');
});

test('the spy planner plans nothing for a missing account', function (): void {
    completionProfile($this->currentUserId);
    $this->planetAddUnit('espionage_probe', 1);

    $orphaned = $this->currentUserId + 2_000_000;
    completionProfile($orphaned);
    expect(app(QueueableSpyPlanner::class)->plan($orphaned))->toBeNull();
});

test('the spy planner skips a vacationing target', function (): void {
    completionProfile($this->currentUserId);
    $this->planetAddUnit('espionage_probe', 1);
    $foreign = $this->createForeignPlanet();
    $owner = $foreign->getPlayer();
    expect($owner)->not->toBeNull();
    User::query()->whereKey($owner->getId())->update(['vacation_mode' => true]);

    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('the raid planner plans nothing for a missing account, a stale target or a defended target', function (): void {
    $profile = completionProfile($this->currentUserId);
    $this->planetAddResources(completionPlenty());

    $orphaned = $this->currentUserId + 3_000_000;
    completionProfile($orphaned);
    expect(app(RaidPlanner::class)->plan($orphaned, 1))->toBeNull();

    $this->planetAddUnit('small_cargo', 1);
    $staleReport = completionReport($this->currentUserId, 1, 1, 4, 1);
    expect(app(RaidPlanner::class)->plan($this->currentUserId, $staleReport))->toBeNull();

    $foreign = $this->createForeignPlanet();
    $foreign->addUnit('rocket_launcher', 500);
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $defendedReport = completionReport($this->currentUserId, $foreign->getPlanetCoordinates()->galaxy, $foreign->getPlanetCoordinates()->system, $foreign->getPlanetCoordinates()->position, (int) $foreign->getPlanetType()->value);
    expect(app(RaidPlanner::class)->plan($this->currentUserId, $defendedReport))->toBeNull();
});

test('the fleetsave planner plans nothing for a single-planet account with a fleet', function (): void {
    completionProfile($this->currentUserId);
    $this->planetAddUnit('small_cargo', 1);
    Planet::query()->where('user_id', $this->currentUserId)->where('id', '<>', $this->currentPlanetId)->update(['destroyed' => 1]);

    expect(app(QueueableFleetSavePlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('the observation ignores the account\'s own missions when assembling inbound fleets', function (): void {
    completionProfile($this->currentUserId);
    $this->planetAddResources(completionPlenty());
    $this->planetAddUnit('small_cargo', 1);

    $own = new FleetMission();
    $own->user_id = $this->currentUserId;
    $own->planet_id_from = $this->currentPlanetId;
    $own->planet_id_to = completionSecondPlanetId($this->currentUserId);
    $own->mission_type = 4;
    $own->time_departure = now()->subMinute()->timestamp;
    $own->time_arrival = now()->addHour()->timestamp;
    $own->time_arrival_ms = 0;
    $own->processed = 0;
    $own->canceled = 0;
    $own->save();

    $state = app(PlayerObservationService::class)->ownedState($this->currentUserId);

    expect($state['inbound_fleets'])->toBe([]);
});

test('the perception builder maps inbound fleets defensively', function (): void {
    $snapshot = app(PlayerPerceptionBuilder::class)->fromObservation([
        'player_id' => $this->currentUserId,
        'observed_at' => now()->timestamp,
        'planets' => [],
        'target_reports' => [],
        'available_actions' => [],
        'fleetsave_eligible' => false,
        'inbound_fleets' => [
            'not-an-array',
            ['mission_id' => 5, 'time_arrival' => 123, 'planet_id_to' => 7],
            ['mission_id' => 9],
        ],
        'source_timestamps' => [],
    ]);

    expect($snapshot->inboundFleets)->toBe([
        ['mission_id' => 5, 'mission_type' => 0, 'time_arrival' => 123, 'planet_id_to' => 7],
    ]);
});

function completionProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 14_000 + $playerId,
        'enabled' => true,
    ]);
}

function completionPlenty(): Resources
{
    return new Resources(1_000_000, 1_000_000, 1_000_000);
}

function completionWorkItem(AiProfile $profile, AiWorkKind $kind, array $payload): AiWorkItem
{
    return AiWorkItem::create([
        'player_id' => $profile->player_id,
        'kind' => $kind,
        'due_at' => now(),
        'idempotency_key' => 'completion-work:' . $profile->player_id . ':' . $kind->value . ':' . uniqid(),
        'state' => AiWorkState::Pending,
        'payload' => $payload,
    ]);
}

function completionSession(AiProfile $profile, string $suffix): AiWorkItem
{
    return AiWorkItem::create([
        'player_id' => $profile->player_id,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now(),
        'idempotency_key' => 'completion-session:' . $profile->player_id . ':' . $suffix,
        'state' => AiWorkState::Pending,
    ]);
}

function completionTrace(int $playerId, int $planetId, AiCandidateActionType $type, array $parameters = []): Modules\AI\Domain\Decision\DecisionTrace
{
    $candidate = app()->makeWith(Modules\AI\Domain\Decision\CandidateAction::class, [
        'type' => $type,
        'reason' => 'completion-fixture',
        'parameters' => $parameters,
        'features' => ['resource_need' => 0.0, 'safety' => 0.0, 'target_confidence' => 0.0, 'travel_cost' => 0.0, 'recovery' => 0.0],
        'sourceTimestamps' => [],
    ]);
    $selected = app()->makeWith(Modules\AI\Domain\Decision\ScoredCandidate::class, ['candidate' => $candidate, 'score' => 1.0, 'components' => []]);

    return app()->makeWith(Modules\AI\Domain\Decision\DecisionTrace::class, [
        'perception' => app()->makeWith(Modules\AI\Domain\Perception\PerceptionSnapshot::class, [
            'playerId' => $playerId,
            'observedAt' => Carbon\CarbonImmutable::instance(now()),
            'planets' => [],
            'targetReports' => [],
            'availableActions' => [],
            'fleetsaveEligible' => false,
            'recoveryFactor' => 0.0,
            'sourceTimestamps' => [],
            'inboundFleets' => [],
        ]),
        'candidates' => [$selected],
        'selected' => $selected,
        'rejections' => [],
        'inputHash' => 'completion-fixture',
    ]);
}

function completionReport(int $playerId, int $galaxy, int $system, int $position, int $planetType): int
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

    $player = app(OGame\Factories\PlayerServiceFactory::class)->make($playerId, true);
    app(MessageService::class)->sendEspionageReportMessageToPlayer($player, $report->id);

    return $report->id;
}

function completionSecondPlanetId(int $playerId): int
{
    $planets = app(OGame\Factories\PlayerServiceFactory::class)->make($playerId, true)->planets->all();

    return $planets[1]->getPlanetId();
}
