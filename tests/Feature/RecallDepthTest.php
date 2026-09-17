<?php

use Modules\AI\Actions\ExecuteAiIntentAction;
use Modules\AI\Actions\QueueAiFleetSaveAction;
use Modules\AI\Actions\QueueAiRecallAction;
use Modules\AI\Actions\ScheduleAiIntentAction;
use Modules\AI\Contracts\QueueAiFleetSave;
use Modules\AI\Contracts\QueueAiRecall;
use Modules\AI\Domain\Decision\CandidateAction;
use Modules\AI\Domain\Decision\CandidateActionFactory;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Perception\PlayerObservationService;
use Modules\AI\Domain\Perception\PlayerPerceptionBuilder;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\GameMissions\DeploymentMission;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiFleetSave::class, QueueAiFleetSaveAction::class);
    app()->bind(QueueAiRecall::class, QueueAiRecallAction::class);
});

test('the recall action brings the parked save home', function (): void {
    recallProfile($this->currentUserId);
    $this->planetAddResources(new Resources(10_000, 10_000, 10_000));
    $this->planetAddUnit('large_cargo', 1);
    recallSecondOwnPlanet($this->currentUserId);

    $save = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);
    expect($save)->not->toBeNull();
    $queued = app(QueueAiFleetSave::class)->handle($this->currentUserId, $save->originPlanetId, $save->destinationPlanetId);
    expect($queued->successful)->toBeTrue($queued->reason);

    $result = app(QueueAiRecall::class)->handle($this->currentUserId, $save->originPlanetId);

    expect($result->successful)->toBeTrue($result->reason)
        ->and($result->queueId)->toBe($queued->queueId);

    $mission = FleetMission::query()->whereKey($queued->queueId)->firstOrFail();
    expect($mission->canceled)->toBe(1)
        ->and($mission->processed)->toBe(1);
});

test('the recall action refuses a deployment it does not own', function (): void {
    recallProfile($this->currentUserId);
    $foreign = $this->createForeignPlanet();
    $foreignPlayer = $foreign->getPlayer();
    expect($foreignPlayer)->not->toBeNull();

    $foreignMission = recallDeploymentRow($foreignPlayer->getId(), $foreign->getPlanetId(), $this->currentPlanetId);

    $result = app(QueueAiRecall::class)->handle($this->currentUserId, $this->currentPlanetId);

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe(AiQueueActionReason::NoDeploymentToRecall->value);

    $foreignMission->refresh();
    expect($foreignMission->canceled)->toBe(0);
});

test('the recall action withholds the recall while a hostile is inbound', function (): void {
    recallProfile($this->currentUserId);
    $this->planetAddResources(new Resources(10_000, 10_000, 10_000));
    $this->planetAddUnit('large_cargo', 1);
    recallSecondOwnPlanet($this->currentUserId);

    $save = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);
    expect($save)->not->toBeNull();
    app(QueueAiFleetSave::class)->handle($this->currentUserId, $save->originPlanetId, $save->destinationPlanetId);

    recallInboundHostileFleet($this->currentPlanetId);

    $result = app(QueueAiRecall::class)->handle($this->currentUserId, $save->originPlanetId);

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe(AiQueueActionReason::UnderAttack->value);
});

test('owned state publishes recall eligibility only when a save is parked and safe', function (): void {
    recallProfile($this->currentUserId);
    $this->planetAddUnit('small_cargo', 1);
    $second = recallSecondOwnPlanet($this->currentUserId);

    expect(app(PlayerObservationService::class)->ownedState($this->currentUserId)['recall_eligible'])->toBeFalse();

    recallDeploymentRow($this->currentUserId, $this->currentPlanetId, $second->id);
    expect(app(PlayerObservationService::class)->ownedState($this->currentUserId)['recall_eligible'])->toBeTrue();

    recallInboundHostileFleet($this->currentPlanetId);
    expect(app(PlayerObservationService::class)->ownedState($this->currentUserId)['recall_eligible'])->toBeFalse();
});

test('the recall flows from perception to a dispatched work item', function (): void {
    $profile = recallProfile($this->currentUserId);
    $second = recallSecondOwnPlanet($this->currentUserId);
    recallDeploymentRow($this->currentUserId, $this->currentPlanetId, $second->id);

    $perception = app(PlayerPerceptionBuilder::class)->build($this->currentUserId);
    expect($perception->recallEligible)->toBeTrue();

    $types = array_map(
        static fn (CandidateAction $candidate): AiCandidateActionType => $candidate->type,
        app(CandidateActionFactory::class)->create($perception)->candidates,
    );
    expect($types)->toContain(AiCandidateActionType::Recall);

    $session = AiWorkItem::create([
        'player_id' => $profile->player_id,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now(),
        'idempotency_key' => 'recall-session:' . $profile->player_id,
        'state' => AiWorkState::Pending,
    ]);
    app(ScheduleAiIntentAction::class)->handle($profile, $session, recallTrace($this->currentUserId, AiCandidateActionType::Recall));

    $intent = AiWorkItem::query()->where('idempotency_key', 'intent:session:' . $session->id)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->kind)->toBe(AiWorkKind::Recall);

    [$result] = app(ExecuteAiIntentAction::class)->execute($intent, $this->currentPlanetId);
    expect($result)->not->toBeNull()
        ->and($result->successful)->toBeTrue($result->reason);
});

test('the recall action rejects a player the host does not know', function (): void {
    $result = app(QueueAiRecall::class)->handle($this->currentUserId + 1_000_000, 0);

    expect($result->successful)->toBeFalse();
});

test('recall planning returns nothing for an unmanaged account', function (): void {
    expect(app(QueueableFleetSavePlanner::class)->recallPlan($this->currentUserId + 1_000_000))->toBeNull();
});

function recallTrace(int $playerId, AiCandidateActionType $type): DecisionTrace
{
    $candidate = app()->makeWith(CandidateAction::class, [
        'type' => $type,
        'reason' => 'recall-fixture',
        'parameters' => [],
        'features' => ['resource_need' => 0.0, 'safety' => 0.0, 'target_confidence' => 0.0, 'travel_cost' => 0.0, 'recovery' => 0.0],
        'sourceTimestamps' => [],
    ]);
    $selected = app()->makeWith(ScoredCandidate::class, ['candidate' => $candidate, 'score' => 1.0, 'components' => []]);

    return app()->makeWith(DecisionTrace::class, [
        'perception' => app(PlayerPerceptionBuilder::class)->build($playerId),
        'candidates' => [$selected],
        'selected' => $selected,
        'rejections' => [],
        'inputHash' => 'recall-fixture',
    ]);
}

function recallProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Fleeter,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 16_000 + $playerId,
        'enabled' => true,
    ]);
}

function recallSecondOwnPlanet(int $playerId): Planet
{
    return Planet::factory()->create([
        'user_id' => $playerId,
        'galaxy' => 5,
        'system' => 20,
        'planet' => 10,
        'time_last_update' => now()->subHour()->getTimestamp(),
    ]);
}

function recallDeploymentRow(int $playerId, int $fromPlanetId, int $toPlanetId): FleetMission
{
    $mission = new FleetMission();
    $mission->user_id = $playerId;
    $mission->planet_id_from = $fromPlanetId;
    $mission->planet_id_to = $toPlanetId;
    $mission->mission_type = DeploymentMission::getTypeId();
    $mission->type_from = 1;
    $mission->type_to = 1;
    $mission->small_cargo = 1;
    $mission->time_departure = now()->subMinute()->timestamp;
    $mission->time_arrival = now()->addHour()->timestamp;
    $mission->time_arrival_ms = (int) now()->addHour()->valueOf();
    $mission->processed = 0;
    $mission->canceled = 0;
    $mission->save();

    return $mission;
}

function recallInboundHostileFleet(int $targetPlanetId): FleetMission
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
