<?php

use Modules\AI\Domain\Decision\QueueableSpy;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\EspionageReport;
use OGame\Models\Highscore;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\MessageService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// The hunter reads only what a player can: the public highscore's military points, its own reports and the clock
// (rl/ogsim players::hunt_rivals / docked_hour, arm `hunt`).

beforeEach(function (): void {
    config(['ai.rl.hunt' => true]);
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Raider,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 15_000 + $this->currentUserId,
        'enabled' => true,
    ]);
    $this->planetAddUnit('espionage_probe', 10);
    // 1,000 light fighters: 4M at host prices, so a rival of 0.4M to 2.5M military points' worth is huntable.
    $this->planetAddUnit('light_fighter', 1000);
    $this->planetAddResources(new Resources(0, 0, 1_000_000, 0));

    $near = $this->createForeignPlanet();
    Planet::query()->whereKey($near->getPlanetId())->update(['time_last_update' => now()->subMinutes(30)->getTimestamp()]);
    $this->near = $near->getPlanetCoordinates();

    // An active rival: an idle account is a farm, not a hunt.
    // Farther than the neighbour, within the probes' fuel tanks.
    $home = Planet::query()->findOrFail($this->currentPlanetId);
    $rival = User::factory()->create(['time' => now()->getTimestamp()]);
    $this->rival = Planet::factory()->create([
        'user_id' => $rival->id,
        'galaxy' => $home->galaxy,
        'system' => $home->system > 250 ? $home->system - 60 : $home->system + 60,
        'planet' => 8,
        'time_last_update' => now()->subMinutes(30)->getTimestamp(),
    ]);
    $this->rivalId = $rival->id;
});

function huntPoints(int $playerId, int $military): void
{
    Highscore::query()->updateOrCreate(['player_id' => $playerId], ['military_built' => $military]);
}

/** @param array<string, int> $ships */
function huntReport(int $playerId, Planet $planet, array $ships): void
{
    $report = new EspionageReport();
    $report->planet_galaxy = (int) $planet->galaxy;
    $report->planet_system = (int) $planet->system;
    $report->planet_position = (int) $planet->planet;
    $report->planet_type = (int) $planet->planet_type;
    $report->planet_user_id = (int) $planet->user_id;
    $report->resources = ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'energy' => 0];
    $report->debris = [];
    $report->buildings = [];
    $report->research = [];
    $report->ships = $ships;
    $report->defense = [];
    $report->player_info = [];
    $report->save();
    app(MessageService::class)->sendEspionageReportMessageToPlayer(app(PlayerServiceFactory::class)->make($playerId, true), $report->id);
}

test('a hunter probes the far rival whose fleet it can crash before the nearest neighbour', function (): void {
    huntPoints($this->rivalId, 1_000);
    config(['ai.rl.hunt' => false]);
    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId)->targetSystem)->toBe($this->near->system);
    config(['ai.rl.hunt' => true]);
    Highscore::query()->where('player_id', $this->rivalId)->delete();

    $plan = app(QueueableSpyPlanner::class)->plan($this->currentUserId);
    expect($plan)->toBeInstanceOf(QueueableSpy::class)
        ->and($plan->targetSystem)->toBe($this->near->system);

    // Too strong to crash: no hunt.
    huntPoints($this->rivalId, 5_000);
    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId)->targetSystem)->toBe($this->near->system);

    huntPoints($this->rivalId, 1_000);
    $plan = app(QueueableSpyPlanner::class)->plan($this->currentUserId);
    expect($plan->targetSystem)->toBe((int) $this->rival->system);

    // The rival's live fleet is not something the hunter can read: parking more changes nothing.
    app(PlanetServiceFactory::class)->make($this->rival->id, true)->addUnit('cruiser', 50);
    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId)->targetSystem)->toBe((int) $this->rival->system);
});

test('a hunted rival is re-read after the short window, not the day-long one', function (): void {
    huntReport($this->currentUserId, $this->rival, ['light_fighter' => 200]);
    // The highscore as the host last published it (set after the report, whose delivery refreshes the table).
    huntPoints($this->rivalId, 1_000);

    // A fresh read of the parked fleet: the raid planner acts on it, the scout moves on.
    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId)->targetSystem)->toBe($this->near->system);

    // Four hours on, inside the day-long window but past the hunt's: the fleet may have moved, read it again.
    $this->travel(4)->hours();
    huntPoints($this->rivalId, 1_000);
    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId)->targetSystem)->toBe((int) $this->rival->system);
});

test('a rival whose fleet is away at this hour is not probed now', function (): void {
    // Yesterday at this hour the hunter's own probes found the planet empty.
    $this->travel(-24)->hours();
    huntReport($this->currentUserId, $this->rival, []);
    $this->travel(24)->hours();
    huntPoints($this->rivalId, 1_000);

    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId)->targetSystem)->toBe($this->near->system);

    // Six hours later the hour holds no reading yet: the hunter probes to learn the rival's day.
    $this->travel(6)->hours();
    huntPoints($this->rivalId, 1_000);
    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId)->targetSystem)->toBe((int) $this->rival->system);
});
