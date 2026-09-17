<?php

use Modules\AI\Actions\ExecuteAiIntentAction;
use Modules\AI\Actions\QueueAiExpeditionAction;
use Modules\AI\Actions\ScheduleAiIntentAction;
use Modules\AI\Contracts\QueueAiExpedition;
use Modules\AI\Domain\Decision\CandidateAction;
use Modules\AI\Domain\Decision\CandidateActionFactory;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\QueueableExpedition;
use Modules\AI\Domain\Decision\QueueableExpeditionPlanner;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Perception\PlayerPerceptionBuilder;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiExpedition::class, QueueAiExpeditionAction::class);
});

test('the expedition planner plans nothing without astrophysics or a ship', function (): void {
    expeditionProfile($this->currentUserId);

    expect(app(QueueableExpeditionPlanner::class)->plan($this->currentUserId))->toBeNull();

    $this->planetAddUnit('small_cargo', 1);
    expect(app(QueueableExpeditionPlanner::class)->plan($this->currentUserId))->toBeNull();

    $this->playerSetResearchLevel('astrophysics', 1);
    expect(app(QueueableExpeditionPlanner::class)->plan($this->currentUserId))->not->toBeNull();
});

test('the expedition action dispatches one small ship to slot 16', function (): void {
    expeditionProfile($this->currentUserId);
    $this->playerSetResearchLevel('astrophysics', 1);
    $this->planetAddResources(new Resources(10_000, 10_000, 10_000));
    $this->planetAddUnit('small_cargo', 3);
    $this->planetAddUnit('light_fighter', 2);
    $this->planetAddUnit('solar_satellite', 1);

    $plan = app(QueueableExpeditionPlanner::class)->plan($this->currentUserId);
    expect($plan)->toBeInstanceOf(QueueableExpedition::class)
        ->and($plan->position)->toBe(16);

    $result = app(QueueAiExpedition::class)->handle($this->currentUserId, $plan->planetId, $plan->galaxy, $plan->system);

    expect($result->successful)->toBeTrue($result->reason);
    $mission = FleetMission::query()->whereKey($result->queueId)->firstOrFail();
    expect($mission->mission_type)->toBe(15)
        ->and($mission->position_to)->toBe(16)
        ->and($mission->small_cargo)->toBe(1)
        ->and($mission->light_fighter)->toBe(1);

    // The dispatched mission consumes the account's only expedition slot.
    expect(app(QueueableExpeditionPlanner::class)->plan($this->currentUserId))->toBeNull();
});

// After the home system has already sent a recent expedition, the planner
// rotates to the own body whose system has sent the fewest (EXP-003).
test('the expedition planner rotates to the least-used own system', function (): void {
    expeditionProfile($this->currentUserId);
    $this->playerSetResearchLevel('astrophysics', 1);
    $this->planetAddUnit('small_cargo', 1);

    $far = Planet::factory()->create([
        'user_id' => $this->currentUserId,
        'galaxy' => 1,
        'system' => 410,
        'planet' => 15,
        'time_last_update' => now()->subHour()->getTimestamp(),
    ]);
    app(OGame\Factories\PlanetServiceFactory::class)->make($far->id, true)->addUnit('small_cargo', 1);

    // One recent expedition already left the home system.
    $home = $this->planetService->getPlanetCoordinates();
    $mission = new FleetMission();
    $mission->user_id = $this->currentUserId;
    $mission->planet_id_from = $this->currentPlanetId;
    $mission->planet_id_to = $far->id;
    $mission->galaxy_from = $home->galaxy;
    $mission->system_from = $home->system;
    $mission->position_from = $home->position;
    $mission->galaxy_to = $far->galaxy;
    $mission->system_to = $far->system;
    $mission->position_to = 16;
    $mission->mission_type = \OGame\GameMissions\ExpeditionMission::getTypeId();
    $mission->time_departure = now()->subHour()->timestamp;
    $mission->time_arrival = now()->subMinutes(30)->timestamp;
    $mission->time_arrival_ms = 0;
    $mission->processed = 1;
    $mission->canceled = 0;
    $mission->save();

    $plan = app(QueueableExpeditionPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableExpedition::class)
        ->and($plan->planetId)->toBe($far->id)
        ->and($plan->system)->toBe(410);
});

test('the expedition action refuses when the account owns no disposable ship', function (): void {
    expeditionProfile($this->currentUserId);
    $this->playerSetResearchLevel('astrophysics', 1);
    $this->planetAddUnit('espionage_probe', 2);

    $plan = app(QueueableExpeditionPlanner::class)->plan($this->currentUserId);
    expect($plan)->toBeNull();

    $result = app(QueueAiExpedition::class)->handle($this->currentUserId, $this->currentPlanetId, 1, 1);

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe(AiQueueActionReason::NoDisposableFleet->value);
});

test('the expedition flows from perception to a dispatched work item', function (): void {
    $profile = expeditionProfile($this->currentUserId);
    $this->playerSetResearchLevel('astrophysics', 1);
    $this->planetAddResources(new Resources(10_000, 10_000, 10_000));
    $this->planetAddUnit('small_cargo', 1);

    $perception = app(PlayerPerceptionBuilder::class)->build($this->currentUserId);
    $types = array_map(
        static fn (CandidateAction $candidate): AiCandidateActionType => $candidate->type,
        app(CandidateActionFactory::class)->create($perception)->candidates,
    );
    expect($types)->toContain(AiCandidateActionType::Expedition);

    $session = AiWorkItem::create([
        'player_id' => $profile->player_id,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now(),
        'idempotency_key' => 'expedition-session:' . $profile->player_id,
        'state' => AiWorkState::Pending,
    ]);
    app(ScheduleAiIntentAction::class)->handle($profile, $session, expeditionTrace($this->currentUserId, AiCandidateActionType::Expedition));

    $intent = AiWorkItem::query()->where('idempotency_key', 'intent:session:' . $session->id)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->kind)->toBe(AiWorkKind::Expedition);

    [$result] = app(ExecuteAiIntentAction::class)->execute($intent, $this->currentPlanetId);
    expect($result)->not->toBeNull()
        ->and($result->successful)->toBeTrue($result->reason);
});

test('the expedition action refuses a planet it does not own', function (): void {
    expeditionProfile($this->currentUserId);

    $result = app(QueueAiExpedition::class)->handle($this->currentUserId, 999_999_999, 1, 1);

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe(AiQueueActionReason::PlanetNotOwned->value);
});

function expeditionProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 17_000 + $playerId,
        'enabled' => true,
    ]);
}

function expeditionTrace(int $playerId, AiCandidateActionType $type): DecisionTrace
{
    $candidate = app()->makeWith(CandidateAction::class, [
        'type' => $type,
        'reason' => 'expedition-fixture',
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
        'inputHash' => 'expedition-fixture',
    ]);
}
