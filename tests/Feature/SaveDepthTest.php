<?php

use Modules\AI\Actions\QueueAiFleetSaveAction;
use Modules\AI\Contracts\QueueAiFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiFleetSave::class, QueueAiFleetSaveAction::class);
});

test('the fleetsave planner saves to the farthest own planet', function (): void {
    saveDepthProfile($this->currentUserId);
    $this->planetAddUnit('small_cargo', 1);

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
    $this->planetAddUnit('small_cargo', 1);

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
    $this->planetAddResources(new Resources(10_000, 10_000, 10_000));
    $this->planetAddUnit('small_cargo', 1);

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);
    expect($plan)->not->toBeNull();

    $result = app(QueueAiFleetSave::class)->handle($this->currentUserId, $plan->originPlanetId, $plan->destinationPlanetId);

    expect($result->successful)->toBeTrue($result->reason);
    $mission = FleetMission::query()->whereKey($result->queueId)->firstOrFail();
    expect($mission->metal)->toBeGreaterThan(0)
        ->and($mission->crystal)->toBeGreaterThan(0);
});

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
