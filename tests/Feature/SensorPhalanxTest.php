<?php

use Modules\AI\Actions\QueueAiPhalanxAction;
use Modules\AI\Contracts\QueueAiPhalanx;
use Modules\AI\Domain\Decision\BuildCandidate;
use Modules\AI\Domain\Decision\FacilityChain;
use Modules\AI\Domain\Decision\QueueablePhalanx;
use Modules\AI\Domain\Decision\QueueablePhalanxPlanner;
use Modules\AI\Domain\Decision\QueueableRaid;
use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiPhalanxScan;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\EspionageReport;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\MessageService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiPhalanx::class, QueueAiPhalanxAction::class);
});

/** The account's own moon, created through the host's own path. */
function phalanxMoon(int $playerId, int $planetId): PlanetService
{
    $player = app(PlayerServiceFactory::class)->make($playerId, true);
    $planet = app(PlanetServiceFactory::class)->makeForPlayer($player, $planetId, false);

    return app(PlanetServiceFactory::class)->createMoonForPlanet($planet, 2_000_000, 20);
}

/** The moon with a sensor phalanx standing. */
function phalanxArmedMoon(int $playerId, int $planetId): PlanetService
{
    $moon = phalanxMoon($playerId, $planetId);
    Planet::query()->whereKey($moon->getPlanetId())->update(['sensor_phalanx' => 1]);

    return app(PlanetServiceFactory::class)->makeForPlayer(
        app(PlayerServiceFactory::class)->make($playerId, true),
        $moon->getPlanetId(),
        true,
    );
}

function phalanxProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 1,
        'enabled' => true,
    ]);
}

function phalanxReport(int $playerId, int $galaxy, int $system, int $position, array $resources, ?int $targetUserId = null): int
{
    $report = new EspionageReport();
    $report->planet_galaxy = $galaxy;
    $report->planet_system = $system;
    $report->planet_position = $position;
    $report->planet_type = 1;
    $report->planet_user_id = $targetUserId;
    $report->resources = $resources + ['energy' => 0];
    $report->debris = [];
    $report->buildings = [];
    $report->research = [];
    $report->ships = [];
    $report->defense = [];
    $report->player_info = ['player_name' => 'Target', 'player_status' => 'inactive'];
    $report->save();

    $player = app(PlayerServiceFactory::class)->make($playerId, true);
    app(MessageService::class)->sendEspionageReportMessageToPlayer($player, $report->id);

    return $report->id;
}

test('a moon with no phalanx publishes no scan candidate', function (): void {
    phalanxProfile($this->currentUserId);
    phalanxMoon($this->currentUserId, $this->currentPlanetId);

    expect(app(QueueablePhalanxPlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('a moon with a phalanx publishes a scan candidate for an in-range report', function (): void {
    phalanxProfile($this->currentUserId);
    $moon = phalanxArmedMoon($this->currentUserId, $this->currentPlanetId);
    $moonModel = Planet::query()->find($moon->getPlanetId());

    // The host's allocator drops an account's planets anywhere in positions 4-12 of this very system,
    // so the neighbour of the moon is not free by construction: the target is the first free position
    // of the system instead.
    $taken = Planet::query()->where('galaxy', $moonModel->galaxy)->where('system', $moonModel->system)->pluck('planet')->all();
    $position = collect(range(1, 15))->first(static fn (int $candidate): bool => !in_array($candidate, $taken, true));
    expect($position)->not->toBeNull();

    $target = Planet::factory()->create([
        'user_id' => User::factory()->create()->id,
        'galaxy' => $moonModel->galaxy,
        'system' => $moonModel->system,
        'planet' => $position,
        'time_last_update' => now()->subHour()->getTimestamp(),
    ]);
    phalanxReport($this->currentUserId, $moonModel->galaxy, $moonModel->system, $position, ['metal' => 100], $target->user_id);

    $plan = app(QueueablePhalanxPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueablePhalanx::class)
        ->and($plan?->moonPlanetId)->toBe($moon->getPlanetId())
        ->and($plan?->targetPlanetId)->toBe($target->id);
});

test('the facility chain proposes the sensor phalanx on an owned moon', function (): void {
    $moon = phalanxMoon($this->currentUserId, $this->currentPlanetId);

    $names = array_map(
        static fn (BuildCandidate $candidate): string => ObjectService::getObjectById($candidate->buildingId)->machine_name,
        app(FacilityChain::class)->pending($moon),
    );

    expect($names)->toContain('sensor_phalanx')
        ->and(array_search('lunar_base', $names, true))->toBeLessThan(array_search('sensor_phalanx', $names, true));
});

test('a recent scan that saw incoming ships refuses an otherwise viable raid', function (): void {
    phalanxProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 1);
    $foreign = $this->createForeignPlanet();
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $reportId = phalanxReport(
        $this->currentUserId,
        $foreign->getPlanetCoordinates()->galaxy,
        $foreign->getPlanetCoordinates()->system,
        $foreign->getPlanetCoordinates()->position,
        ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000],
    );

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeInstanceOf(QueueableRaid::class);

    AiPhalanxScan::query()->create([
        'player_id' => $this->currentUserId,
        'moon_planet_id' => $this->currentPlanetId,
        'target_planet_id' => $foreign->getPlanetId(),
        'incoming_ship_count' => 5,
        'observed_at' => now(),
    ]);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeNull();
});

test('a host-refused scan records the refusal and writes no scan row', function (): void {
    $moon = phalanxArmedMoon($this->currentUserId, $this->currentPlanetId);
    $moonModel = Planet::query()->find($moon->getPlanetId());

    // A target in a different system is outside a level-1 phalanx range (0 systems).
    $target = Planet::factory()->create([
        'user_id' => User::factory()->create()->id,
        'galaxy' => $moonModel->galaxy,
        'system' => $moonModel->system + 1,
        'planet' => 1,
        'time_last_update' => now()->subHour()->getTimestamp(),
    ]);

    $result = app(QueueAiPhalanx::class)->handle($this->currentUserId, $moon->getPlanetId(), $target->id);

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe(AiQueueActionReason::PhalanxScanRefused->value)
        ->and(AiPhalanxScan::query()->count())->toBe(0);
});
