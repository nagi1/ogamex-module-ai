<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\ExecuteAiIntentAction;
use Modules\AI\Actions\ScheduleAiIntentAction;
use Modules\AI\Domain\Decision\CandidateAction;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Models\EspionageReport;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\MessageService;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

test('a build selection schedules and executes a building', function (): void {
    $profile = intentProfile($this->currentUserId);
    $this->planetAddResources(intentPlenty());

    $intent = intentSchedule($profile, AiCandidateActionType::Build, $this->currentPlanetId);

    expect($intent)->not->toBeNull()
        ->and($intent->kind)->toBe(AiWorkKind::BuildFirstBuilding);

    $result = intentExecute($intent, $this->currentPlanetId);

    expect($result[0]?->successful)->toBeTrue($result[0]?->reason);
});

test('a research selection schedules and executes a technology', function (): void {
    $profile = intentProfile($this->currentUserId);
    $this->planetAddResources(intentPlenty());
    intentFacilities();
    $this->planetSetObjectLevel('solar_plant', 20);
    // Enough warehouse that the funded balance is not "about to overflow", so the
    // plan's next step is the technology the facilities unlock, not a store.
    $this->planetSetObjectLevel('metal_store', 10);
    $this->planetSetObjectLevel('crystal_store', 10);
    $this->planetSetObjectLevel('deuterium_store', 10);

    $intent = intentSchedule($profile, AiCandidateActionType::Research, $this->currentPlanetId);

    expect($intent)->not->toBeNull()
        ->and($intent->kind)->toBe(AiWorkKind::QueueResearch);

    $result = intentExecute($intent, $this->currentPlanetId);

    expect($result[0]?->successful)->toBeTrue($result[0]?->reason);
});

test('a units selection schedules and executes a ship', function (): void {
    $profile = intentProfile($this->currentUserId);
    $this->planetAddResources(intentPlenty());
    $this->planetSetObjectLevel('shipyard', 2);
    $this->playerSetResearchLevel('combustion_drive', 2);

    $intent = intentSchedule($profile, AiCandidateActionType::QueueUnits, $this->currentPlanetId);

    expect($intent)->not->toBeNull()
        ->and($intent->kind)->toBe(AiWorkKind::QueueUnits);

    $result = intentExecute($intent, $this->currentPlanetId);

    expect($result[0]?->successful)->toBeTrue($result[0]?->reason);
});

// The yard route has to survive the real host path, not just the planner: the amount the planner
// sizes for a deficit must be one the unit queue accepts, or the account spends a session on a
// refusal instead of on power.
test('a yard power order is scheduled and executed as a satellite', function (): void {
    $profile = intentProfile($this->currentUserId);
    $this->planetAddResources(intentPlenty());
    $this->planetSetObjectLevel('shipyard', 2);
    $this->planetAddUnit('small_cargo', 1);
    // The next plant level costs more than the planet holds, so the building queue will not take
    // it and the shortfall falls through to the yard.
    $this->planetSetObjectLevel('solar_plant', 25);
    $this->planetSetObjectLevel('metal_mine', 21);
    $this->planetSetObjectLevel('crystal_mine', 21);
    $this->planetSetObjectLevel('deuterium_synthesizer', 21);

    $intent = intentSchedule($profile, AiCandidateActionType::QueueUnits, $this->currentPlanetId);

    expect($intent)->not->toBeNull()
        ->and($intent->kind)->toBe(AiWorkKind::QueueUnits)
        ->and(ObjectService::getObjectById((int) $intent->payload['unit_id'])->machine_name)->toBe('solar_satellite');

    $result = intentExecute($intent, $this->currentPlanetId);

    expect($result[0]?->successful)->toBeTrue($result[0]?->reason);
});

test('a colonize selection schedules and executes a colony mission', function (): void {
    $profile = intentProfile($this->currentUserId);
    $this->planetAddResources(intentPlenty());
    $this->playerSetResearchLevel('astrophysics', 4);
    $this->planetAddUnit('colony_ship', 1);

    $intent = intentSchedule($profile, AiCandidateActionType::Colonize, $this->currentPlanetId);

    expect($intent)->not->toBeNull()
        ->and($intent->kind)->toBe(AiWorkKind::Colonize);

    $result = intentExecute($intent, $this->currentPlanetId);

    expect($result[0]?->successful)->toBeTrue($result[0]?->reason);
});

test('a fleet save selection schedules and executes a deployment', function (): void {
    $profile = intentProfile($this->currentUserId);
    $this->planetAddResources(intentPlenty());
    $this->planetAddUnit('large_cargo', 5);

    $intent = intentSchedule($profile, AiCandidateActionType::FleetSave, $this->currentPlanetId);

    expect($intent)->not->toBeNull()
        ->and($intent->kind)->toBe(AiWorkKind::FleetSave);

    $result = intentExecute($intent, $this->currentPlanetId);

    expect($result[0]?->successful)->toBeTrue($result[0]?->reason);
});

test('a spy selection schedules and executes an espionage mission', function (): void {
    $profile = intentProfile($this->currentUserId);
    $this->planetAddResources(intentPlenty());
    $this->planetAddUnit('espionage_probe', 1);
    $foreign = $this->createForeignPlanet();
    // The spy planner scouts quiet targets; age the fixture's activity star.
    Planet::query()->whereKey($foreign->getPlanetId())->update(['time_last_update' => now()->subMinutes(30)->getTimestamp()]);

    $intent = intentSchedule($profile, AiCandidateActionType::Spy, $this->currentPlanetId);

    expect($intent)->not->toBeNull()
        ->and($intent->kind)->toBe(AiWorkKind::Spy);

    $result = intentExecute($intent, $this->currentPlanetId);

    expect($result[0]?->successful)->toBeTrue($result[0]?->reason);
});

test('a raid selection schedules and executes an attack', function (): void {
    $profile = intentProfile($this->currentUserId);
    $this->planetAddResources(intentPlenty());
    $this->planetAddUnit('small_cargo', 1);
    $foreign = $this->createForeignPlanet();
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $reportId = intentReport($this->currentUserId, $foreign);
    // The dispatch re-check refuses a just-touched target, so age the fixture's
    // activity star: a raid flies at a quiet target.
    Planet::query()->whereKey($foreign->getPlanetId())->update(['time_last_update' => now()->subMinutes(30)->getTimestamp()]);

    $trace = intentTrace($this->currentUserId, $this->currentPlanetId, AiCandidateActionType::Raid, ['report_id' => $reportId]);
    $session = intentSession($profile, 'raid');
    app(ScheduleAiIntentAction::class)->handle($profile, $session, $trace);

    $intent = AiWorkItem::query()->where('idempotency_key', 'intent:session:' . $session->id)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->kind)->toBe(AiWorkKind::Raid);

    $result = intentExecute($intent, $this->currentPlanetId);

    expect($result[0]?->successful)->toBeTrue($result[0]?->reason);
});

test('a do-nothing selection schedules no work', function (): void {
    $profile = intentProfile($this->currentUserId);
    $this->planetAddResources(intentPlenty());

    $session = intentSession($profile, 'nothing');
    app(ScheduleAiIntentAction::class)->handle($profile, $session, intentTrace($this->currentUserId, $this->currentPlanetId, AiCandidateActionType::DoNothing));

    expect(AiWorkItem::query()->where('idempotency_key', 'intent:session:' . $session->id)->exists())->toBeFalse();
});

test('a selection whose planner finds nothing schedules no work', function (): void {
    $profile = intentProfile($this->currentUserId);

    $session = intentSession($profile, 'colony-nothing');
    app(ScheduleAiIntentAction::class)->handle($profile, $session, intentTrace($this->currentUserId, $this->currentPlanetId, AiCandidateActionType::Colonize));

    expect(AiWorkItem::query()->where('idempotency_key', 'intent:session:' . $session->id)->exists())->toBeFalse();
});

test('a work item without a binding re-plans or drops safely', function (): void {
    $profile = intentProfile($this->currentUserId);
    $this->planetAddResources(intentPlenty());

    $build = intentWorkItem($profile, AiWorkKind::BuildFirstBuilding, []);
    $buildResult = app(ExecuteAiIntentAction::class)->execute($build, $this->currentPlanetId);
    expect($buildResult[0]?->successful)->toBeTrue($buildResult[0]?->reason);

    $raid = intentWorkItem($profile, AiWorkKind::Raid, []);
    $raidResult = app(ExecuteAiIntentAction::class)->execute($raid, $this->currentPlanetId);
    expect($raidResult[0])->toBeNull()
        ->and($raidResult[1])->toBe([])
        ->and($raidResult[2])->toBe(0);
});

function intentProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 12_000 + $playerId,
        'enabled' => true,
    ]);
}

function intentPlenty(): Resources
{
    return new Resources(1_000_000, 1_000_000, 1_000_000);
}

function intentFacilities(): void
{
    $levels = [];
    foreach ([...OGame\Services\ObjectService::getResearchObjects(), ...OGame\Services\ObjectService::getUnitObjects()] as $object) {
        foreach (OGame\Services\ObjectService::getRecursiveRequirements($object->machine_name) as $machineName => $level) {
            $levels[$machineName] = max($levels[$machineName] ?? $level, $level);
        }
    }

    foreach ($levels as $machineName => $level) {
        $type = OGame\Services\ObjectService::getObjectByMachineName($machineName)->type;
        if (in_array($type, [OGame\GameObjects\Models\Enums\GameObjectType::Building, OGame\GameObjects\Models\Enums\GameObjectType::Station], true)) {
            test()->planetSetObjectLevel($machineName, $level);
        }
    }
}

function intentSession(AiProfile $profile, string $suffix): AiWorkItem
{
    return AiWorkItem::create([
        'player_id' => $profile->player_id,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now(),
        'idempotency_key' => 'intent-session:' . $profile->player_id . ':' . $suffix,
        'state' => AiWorkState::Pending,
    ]);
}

function intentWorkItem(AiProfile $profile, AiWorkKind $kind, array $payload): AiWorkItem
{
    return AiWorkItem::create([
        'player_id' => $profile->player_id,
        'kind' => $kind,
        'due_at' => now(),
        'idempotency_key' => 'intent-work:' . $profile->player_id . ':' . $kind->value . ':' . uniqid(),
        'state' => AiWorkState::Pending,
        'payload' => $payload,
    ]);
}

/** Schedule the selected action and return the intent work item it created. */
function intentSchedule(AiProfile $profile, AiCandidateActionType $type, int $planetId, array $parameters = []): ?AiWorkItem
{
    $session = intentSession($profile, $type->value . '-' . uniqid());
    app(ScheduleAiIntentAction::class)->handle($profile, $session, intentTrace($profile->player_id, $planetId, $type, $parameters));

    return AiWorkItem::query()->where('idempotency_key', 'intent:session:' . $session->id)->first();
}

/** @return array{0: Modules\AI\Support\AiActionResult|null, 1: array<string, mixed>, 2: int} */
function intentExecute(AiWorkItem $intent, int $planetId): array
{
    return app(ExecuteAiIntentAction::class)->execute($intent, (int) ($intent->payload['planet_id'] ?? $planetId));
}

function intentTrace(int $playerId, int $planetId, AiCandidateActionType $type, array $parameters = []): DecisionTrace
{
    $candidate = app()->makeWith(CandidateAction::class, [
        'type' => $type,
        'reason' => 'intent-fixture',
        'parameters' => $parameters,
        'features' => ['resource_need' => 0.0, 'safety' => 0.0, 'target_confidence' => 0.0, 'travel_cost' => 0.0, 'recovery' => 0.0],
        'sourceTimestamps' => [],
    ]);
    $selected = app()->makeWith(ScoredCandidate::class, ['candidate' => $candidate, 'score' => 1.0, 'components' => []]);

    return app()->makeWith(DecisionTrace::class, [
        'perception' => app()->makeWith(PerceptionSnapshot::class, [
            'playerId' => $playerId,
            'observedAt' => CarbonImmutable::instance(now()),
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
        'inputHash' => 'intent-fixture',
    ]);
}

function intentReport(int $playerId, OGame\Services\PlanetService $foreign): int
{
    $report = new EspionageReport();
    $report->planet_galaxy = $foreign->getPlanetCoordinates()->galaxy;
    $report->planet_system = $foreign->getPlanetCoordinates()->system;
    $report->planet_position = $foreign->getPlanetCoordinates()->position;
    $report->planet_type = (int) $foreign->getPlanetType()->value;
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
