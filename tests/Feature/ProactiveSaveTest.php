<?php

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Decision\CandidateActionFactory;
use Modules\AI\Domain\Decision\QueueableFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiCandidateReason;
use Modules\AI\Enums\AiCapability;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Planet;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

test('a proactive save fires only past the absence threshold', function (): void {
    proactiveSaveProfile($this->currentUserId, AiArchetype::Fleeter);
    proactiveSaveDestination($this->currentUserId);
    $this->planetAddUnit('large_cargo', 1);

    $planner = app(QueueableFleetSavePlanner::class);

    expect($planner->proactivePlan($this->currentUserId, 119))->toBeNull()
        ->and($planner->proactivePlan($this->currentUserId, 120))->toBeInstanceOf(QueueableFleetSave::class);
});

test('a fleeter saves a single cargo before a long absence', function (): void {
    proactiveSaveProfile($this->currentUserId, AiArchetype::Fleeter);
    proactiveSaveDestination($this->currentUserId);
    $this->planetAddUnit('large_cargo', 1);

    expect(app(QueueableFleetSavePlanner::class)->proactivePlan($this->currentUserId, 180))
        ->toBeInstanceOf(QueueableFleetSave::class);
});

test('the exposure band keeps a small fleet parked', function (): void {
    proactiveSaveProfile($this->currentUserId, AiArchetype::Miner);
    proactiveSaveDestination($this->currentUserId);

    // One large cargo is below a miner's bar: nothing worth the trip yet.
    $this->planetAddUnit('large_cargo', 1);
    expect(app(QueueableFleetSavePlanner::class)->proactivePlan($this->currentUserId, 120))->toBeNull();

    // Five large cargo clear it: the fleet is now worth saving.
    $this->planetAddUnit('large_cargo', 4);
    expect(app(QueueableFleetSavePlanner::class)->proactivePlan($this->currentUserId, 120))
        ->toBeInstanceOf(QueueableFleetSave::class);
});

test('no fleet, no proactive save', function (): void {
    proactiveSaveProfile($this->currentUserId, AiArchetype::Fleeter);
    proactiveSaveDestination($this->currentUserId);

    expect(app(QueueableFleetSavePlanner::class)->proactivePlan($this->currentUserId, 120))->toBeNull();
});

// An account the module does not manage has no exposure band to weigh, so a long absence is not a
// save either: the planner refuses rather than saving for a stranger.
test('a proactive save for an unmanaged account is refused', function (): void {
    expect(app(QueueableFleetSavePlanner::class)->proactivePlan($this->currentUserId + 500, 180))->toBeNull();
});

test('the factory offers a proactive save only for an upcoming absence', function (): void {
    proactiveSaveProfile($this->currentUserId, AiArchetype::Fleeter);
    proactiveSaveDestination($this->currentUserId);
    $this->planetAddUnit('large_cargo', 1);

    $offered = app(CandidateActionFactory::class)->create(proactiveSaveSnapshot($this->currentUserId, $this->currentPlanetId, 180));
    $quiet = app(CandidateActionFactory::class)->create(proactiveSaveSnapshot($this->currentUserId, $this->currentPlanetId, null));

    expect(array_map(static fn ($c) => $c->type, $offered->candidates))->toContain(AiCandidateActionType::FleetSave)
        ->and(array_map(static fn ($c) => $c->reason, $offered->candidates))->toContain(AiCandidateReason::ProactiveSave->value)
        ->and(array_map(static fn ($c) => $c->type, $quiet->candidates))->not->toContain(AiCandidateActionType::FleetSave);
});

function proactiveSaveProfile(int $playerId, AiArchetype $archetype): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => $archetype,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 20_000 + $playerId,
        'enabled' => true,
    ]);
}

function proactiveSaveDestination(int $playerId): void
{
    Planet::factory()->create([
        'user_id' => $playerId,
        'galaxy' => 5,
        'system' => 10,
        'planet' => 15,
        'time_last_update' => now()->subHour()->getTimestamp(),
    ]);
}

function proactiveSaveSnapshot(int $playerId, int $planetId, ?int $absenceMinutes): PerceptionSnapshot
{
    return app()->makeWith(PerceptionSnapshot::class, [
        'playerId' => $playerId,
        'observedAt' => CarbonImmutable::create(2026, 9, 11, 8, 0, 0, 'UTC'),
        'planets' => [['id' => $planetId, 'resources' => ['metal' => 5_000, 'crystal' => 5_000, 'deuterium' => 5_000]]],
        'targetReports' => [],
        'availableActions' => array_fill_keys(array_map(static fn (AiCapability $capability): string => $capability->value, AiCapability::cases()), false),
        'fleetsaveEligible' => false,
        'recoveryFactor' => 0.1,
        'sourceTimestamps' => [],
        'upcomingAbsenceMinutes' => $absenceMinutes,
    ]);
}
