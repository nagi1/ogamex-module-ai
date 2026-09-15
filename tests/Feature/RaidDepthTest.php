<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\QueueAiRaidAction;
use Modules\AI\Contracts\QueueAiRaid;
use Modules\AI\Domain\Decision\CandidateActionFactory;
use Modules\AI\Domain\Decision\QueueableRaid;
use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Domain\Perception\PlayerObservationService;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateRejectionReason;
use Modules\AI\Enums\AiCapability;
use Modules\AI\Enums\AiQueueActionReason;
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

beforeEach(function (): void {
    app()->bind(QueueAiRaid::class, QueueAiRaidAction::class);
});

test('the raid planner refuses a distant farm that burns more fuel than the tier allows', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 1);

    // A far galaxy with a trivial pile: the sampled profit stays positive (no
    // defence to lose to), but the loot cannot clear the loot-to-fuel tier.
    $farUser = User::factory()->create();
    Planet::factory()->create([
        'user_id' => $farUser->id,
        'galaxy' => 5,
        'system' => 10,
        'planet' => 15,
        'metal' => 100,
        'crystal' => 0,
        'deuterium' => 0,
        'time_last_update' => now()->subHour()->getTimestamp(),
    ]);

    $reportId = raidDepthReport($this->currentUserId, 5, 10, 15, ['metal' => 100, 'crystal' => 0, 'deuterium' => 0]);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeNull();
});

test('the raid action refuses a target that is active at dispatch', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 1);
    $foreign = $this->createForeignPlanet();
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $reportId = raidDepthReport(
        $this->currentUserId,
        $foreign->getPlanetCoordinates()->galaxy,
        $foreign->getPlanetCoordinates()->system,
        $foreign->getPlanetCoordinates()->position,
        ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000],
    );

    $plan = app(RaidPlanner::class)->plan($this->currentUserId, $reportId);
    expect($plan)->toBeInstanceOf(QueueableRaid::class);

    Planet::query()->whereKey($foreign->getPlanetId())->update(['time_last_update' => now()->getTimestamp()]);

    $result = app(QueueAiRaid::class)->handle($this->currentUserId, $plan->originPlanetId, $plan->targetGalaxy, $plan->targetSystem, $plan->targetPosition, $plan->targetType);

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe(AiQueueActionReason::TargetActiveAtDispatch->value);
});

test('the raid action refuses a target whose moon is staging a trap', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 1);
    $foreign = $this->createForeignPlanet();
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($foreign, 2_000_000, 20);

    $reportId = raidDepthReport(
        $this->currentUserId,
        $foreign->getPlanetCoordinates()->galaxy,
        $foreign->getPlanetCoordinates()->system,
        $foreign->getPlanetCoordinates()->position,
        ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000],
    );

    $plan = app(RaidPlanner::class)->plan($this->currentUserId, $reportId);
    expect($plan)->toBeInstanceOf(QueueableRaid::class);

    // The planet reads quiet, but the moon is in use: the defender is moving a
    // fleet on the moon, not sitting still (NIN-005).
    Planet::query()->whereKey($foreign->getPlanetId())->update(['time_last_update' => now()->subMinutes(30)->getTimestamp()]);
    Planet::query()->whereKey($moon->getPlanetId())->update(['time_last_update' => now()->getTimestamp()]);

    $result = app(QueueAiRaid::class)->handle($this->currentUserId, $plan->originPlanetId, $plan->targetGalaxy, $plan->targetSystem, $plan->targetPosition, $plan->targetType);

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe(AiQueueActionReason::TargetStagingAtDispatch->value);
});

test('owned state prices a distant target higher than a near one', function (): void {
    raidDepthProfile($this->currentUserId);
    $near = $this->createForeignPlanet();

    $nearId = raidDepthReport(
        $this->currentUserId,
        $near->getPlanetCoordinates()->galaxy,
        $near->getPlanetCoordinates()->system,
        $near->getPlanetCoordinates()->position,
        ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000],
    );
    $farId = raidDepthReport($this->currentUserId, 5, 10, 15, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]);

    $reports = app(PlayerObservationService::class)->ownedState($this->currentUserId)['target_reports'];
    $byReport = array_column($reports, null, 'report_id');

    expect($byReport[$nearId]['travel_cost'])->toBeLessThan($byReport[$farId]['travel_cost'])
        ->and($byReport[$farId]['travel_cost'])->toBeGreaterThan(0.0);
});

test('a target far below our own score is published unviable', function (): void {
    raidDepthProfile($this->currentUserId);
    Highscore::query()->where('player_id', $this->currentUserId)->update(['general' => 1000]);

    $reportId = raidDepthReport($this->currentUserId, 1, 2, 3, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]);

    $reports = app(PlayerObservationService::class)->ownedState($this->currentUserId)['target_reports'];
    $byReport = array_column($reports, null, 'report_id');

    expect($byReport[$reportId]['score_viable'])->toBeFalse();
});

test('an unknown own score filters no target', function (): void {
    raidDepthProfile($this->currentUserId);

    // The isolated account's zeroed highscore row is the young-universe state:
    // no own score yet, so nothing is skipped (RAID-008).
    $reportId = raidDepthReport($this->currentUserId, 1, 2, 3, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]);

    $reports = app(PlayerObservationService::class)->ownedState($this->currentUserId)['target_reports'];
    $byReport = array_column($reports, null, 'report_id');

    expect($byReport[$reportId]['score_viable'])->toBeTrue();
});

test('an unviable target is dropped before the profit test', function (): void {
    $now = CarbonImmutable::create(2026, 9, 11, 8, 0, 0, 'UTC');
    $snapshot = app()->makeWith(PerceptionSnapshot::class, [
        'playerId' => $this->currentUserId,
        'observedAt' => $now,
        'planets' => [['id' => $this->currentPlanetId, 'resources' => ['metal' => 5_000, 'crystal' => 5_000, 'deuterium' => 5_000]]],
        'targetReports' => [[
            'report_id' => 8,
            'observed_at' => $now->getTimestamp(),
            'expires_at' => $now->addHour()->getTimestamp(),
            'confidence' => 0.8,
            'travel_cost' => 0.2,
            'attack_permitted' => true,
            'score_viable' => false,
        ]],
        'availableActions' => array_fill_keys(array_map(static fn (AiCapability $capability): string => $capability->value, AiCapability::cases()), false),
        'fleetsaveEligible' => false,
        'recoveryFactor' => 0.1,
        'sourceTimestamps' => [],
    ]);

    $generation = app(CandidateActionFactory::class)->create($snapshot);

    expect($generation->rejections)->toHaveKey('report:8', AiCandidateRejectionReason::ScoreBelowViability->value)
        ->and(array_column($generation->candidates, 'reason'))->not->toContain('fresh_visible_report');
});

test('the raid storage gate opens only when the warehouse is full', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddUnit('small_cargo', 1);

    $planner = app(RaidPlanner::class);

    // An empty warehouse keeps the fleet home: nothing has filled yet (RAID-009).
    expect($planner->storageReady($this->currentUserId))->toBeFalse();

    // Fill the warehouse well past the near-full bar.
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));

    expect($planner->storageReady($this->currentUserId))->toBeTrue();
});

test('an unfilled warehouse drops every visible target', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddUnit('small_cargo', 1);

    $now = CarbonImmutable::create(2026, 9, 11, 8, 0, 0, 'UTC');
    $snapshot = app()->makeWith(PerceptionSnapshot::class, [
        'playerId' => $this->currentUserId,
        'observedAt' => $now,
        'planets' => [['id' => $this->currentPlanetId, 'resources' => ['metal' => 5_000, 'crystal' => 5_000, 'deuterium' => 5_000]]],
        'targetReports' => [[
            'report_id' => 8,
            'observed_at' => $now->getTimestamp(),
            'expires_at' => $now->addHour()->getTimestamp(),
            'confidence' => 0.8,
            'travel_cost' => 0.2,
            'attack_permitted' => true,
            'score_viable' => true,
        ]],
        'availableActions' => array_fill_keys(array_map(static fn (AiCapability $capability): string => $capability->value, AiCapability::cases()), false),
        'fleetsaveEligible' => false,
        'recoveryFactor' => 0.1,
        'sourceTimestamps' => [],
    ]);

    $generation = app(CandidateActionFactory::class)->create($snapshot);

    expect($generation->rejections)->toHaveKey('report:8', AiCandidateRejectionReason::StorageNotFull->value)
        ->and(array_column($generation->candidates, 'reason'))->not->toContain('fresh_visible_report');
});

function raidDepthProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 13_000 + $playerId,
        'enabled' => true,
    ]);
}

function raidDepthReport(int $playerId, int $galaxy, int $system, int $position, array $resources): int
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
    $report->player_info = ['player_name' => 'Target', 'player_status' => 'inactive'];
    $report->save();

    $player = app(PlayerServiceFactory::class)->make($playerId, true);
    app(MessageService::class)->sendEspionageReportMessageToPlayer($player, $report->id);

    return $report->id;
}
