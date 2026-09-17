<?php

use Modules\AI\Actions\QueueAiFleetSaveAction;
use Modules\AI\Contracts\QueueAiFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\RecycleMission;
use OGame\Models\DebrisField;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiFleetSave::class, QueueAiFleetSaveAction::class);
    DebrisField::query()->delete();
});

test('the fleetsave planner saves to the farthest own planet', function (): void {
    saveDepthProfile($this->currentUserId);
    $this->planetAddUnit('large_cargo', 5);

    // A far colony the account owns: the save flies there, not to the nearest
    // other planet in id order.
    $far = Planet::factory()->create([
        'user_id' => $this->currentUserId,
        'galaxy' => 5,
        'system' => 10,
        'planet' => 15,
        'time_last_update' => now()->subHour()->getTimestamp(),
    ]);

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableFleetSave::class)
        ->and($plan->destinationPlanetId)->toBe($far->id);
});

test('the fleetsave planner parks on a moon when one exists', function (): void {
    saveDepthProfile($this->currentUserId);
    $this->planetAddUnit('large_cargo', 5);

    // A far own planet exists, but the account's own moon is the safer park:
    // the phalanx cannot see a moon (CRASH-006).
    Planet::factory()->create([
        'user_id' => $this->currentUserId,
        'galaxy' => 5,
        'system' => 10,
        'planet' => 15,
        'time_last_update' => now()->subHour()->getTimestamp(),
    ]);

    $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($this->planetService, 2_000_000, 20);

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableFleetSave::class)
        ->and($plan->destinationPlanetId)->toBe($moon->getPlanetId());
});

test('the fleetsave action lifts the planet stock into the save', function (): void {
    saveDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(100_000, 100_000, 100_000));
    $this->planetAddUnit('large_cargo', 5);

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);
    expect($plan)->not->toBeNull();

    $result = app(QueueAiFleetSave::class)->handle($this->currentUserId, $plan->originPlanetId, $plan->destinationPlanetId);

    expect($result->successful)->toBeTrue($result->reason);
    $mission = FleetMission::query()->whereKey($result->queueId)->firstOrFail();
    expect($mission->metal)->toBeGreaterThan(0)
        ->and($mission->crystal)->toBeGreaterThan(0);
});

// Never deploy the save into another incoming attack: when every own body
// except the origin is itself under inbound hostile, there is no safe
// destination and no save (FS-010).
test('the fleetsave planner refuses to save into a body already under attack', function (): void {
    saveDepthProfile($this->currentUserId);
    $this->planetAddUnit('large_cargo', 5);

    foreach (app(PlayerServiceFactory::class)->make($this->currentUserId, true)->planets->all() as $planet) {
        if ($planet->getPlanetId() !== $this->currentPlanetId) {
            saveDepthHostileFleet($planet->getPlanetId());
        }
    }

    expect(app(QueueableFleetSavePlanner::class)->plan($this->currentUserId))->toBeNull();
});

// A same-coordinate planet↔moon hop is invisible to the phalanx, so it ranks
// ahead of any farther moon (FS-005).
test('the fleetsave planner prefers the origin own same-coordinate moon', function (): void {
    saveDepthProfile($this->currentUserId);
    $this->planetAddUnit('large_cargo', 5);

    $player = app(PlayerServiceFactory::class)->make($this->currentUserId, true);

    $far = Planet::factory()->create([
        'user_id' => $this->currentUserId,
        'galaxy' => 5,
        'system' => 10,
        'planet' => 15,
        'time_last_update' => now()->subHour()->getTimestamp(),
    ]);
    app(PlanetServiceFactory::class)->createMoonForPlanet(
        app(PlanetServiceFactory::class)->makeForPlayer($player, $far->id),
        2_000_000,
        20,
    );

    $originMoon = app(PlanetServiceFactory::class)->createMoonForPlanet($this->planetService, 2_000_000, 20);

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableFleetSave::class)
        ->and($plan->destinationPlanetId)->toBe($originMoon->getPlanetId());
});

// A single-planet account cannot deploy, so it parks the fleet on a host debris
// field via a recycle mission (FS-011).
test('a single-planet account fleetsaves to a debris field', function (): void {
    saveDepthProfile($this->currentUserId);
    $this->planetAddUnit('recycler', 3);

    Planet::query()->where('user_id', $this->currentUserId)->where('id', '<>', $this->currentPlanetId)->update(['destroyed' => 1]);
    DebrisField::create(['galaxy' => 1, 'system' => 2, 'planet' => 8, 'metal' => 20_000, 'crystal' => 20_000, 'deuterium' => 0]);

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableFleetSave::class)
        ->and($plan->destinationPlanetId)->toBe(0)
        ->and($plan->missionType)->toBe(RecycleMission::getTypeId())
        ->and($plan->harvestGalaxy)->toBe(1)
        ->and($plan->harvestSystem)->toBe(2)
        ->and($plan->harvestPosition)->toBe(8);
});

// The harvest-save rides the host's own recycle mission path: the whole fleet
// leaves on a recycle mission to the debris field.
test('the fleetsave action dispatches a harvest-save as a recycle mission', function (): void {
    saveDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(100_000, 100_000, 100_000));
    $this->planetAddUnit('recycler', 3);

    Planet::query()->where('user_id', $this->currentUserId)->where('id', '<>', $this->currentPlanetId)->update(['destroyed' => 1]);
    DebrisField::create(['galaxy' => 1, 'system' => 2, 'planet' => 8, 'metal' => 20_000, 'crystal' => 20_000, 'deuterium' => 0]);

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);
    expect($plan)->not->toBeNull();

    $result = app(QueueAiFleetSave::class)->handle(
        $this->currentUserId,
        $plan->originPlanetId,
        $plan->destinationPlanetId,
        $plan->shadowDestinationPlanetId,
        $plan->harvestGalaxy,
        $plan->harvestSystem,
        $plan->harvestPosition,
    );

    expect($result->successful)->toBeTrue($result->reason);
    $mission = FleetMission::query()->whereKey($result->queueId)->firstOrFail();
    expect($mission->mission_type)->toBe(RecycleMission::getTypeId());
});

function saveDepthHostileFleet(int $targetPlanetId): FleetMission
{
    $foreign = test()->createForeignPlanet();
    $foreignPlayer = $foreign->getPlayer();

    $mission = new FleetMission();
    $mission->user_id = $foreignPlayer->getId();
    $mission->planet_id_from = $foreign->getPlanetId();
    $mission->planet_id_to = $targetPlanetId;
    $mission->mission_type = 1;
    $mission->time_departure = now()->subMinute()->timestamp;
    $mission->time_arrival = now()->addSeconds(150)->timestamp;
    $mission->time_arrival_ms = 0;
    $mission->processed = 0;
    $mission->canceled = 0;
    $mission->save();

    return $mission;
}

function saveDepthProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 15_000 + $playerId,
        'enabled' => true,
    ]);
}
