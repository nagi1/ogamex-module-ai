<?php

use Modules\AI\Actions\QueueAiFleetSaveAction;
use Modules\AI\Contracts\QueueAiFleetSave;
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

// FLEET-001/FLEET-003's situation (scripts/cohort-scenario.php `inbound-attack`): a hostile fleet is
// due inside the account's reaction lead and the account has ships to lose. An experienced player
// moves the fleet; here that means the account sees the threat, is eligible to save, and the save
// it plans leaves the attacked planet for another of its own.
test('a hostile fleet due inside the reaction lead is a save the account makes', function (): void {
    $profile = inboundAttackProfile($this->currentUserId);
    $this->planetAddResources(new Resources(100_000, 100_000, 100_000));
    $this->planetAddUnit('large_cargo', 5);
    $mission = inboundAttackFleet($this->currentPlanetId, 150);
    $profile->update(['random_seed' => inboundAttackNoSkipSeed($mission->id)]);

    $state = app(PlayerObservationService::class)->ownedState($this->currentUserId);
    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);

    expect($state['inbound_fleets'])->not->toBeEmpty()
        ->and($state['fleetsave_eligible'])->toBeTrue()
        ->and($plan)->not->toBeNull()
        ->and($plan->originPlanetId)->toBe($this->currentPlanetId);

    $result = app(QueueAiFleetSave::class)->handle($this->currentUserId, $plan->originPlanetId, $plan->destinationPlanetId);

    expect($result->successful)->toBeTrue($result->reason)
        ->and(FleetMission::query()->whereKey($result->queueId)->exists())->toBeTrue();
});

// The doctrine says a save can also fail: a fleet is not always moved. The policy that withholds it
// is seeded per account and mission, so the same account does not both save and not save one fleet.
test('the save the policy skips is withheld and named, not silently dropped', function (): void {
    $profile = inboundAttackProfile($this->currentUserId);
    $this->planetAddUnit('large_cargo', 5);
    $mission = inboundAttackFleet($this->currentPlanetId, 150);
    $profile->update(['random_seed' => inboundAttackSkipSeed($mission->id)]);

    $state = app(PlayerObservationService::class)->ownedState($this->currentUserId);

    expect($state['fleetsave_eligible'])->toBeFalse()
        ->and($state['fleetsave_skip_reason'])->not->toBeNull();
});

function inboundAttackProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 15_000 + $playerId,
        'enabled' => true,
    ]);
}

/** The host's own row for an attack (mission type 1) on the planet, from a neighbour's planet. */
function inboundAttackFleet(int $targetPlanetId, int $leadSeconds): FleetMission
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

function inboundAttackSkipSeed(int $missionId): int
{
    foreach (range(0, 999) as $seed) {
        if (app(SaveFailurePolicy::class)->shouldSkip($seed, $missionId) !== null) {
            return $seed;
        }
    }

    throw new RuntimeException('No seed skips mission ' . $missionId);
}

function inboundAttackNoSkipSeed(int $missionId): int
{
    foreach (range(0, 999) as $seed) {
        if (app(SaveFailurePolicy::class)->shouldSkip($seed, $missionId) === null) {
            return $seed;
        }
    }

    throw new RuntimeException('No seed lets mission ' . $missionId . ' through');
}
