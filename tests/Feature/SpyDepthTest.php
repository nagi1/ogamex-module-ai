<?php

use Modules\AI\Domain\Decision\QueueableSpy;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\EspionageReport;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\MessageService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

test('the spy planner skips a just-touched target', function (): void {
    spyDepthProfile($this->currentUserId);
    $this->planetAddUnit('espionage_probe', 1);

    // The only foreign planet is touched now: probing it would be the tell, so
    // the planner scouts nothing rather than advertise on an active body.
    $this->createForeignPlanet();

    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('the spy planner prefers the closer target', function (): void {
    spyDepthProfile($this->currentUserId);
    $this->planetAddUnit('espionage_probe', 1);

    $near = $this->createForeignPlanet();
    spyDepthQuiet($near);

    $farUser = User::factory()->create();
    $far = Planet::factory()->create([
        'user_id' => $farUser->id,
        'galaxy' => 5,
        'system' => 10,
        'planet' => 15,
        'time_last_update' => now()->subMinutes(30)->getTimestamp(),
    ]);

    $plan = app(QueueableSpyPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableSpy::class)
        ->and($plan->targetGalaxy)->toBe($near->getPlanetCoordinates()->galaxy)
        ->and($plan->targetSystem)->toBe($near->getPlanetCoordinates()->system)
        ->and($plan->targetPosition)->toBe($near->getPlanetCoordinates()->position);
});

test('the spy planner prefers a target it knows is rich', function (): void {
    spyDepthProfile($this->currentUserId);
    $this->planetAddUnit('espionage_probe', 1);

    $known = $this->createForeignPlanet();
    $unknown = $this->createForeignPlanet();
    spyDepthQuiet($known);
    spyDepthQuiet($unknown);

    $reportId = spyDepthReport(
        $this->currentUserId,
        $known->getPlanetCoordinates()->galaxy,
        $known->getPlanetCoordinates()->system,
        $known->getPlanetCoordinates()->position,
        ['metal' => 100_000_000, 'crystal' => 100_000_000, 'deuterium' => 100_000_000],
    );
    // Age the report past the intel window: it is no longer fresh intel to skip,
    // but its resources are still the known yield the score prefers.
    Message::query()->where('espionage_report_id', $reportId)->update(['created_at' => now()->subHours(30)]);

    $plan = app(QueueableSpyPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableSpy::class)
        ->and($plan->targetGalaxy)->toBe($known->getPlanetCoordinates()->galaxy)
        ->and($plan->targetPosition)->toBe($known->getPlanetCoordinates()->position);
});

function spyDepthProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 14_000 + $playerId,
        'enabled' => true,
    ]);
}

function spyDepthQuiet(PlanetService $planet): void
{
    Planet::query()->whereKey($planet->getPlanetId())->update(['time_last_update' => now()->subMinutes(30)->getTimestamp()]);
}

function spyDepthReport(int $playerId, int $galaxy, int $system, int $position, array $resources): int
{
    $report = new EspionageReport();
    $report->planet_galaxy = $galaxy;
    $report->planet_system = $system;
    $report->planet_position = $position;
    $report->planet_type = 1;
    $report->planet_user_id = null;
    $report->resources = $resources + ['energy' => 0];
    $report->debris = [];
    $report->buildings = [];
    $report->research = [];
    $report->ships = [];
    $report->defense = [];
    $report->player_info = [];
    $report->save();

    $player = app(PlayerServiceFactory::class)->make($playerId, true);
    app(MessageService::class)->sendEspionageReportMessageToPlayer($player, $report->id);

    return $report->id;
}
