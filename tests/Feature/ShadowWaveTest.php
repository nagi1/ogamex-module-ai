<?php

use Modules\AI\Actions\QueueAiFleetSaveAction;
use Modules\AI\Contracts\QueueAiFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\DeploymentMission;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiFleetSave::class, QueueAiFleetSaveAction::class);
});

test('a large two-role fleet is split across two own bodies', function (): void {
    shadowWaveProfile($this->currentUserId, AiArchetype::Fleeter);
    $this->playerSetResearchLevel('computer_technology', 1);
    $this->planetAddResources(new Resources(100_000, 100_000, 100_000));
    $this->planetAddUnit('light_fighter', 5);
    $this->planetAddUnit('large_cargo', 3);

    $far = Planet::factory()->create(['user_id' => $this->currentUserId, 'galaxy' => 5, 'system' => 10, 'planet' => 15, 'time_last_update' => now()->subHour()->getTimestamp()]);
    $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($this->planetService, 2_000_000, 20);

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableFleetSave::class)
        ->and($plan->shadowDestinationPlanetId)->toBeGreaterThan(0)
        ->and($plan->destinationPlanetId)->toBe($moon->getPlanetId());

    $result = app(QueueAiFleetSave::class)->handle($this->currentUserId, $plan->originPlanetId, $plan->destinationPlanetId, $plan->shadowDestinationPlanetId);
    expect($result->successful)->toBeTrue($result->reason);

    $missions = FleetMission::query()
        ->where('user_id', $this->currentUserId)
        ->where('mission_type', DeploymentMission::getTypeId())
        ->get();

    expect($missions)->toHaveCount(2)
        ->and($missions->pluck('planet_id_to')->all())->toContain($far->id);
});

test('a fleet below the split bar is never split', function (): void {
    shadowWaveProfile($this->currentUserId, AiArchetype::Fleeter);
    $this->playerSetResearchLevel('computer_technology', 1);
    $this->planetAddUnit('light_fighter', 1);
    $this->planetAddUnit('small_cargo', 1);

    Planet::factory()->create(['user_id' => $this->currentUserId, 'galaxy' => 5, 'system' => 10, 'planet' => 15, 'time_last_update' => now()->subHour()->getTimestamp()]);
    app(PlanetServiceFactory::class)->createMoonForPlanet($this->planetService, 2_000_000, 20);

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableFleetSave::class)
        ->and($plan->shadowDestinationPlanetId)->toBe(0);
});

test('a single-role fleet is never split', function (): void {
    shadowWaveProfile($this->currentUserId, AiArchetype::Fleeter);
    $this->playerSetResearchLevel('computer_technology', 1);
    $this->planetAddUnit('light_fighter', 10);

    Planet::factory()->create(['user_id' => $this->currentUserId, 'galaxy' => 5, 'system' => 10, 'planet' => 15, 'time_last_update' => now()->subHour()->getTimestamp()]);
    app(PlanetServiceFactory::class)->createMoonForPlanet($this->planetService, 2_000_000, 20);

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableFleetSave::class)
        ->and($plan->shadowDestinationPlanetId)->toBe(0);
});

function shadowWaveProfile(int $playerId, AiArchetype $archetype): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => $archetype,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 25_000 + $playerId,
        'enabled' => true,
    ]);
}
