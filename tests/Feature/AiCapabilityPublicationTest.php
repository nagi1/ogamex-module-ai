<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\RunAiSessionAction;
use Modules\AI\Actions\ScheduleAiIntentAction;
use Modules\AI\Domain\Decision\BuildFirstBuilding;
use Modules\AI\Domain\Decision\CandidateAction;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Domain\Perception\PlayerObservationService;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiCapability;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiDecisionTrace;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\SystemAiClock;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\BuildingQueue;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\BuildingQueueService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    $this->app->bind(AiClock::class, SystemAiClock::class);
});

test('it publishes the build capability when the account can queue its chosen building', function (): void {
    $profile = capabilityProfile($this->currentUserId);
    $this->planetAddResources(capabilityPlenty());

    $state = capabilityOwnedState($this->currentUserId);
    $plan = app(QueueableBuildingPlanner::class)->plan($this->currentUserId);

    expect($state['available_actions'][AiCapability::Build->value])->toBeTrue()
        ->and($plan?->buildingId)->toBe((int) app(BuildFirstBuilding::class)->choose($profile)['building_id'])
        ->and(capabilityOwnedPlanetIds($this->currentUserId))->toContain($plan?->planetId);
});

// Affordability is the gate that matters most: the host cancels a queue item it cannot pay for,
// so a published capability the account cannot afford would spend a queue slot and report nothing.
test('it withholds the build capability when the account cannot pay for the chosen building', function (): void {
    capabilityProfile($this->currentUserId);
    capabilityDrainPlanets($this->currentUserId);

    expect(capabilityOwnedState($this->currentUserId)['available_actions'][AiCapability::Build->value])->toBeFalse()
        ->and(app(QueueableBuildingPlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('it withholds the build capability when the building queue is full', function (): void {
    capabilityProfile($this->currentUserId);
    $this->planetAddResources(capabilityPlenty());
    capabilityFillBuildingQueues($this->currentUserId);

    expect(capabilityOwnedState($this->currentUserId)['available_actions'][AiCapability::Build->value])->toBeFalse()
        ->and(app(QueueableBuildingPlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('it publishes no capability for an account the module does not manage', function (): void {
    $this->planetAddResources(capabilityPlenty());

    // With no profile the module has no policy to apply, so it claims no ability either.
    expect(capabilityOwnedState($this->currentUserId)['available_actions'][AiCapability::Build->value])->toBeFalse();

    capabilityProfile($this->currentUserId)->update(['enabled' => false]);

    expect(capabilityOwnedState($this->currentUserId)['available_actions'][AiCapability::Build->value])->toBeFalse();
});

test('it publishes no capability for a profile whose host account does not exist', function (): void {
    $orphanedPlayerId = $this->currentUserId + 987_654;
    capabilityProfile($orphanedPlayerId);

    expect(app(QueueableBuildingPlanner::class)->plan($orphanedPlayerId))->toBeNull();
});

test('it queues a real building when a session selects the build intent', function (): void {
    config(['ai.cognition.conversation.enabled' => false]);
    $profile = capabilityProfile($this->currentUserId);
    $this->planetAddResources(capabilityPlenty());

    $session = capabilitySession($profile, 'build');
    app()->makeWith(ProcessAiWork::class, ['workItemId' => $session->id])->handle(app(BuildFirstBuilding::class));

    $trace = AiDecisionTrace::query()->where('work_item_id', $session->id)->firstOrFail();
    $intent = AiWorkItem::query()
        ->where('player_id', $profile->player_id)
        ->where('kind', AiWorkKind::BuildFirstBuilding)
        ->firstOrFail();
    $planetId = (int) $intent->payload['planet_id'];

    expect($trace->selected_action)->toBe(AiCandidateActionType::Build)
        ->and(capabilityOwnedPlanetIds($this->currentUserId))->toContain($planetId);

    // The intent runs through the ordinary action path: lease, admission, host queue, receipt.
    app()->makeWith(ProcessAiWork::class, ['workItemId' => $intent->id])->handle(app(BuildFirstBuilding::class));

    $chosen = (int) app(BuildFirstBuilding::class)->choose($profile)['building_id'];

    expect(AiActionReceipt::query()->where('idempotency_key', $intent->idempotency_key)->firstOrFail()->state)->toBe(AiReceiptState::Accepted)
        ->and(BuildingQueue::query()->where('planet_id', $planetId)->where('object_id', $chosen)->count())->toBe(1)
        ->and($intent->fresh()?->state)->toBe(AiWorkState::Completed);
});

test('it leaves a session that cannot act with a trace and no work item', function (): void {
    config(['ai.cognition.conversation.enabled' => false]);
    $profile = capabilityProfile($this->currentUserId);
    capabilityDrainPlanets($this->currentUserId);

    $session = capabilitySession($profile, 'idle');
    app()->makeWith(ProcessAiWork::class, ['workItemId' => $session->id])->handle(app(BuildFirstBuilding::class));

    expect(AiDecisionTrace::query()->where('work_item_id', $session->id)->firstOrFail()->selected_action)->toBe(AiCandidateActionType::DoNothing)
        ->and(AiWorkItem::query()->where('player_id', $profile->player_id)->where('kind', AiWorkKind::BuildFirstBuilding)->count())->toBe(0);
});

test('it converges on one intent when a session runs twice', function (): void {
    config(['ai.cognition.conversation.enabled' => false]);
    $profile = capabilityProfile($this->currentUserId);
    $this->planetAddResources(capabilityPlenty());

    $session = capabilitySession($profile, 'retry');
    app(RunAiSessionAction::class)->handle($profile, $session);
    app(RunAiSessionAction::class)->handle($profile, $session);

    expect(AiWorkItem::query()->where('player_id', $profile->player_id)->where('kind', AiWorkKind::BuildFirstBuilding)->count())->toBe(1);
});

// Reading the account's own economy must not change it: the refresh behind the capability answer
// is in memory, so an observation cannot quietly advance the ledger it is only meant to read.
test('it does not write to the account while publishing a capability', function (): void {
    capabilityProfile($this->currentUserId);
    $this->planetAddResources(capabilityPlenty());
    $this->travel(2)->hours();

    $before = Planet::query()->whereKey($this->currentPlanetId)->firstOrFail()->toArray();
    capabilityOwnedState($this->currentUserId);

    expect(Planet::query()->whereKey($this->currentPlanetId)->firstOrFail()->toArray())->toEqual($before);
});

// Recording a decision and acting on it stay separate: a selection with no executor must stay in
// the trace rather than become a work item that quietly does nothing.
test('it schedules work only for selections the module can execute', function (): void {
    $profile = capabilityProfile($this->currentUserId);
    $this->planetAddResources(capabilityPlenty());

    foreach (AiCandidateActionType::cases() as $type) {
        $session = capabilitySession($profile, 'selection-' . $type->value);
        app(ScheduleAiIntentAction::class)->handle($profile, $session, capabilityTrace($this->currentUserId, $this->currentPlanetId, $type));

        $intent = AiWorkItem::query()->where('idempotency_key', 'intent:session:' . $session->id)->first();

        expect($intent !== null)->toBe($type === AiCandidateActionType::Build, $type->name);
    }
});

function capabilityProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 7_000 + $playerId,
    ]);
}

function capabilityPlenty(): Resources
{
    return new Resources(1_000_000, 1_000_000, 1_000_000);
}

/** @return array<string, bool> */
function capabilityOwnedState(int $playerId): array
{
    return app(PlayerObservationService::class)->ownedState($playerId);
}

/** @return list<int> */
function capabilityOwnedPlanetIds(int $playerId): array
{
    return array_map(
        static fn ($planet): int => $planet->getPlanetId(),
        app(PlayerServiceFactory::class)->make($playerId, true)->planets->all(),
    );
}

function capabilityDrainPlanets(int $playerId): void
{
    foreach (app(PlayerServiceFactory::class)->make($playerId, true)->planets->all() as $planet) {
        $planet->deductResources(new Resources($planet->metal()->get(), $planet->crystal()->get(), $planet->deuterium()->get()));
    }
}

function capabilityFillBuildingQueues(int $playerId): void
{
    $queue = app(BuildingQueueService::class);
    foreach (app(PlayerServiceFactory::class)->make($playerId, true)->planets->all() as $planet) {
        for ($index = 0; $index < 5; $index++) {
            // A metal mine has no requirements, so the host queues it while the queue has room.
            $queue->add($planet, 1);
        }
    }
}

function capabilitySession(AiProfile $profile, string $suffix): AiWorkItem
{
    return AiWorkItem::create([
        'player_id' => $profile->player_id,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now(),
        'idempotency_key' => 'capability-session:' . $profile->player_id . ':' . $suffix,
        'state' => AiWorkState::Pending,
    ]);
}

/** A trace whose selected candidate is exactly the given action, so the mapping is what is tested. */
function capabilityTrace(int $playerId, int $planetId, AiCandidateActionType $type): DecisionTrace
{
    $candidate = app()->makeWith(CandidateAction::class, [
        'type' => $type,
        'reason' => 'capability-fixture',
        'parameters' => [],
        'features' => ['resource_need' => 0.0, 'energy_blocker' => 0.0, 'safety' => 0.0, 'target_confidence' => 0.0, 'travel_cost' => 0.0, 'recovery' => 0.0],
        'sourceTimestamps' => [],
    ]);
    $selected = app()->makeWith(ScoredCandidate::class, ['candidate' => $candidate, 'score' => 1.0, 'components' => []]);

    return app()->makeWith(DecisionTrace::class, [
        'perception' => capabilityPerception($playerId, $planetId),
        'candidates' => [$selected],
        'selected' => $selected,
        'rejections' => [],
        'inputHash' => 'capability-fixture',
    ]);
}

function capabilityPerception(int $playerId, int $planetId): PerceptionSnapshot
{
    $now = CarbonImmutable::instance(now());

    return app()->makeWith(PerceptionSnapshot::class, [
        'playerId' => $playerId,
        'observedAt' => $now,
        'planets' => [['id' => $planetId, 'resources' => ['metal' => 1_000_000.0, 'crystal' => 1_000_000.0, 'deuterium' => 1_000_000.0]]],
        'targetReports' => [],
        'availableActions' => array_fill_keys(
            array_map(static fn (AiCapability $capability): string => $capability->value, AiCapability::cases()),
            false,
        ),
        'fleetsaveEligible' => false,
        'recoveryFactor' => 0.1,
        'sourceTimestamps' => ['owned_state' => $now->toIso8601String()],
    ]);
}
