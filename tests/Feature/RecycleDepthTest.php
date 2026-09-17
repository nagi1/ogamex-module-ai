<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\QueueAiRecycleAction;
use Modules\AI\Contracts\QueueAiRecycle;
use Modules\AI\Domain\Decision\CandidateActionFactory;
use Modules\AI\Domain\Decision\QueueableRecyclePlanner;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiCapability;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\GameMissions\RecycleMission;
use OGame\Models\DebrisField;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiRecycle::class, QueueAiRecycleAction::class);
    // The recycle surface is the only shared fixture this file cares about, so
    // clear it each test rather than depend on transaction rollback in a parallel
    // worker.
    DebrisField::query()->delete();
    FleetMission::query()->where('mission_type', RecycleMission::getTypeId())->delete();
});

// The account needs a harvest hull before any field is collectable: a fresh
// account with no recycler falls through cleanly, like spy/expedition do.
test('the recycle planner plans nothing when the account has no harvest hull', function (): void {
    recycleProfile($this->currentUserId);
    DebrisField::create(['galaxy' => 1, 'system' => 2, 'planet' => 3, 'metal' => 20_000, 'crystal' => 20_000, 'deuterium' => 0]);

    expect(app(QueueableRecyclePlanner::class)->plan($this->currentUserId))->toBeNull();
});

// A field too small to justify the trip is skipped, so the account does not burn
// deuterium on dust.
test('the recycle planner skips a field below the minimum mass', function (): void {
    recycleProfile($this->currentUserId);
    $this->planetAddUnit('recycler', 1);
    DebrisField::create(['galaxy' => 1, 'system' => 2, 'planet' => 4, 'metal' => 100, 'crystal' => 100, 'deuterium' => 0]);

    expect(app(QueueableRecyclePlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('the recycle planner returns the field worth harvesting', function (): void {
    recycleProfile($this->currentUserId);
    $this->planetAddUnit('recycler', 1);
    DebrisField::create(['galaxy' => 1, 'system' => 2, 'planet' => 5, 'metal' => 20_000, 'crystal' => 20_000, 'deuterium' => 0]);

    $plan = app(QueueableRecyclePlanner::class)->plan($this->currentUserId);

    expect($plan)->not->toBeNull()
        ->and($plan?->targetGalaxy)->toBe(1)
        ->and($plan?->targetSystem)->toBe(2)
        ->and($plan?->targetPosition)->toBe(5)
        ->and($plan?->targetType)->toBe(PlanetType::DebrisField->value)
        ->and($plan?->missionType)->toBe(RecycleMission::getTypeId());
});

// A field already being harvested is skipped: a second recycler fleet to the
// same coordinates is the duplicate-dispatch loop, not a second player.
test('the recycle planner skips a field already being harvested', function (): void {
    recycleProfile($this->currentUserId);
    $this->planetAddUnit('recycler', 1);
    DebrisField::create(['galaxy' => 1, 'system' => 2, 'planet' => 6, 'metal' => 20_000, 'crystal' => 20_000, 'deuterium' => 0]);
    recycleHarvestMission($this->currentUserId, 1, 2, 6);

    expect(app(QueueableRecyclePlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('the recycle action launches the host harvest mission', function (): void {
    recycleProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('recycler', 1);
    DebrisField::create(['galaxy' => 1, 'system' => 2, 'planet' => 7, 'metal' => 20_000, 'crystal' => 20_000, 'deuterium' => 0]);

    $result = app(QueueAiRecycle::class)->handle($this->currentUserId, $this->currentPlanetId, 1, 2, 7, PlanetType::DebrisField->value);

    expect($result->successful)->toBeTrue($result->reason)
        ->and(FleetMission::query()->where('mission_type', RecycleMission::getTypeId())->count())->toBe(1);
});

test('the recycle action refuses a body it does not own', function (): void {
    recycleProfile($this->currentUserId);
    $this->planetAddUnit('recycler', 1);
    $foreign = $this->createForeignPlanet();

    $result = app(QueueAiRecycle::class)->handle($this->currentUserId, $foreign->getPlanetId(), 1, 2, 3, PlanetType::DebrisField->value);

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe(AiQueueActionReason::PlanetNotOwned->value);
});

// The candidate the decision engine actually sees: a collectable field becomes a
// recycle candidate when a fleet slot is free.
test('a recyclable field is offered as a recycle candidate', function (): void {
    recycleProfile($this->currentUserId);
    $this->planetAddUnit('recycler', 1);
    DebrisField::create(['galaxy' => 1, 'system' => 2, 'planet' => 8, 'metal' => 20_000, 'crystal' => 20_000, 'deuterium' => 0]);

    $now = CarbonImmutable::create(2026, 9, 11, 8, 0, 0, 'UTC');
    $snapshot = app()->makeWith(PerceptionSnapshot::class, [
        'playerId' => $this->currentUserId,
        'observedAt' => $now,
        'planets' => [],
        'targetReports' => [],
        'availableActions' => array_fill_keys(array_map(static fn (AiCapability $capability): string => $capability->value, AiCapability::cases()), false),
        'fleetsaveEligible' => false,
        'recoveryFactor' => 0.1,
        'sourceTimestamps' => [],
        'fleetSlotsFree' => 2,
    ]);

    $generation = app(CandidateActionFactory::class)->create($snapshot);

    expect(collect($generation->candidates)->first(static fn ($candidate): bool => $candidate->type === AiCandidateActionType::Recycle))->not->toBeNull();
});

function recycleProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 12_000 + $playerId,
        'enabled' => true,
    ]);
}

function recycleHarvestMission(int $playerId, int $galaxy, int $system, int $position): void
{
    $mission = new FleetMission();
    $mission->user_id = $playerId;
    $mission->planet_id_from = null;
    $mission->mission_type = RecycleMission::getTypeId();
    $mission->galaxy_to = $galaxy;
    $mission->system_to = $system;
    $mission->position_to = $position;
    $mission->processed = 0;
    $mission->canceled = 0;
    $mission->save();
}
