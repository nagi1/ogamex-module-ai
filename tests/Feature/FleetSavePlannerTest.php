<?php

use Modules\AI\Actions\QueueAiFleetSaveAction;
use Modules\AI\Contracts\QueueAiFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Domain\Decision\SaveFailurePolicy;
use Modules\AI\Domain\Perception\PlayerObservationService;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiFleetSave::class, QueueAiFleetSaveAction::class);
});

test('the fleetsave planner picks the fleet planet and another own planet', function (): void {
    fleetProfile($this->currentUserId);
    $this->planetAddUnit('large_cargo', 5);

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableFleetSave::class)
        ->and($plan->originPlanetId)->toBe($this->currentPlanetId)
        ->and($plan->destinationPlanetId)->not->toBe($this->currentPlanetId)
        ->and($plan->destinationPlanetId)->toBeIn(fleetOwnPlanetIds($this->currentUserId));
});

test('the fleetsave planner plans nothing without a fleet or for an unmanaged account', function (): void {
    $profile = fleetProfile($this->currentUserId);
    expect(app(QueueableFleetSavePlanner::class)->plan($this->currentUserId))->toBeNull();

    $profile->update(['enabled' => false]);
    $this->planetAddUnit('small_cargo', 1);
    expect(app(QueueableFleetSavePlanner::class)->plan($this->currentUserId))->toBeNull();

    $orphaned = $this->currentUserId + 1_000_000;
    fleetProfile($orphaned);
    expect(app(QueueableFleetSavePlanner::class)->plan($orphaned))->toBeNull();
});

test('the fleetsave action deploys the fleet to another own planet', function (): void {
    fleetProfile($this->currentUserId);
    $this->planetAddResources(new Resources(100_000, 100_000, 100_000));
    $this->planetAddUnit('large_cargo', 5);

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);
    expect($plan)->not->toBeNull();

    $result = app(QueueAiFleetSave::class)->handle($this->currentUserId, $plan->originPlanetId, $plan->destinationPlanetId);

    expect($result->successful)->toBeTrue($result->reason)
        ->and($result->queueId)->not->toBeNull()
        ->and(FleetMission::query()->whereKey($result->queueId)->exists())->toBeTrue();
});

test('the fleetsave action refuses a planet it does not own', function (): void {
    fleetProfile($this->currentUserId);
    $this->planetAddUnit('large_cargo', 5);
    $foreign = $this->createForeignPlanet();

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);
    expect($plan)->not->toBeNull();

    $notOwned = app(QueueAiFleetSave::class)->handle($this->currentUserId, $plan->originPlanetId, $foreign->getPlanetId());

    expect($notOwned->successful)->toBeFalse()
        ->and($notOwned->reason)->toBe(\Modules\AI\Enums\AiQueueActionReason::PlanetNotOwned->value);
});

// A shadow split needs two own bodies and two free fleet slots; with the slots spent, the save still
// flies but stays whole rather than splitting into a second wave it cannot launch (FS-009).
test('a shadow split is withheld when fewer than two slots are free', function (): void {
    fleetProfile($this->currentUserId);
    Planet::factory()->create([
        'user_id' => $this->currentUserId,
        'galaxy' => 5,
        'system' => 10,
        'planet' => 15,
        'time_last_update' => now()->subHour()->getTimestamp(),
    ]);
    $this->planetAddUnit('large_cargo', 5);

    // The account holds six slots (computer 5); spending five leaves one free, so the split is
    // withheld while the save itself still flies.
    for ($index = 0; $index < 5; $index++) {
        FleetMission::query()->forceCreate([
            'user_id' => $this->currentUserId,
            'planet_id_from' => $this->currentPlanetId,
            'planet_id_to' => $this->currentPlanetId,
            'mission_type' => 3,
            'time_departure' => now()->subMinute()->getTimestamp(),
            'time_arrival' => now()->addHour()->getTimestamp(),
            'time_arrival_ms' => 0,
            'processed' => 0,
            'canceled' => 0,
        ]);
    }

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableFleetSave::class)
        ->and($plan?->shadowDestinationPlanetId)->toBe(0);
});

test('a save the policy skips is withheld and the gamble is named', function (): void {
    $profile = fleetProfile($this->currentUserId);
    $this->planetAddUnit('large_cargo', 5);

    $mission = fleetInboundHostileFleet($this->currentPlanetId);
    $skipSeed = fleetSkipSeed($mission->id);
    $profile->update(['random_seed' => $skipSeed]);

    $state = app(PlayerObservationService::class)->ownedState($this->currentUserId);

    expect($state['fleetsave_eligible'])->toBeFalse()
        ->and($state['fleetsave_skip_reason'])->toBe('overnight_gamble')
        ->and($state['inbound_fleets'])->not->toBeEmpty();
});

test('a save the policy does not skip stays eligible', function (): void {
    $profile = fleetProfile($this->currentUserId);
    $this->planetAddUnit('large_cargo', 5);
    $mission = fleetInboundHostileFleet($this->currentPlanetId);

    $noSkipSeed = fleetNoSkipSeed($mission->id);
    $profile->update(['random_seed' => $noSkipSeed]);

    $state = app(PlayerObservationService::class)->ownedState($this->currentUserId);

    expect($state['fleetsave_eligible'])->toBeTrue()
        ->and($state['fleetsave_skip_reason'])->toBeNull();
});

test('an inbound landing past the reaction window withholds the save and publishes the wake', function (): void {
    fleetProfile($this->currentUserId);
    $this->planetAddUnit('large_cargo', 5);
    $mission = fleetInboundHostileFleet($this->currentPlanetId, 600);

    $state = app(PlayerObservationService::class)->ownedState($this->currentUserId);

    expect($state['fleetsave_eligible'])->toBeFalse()
        ->and($state['fleetsave_skip_reason'])->toBeNull()
        ->and($state['reaction_wake_at'])->toBeBetween($mission->time_arrival - 180, $mission->time_arrival - 120);
});

test('an inbound inside the detector floor is not saved', function (): void {
    fleetProfile($this->currentUserId);
    $this->planetAddUnit('large_cargo', 5);
    fleetInboundHostileFleet($this->currentPlanetId, 5);

    $state = app(PlayerObservationService::class)->ownedState($this->currentUserId);

    expect($state['fleetsave_eligible'])->toBeFalse()
        ->and($state['reaction_wake_at'])->toBeNull();
});

// A fleet below the persona's exposure band is not worth moving, so the reactive
// save is skipped the same way the proactive save already is (FS-001).
test('the reactive save skips a fleet below the exposure band', function (): void {
    fleetProfile($this->currentUserId);
    $this->planetAddUnit('small_cargo', 1);

    expect(app(QueueableFleetSavePlanner::class)->plan($this->currentUserId))->toBeNull();
});

// A spy probe alone is not a reason to move: only a non-espionage inbound is a
// threat worth saving from (FS-012).
test('a probe-only inbound does not trigger a save', function (): void {
    $profile = fleetProfile($this->currentUserId);
    $this->planetAddUnit('large_cargo', 5);
    $mission = fleetInboundEspionageFleet($this->currentPlanetId);
    $profile->update(['random_seed' => fleetNoSkipSeed($mission->id)]);

    $state = app(PlayerObservationService::class)->ownedState($this->currentUserId);

    expect($state['fleetsave_eligible'])->toBeFalse()
        ->and($state['inbound_fleets'])->not->toBeEmpty();
});

function fleetProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 9_000 + $playerId,
        'enabled' => true,
    ]);
}

/** @return list<int> */
function fleetOwnPlanetIds(int $playerId): array
{
    return array_map(
        static fn ($planet): int => $planet->getPlanetId(),
        app(\OGame\Factories\PlayerServiceFactory::class)->make($playerId, true)->planets->all(),
    );
}

function fleetInboundHostileFleet(int $targetPlanetId, int $leadSeconds = 150): FleetMission
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
    $mission->time_arrival = now()->addSeconds($leadSeconds)->timestamp;
    $mission->time_arrival_ms = 0;
    $mission->processed = 0;
    $mission->canceled = 0;
    $mission->save();

    return $mission;
}

function fleetInboundEspionageFleet(int $targetPlanetId): FleetMission
{
    $foreign = test()->createForeignPlanet();
    $foreignPlayer = $foreign->getPlayer();
    expect($foreignPlayer)->not->toBeNull();

    $mission = new FleetMission();
    $mission->user_id = $foreignPlayer->getId();
    $mission->planet_id_from = $foreign->getPlanetId();
    $mission->planet_id_to = $targetPlanetId;
    $mission->mission_type = \OGame\GameMissions\EspionageMission::getTypeId();
    $mission->espionage_probe = 1;
    $mission->time_departure = now()->subMinute()->timestamp;
    $mission->time_arrival = now()->addSeconds(150)->timestamp;
    $mission->time_arrival_ms = 0;
    $mission->processed = 0;
    $mission->canceled = 0;
    $mission->save();

    return $mission;
}

function fleetSkipSeed(int $missionId): int
{
    $policy = app(SaveFailurePolicy::class);
    for ($seed = 0; $seed < 1000; $seed++) {
        if ($policy->shouldSkip($seed, $missionId) !== null) {
            return $seed;
        }
    }

    throw new RuntimeException('No seed found that skips mission ' . $missionId);
}

function fleetNoSkipSeed(int $missionId): int
{
    $policy = app(SaveFailurePolicy::class);
    for ($seed = 0; $seed < 1000; $seed++) {
        if ($policy->shouldSkip($seed, $missionId) === null) {
            return $seed;
        }
    }

    throw new RuntimeException('No seed found that does not skip mission ' . $missionId);
}
