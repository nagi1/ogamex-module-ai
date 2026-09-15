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
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiFleetSave::class, QueueAiFleetSaveAction::class);
});

test('the fleetsave planner picks the fleet planet and another own planet', function (): void {
    fleetProfile($this->currentUserId);
    $this->planetAddUnit('small_cargo', 1);

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
    $this->planetAddUnit('small_cargo', 1);

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);
    expect($plan)->not->toBeNull();

    $result = app(QueueAiFleetSave::class)->handle($this->currentUserId, $plan->originPlanetId, $plan->destinationPlanetId);

    expect($result->successful)->toBeTrue($result->reason)
        ->and($result->queueId)->not->toBeNull()
        ->and(FleetMission::query()->whereKey($result->queueId)->exists())->toBeTrue();
});

test('the fleetsave action refuses a planet it does not own', function (): void {
    fleetProfile($this->currentUserId);
    $this->planetAddUnit('small_cargo', 1);
    $foreign = $this->createForeignPlanet();

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);
    expect($plan)->not->toBeNull();

    $notOwned = app(QueueAiFleetSave::class)->handle($this->currentUserId, $plan->originPlanetId, $foreign->getPlanetId());

    expect($notOwned->successful)->toBeFalse()
        ->and($notOwned->reason)->toBe(\Modules\AI\Enums\AiQueueActionReason::PlanetNotOwned->value);
});

test('a save the policy skips is withheld and the gamble is named', function (): void {
    $profile = fleetProfile($this->currentUserId);
    $this->planetAddUnit('small_cargo', 1);

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
    $this->planetAddUnit('small_cargo', 1);
    $mission = fleetInboundHostileFleet($this->currentPlanetId);

    $noSkipSeed = fleetNoSkipSeed($mission->id);
    $profile->update(['random_seed' => $noSkipSeed]);

    $state = app(PlayerObservationService::class)->ownedState($this->currentUserId);

    expect($state['fleetsave_eligible'])->toBeTrue()
        ->and($state['fleetsave_skip_reason'])->toBeNull();
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

function fleetInboundHostileFleet(int $targetPlanetId): FleetMission
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
