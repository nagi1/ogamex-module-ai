<?php

use Modules\AI\Actions\ScheduleAiIntentAction;
use Modules\AI\Domain\Decision\CandidateAction;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\QueueableBuilding;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\QueueableResearch;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Models\Planet;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// ECON-001's situation, in seconds instead of a cohort clock: an ordinary account with stock for the
// next building on every planet fills every planet's queue on one login (scripts/cohort-scenario.php
// `every-planet-builds`).
test('a login with free fields and stock on three planets queues a step on each', function (): void {
    $profile = everyPlanetProfile($this->currentUserId);
    everyPlanetStock($this->currentUserId);

    $steps = app(QueueableBuildingPlanner::class)->steps($this->currentUserId);
    $planetIds = array_map(static fn (QueueableBuilding|QueueableResearch $step): int => $step->planetId, $steps);

    expect($planetIds)->toHaveCount(3)
        ->and(array_unique($planetIds))->toHaveCount(3);

    $session = AiWorkItem::create([
        'player_id' => $profile->player_id,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now(),
        'idempotency_key' => 'situation-session:' . $profile->player_id,
        'state' => AiWorkState::Pending,
    ]);
    app(ScheduleAiIntentAction::class)->handle($profile, $session, everyPlanetTrace($profile->player_id, $this->currentPlanetId));

    $queued = AiWorkItem::query()->where('idempotency_key', 'like', 'intent:session:' . $session->id . '%')->get()
        ->pluck('payload')->map(static fn (array $payload): int => (int) $payload['planet_id'])->sort()->values()->all();

    expect($queued)->toBe(collect($planetIds)->sort()->values()->all());
});

function everyPlanetProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 14_000 + $playerId,
        'enabled' => true,
    ]);
}

/** An ordinary mid-game stock, not billions: enough for the next mine plus the planner's reserve, on the
 * fixture's two planets and a third with free fields. */
function everyPlanetStock(int $playerId): void
{
    $stock = new Resources(200_000, 100_000, 50_000);
    test()->planetAddResources($stock);
    test()->secondPlanetService->addResources($stock);

    Planet::factory()->create([
        'user_id' => $playerId,
        'galaxy' => 5,
        'system' => 10,
        'planet' => 15,
        'metal' => 200_000,
        'crystal' => 100_000,
        'deuterium' => 50_000,
        'field_max' => 163,
        'time_last_update' => now()->getTimestamp(),
    ]);
}

function everyPlanetTrace(int $playerId, int $planetId): DecisionTrace
{
    $selected = app()->makeWith(ScoredCandidate::class, [
        'candidate' => app()->makeWith(CandidateAction::class, [
            'type' => AiCandidateActionType::Build,
            'reason' => 'situation-fixture',
            'parameters' => [],
            'features' => ['resource_need' => 0.0, 'safety' => 0.0, 'target_confidence' => 0.0, 'travel_cost' => 0.0, 'recovery' => 0.0],
            'sourceTimestamps' => [],
        ]),
        'score' => 1.0,
        'components' => [],
    ]);

    return app()->makeWith(DecisionTrace::class, [
        'perception' => app()->makeWith(PerceptionSnapshot::class, [
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
        'inputHash' => 'situation-fixture',
    ]);
}
