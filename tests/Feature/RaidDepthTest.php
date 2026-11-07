<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\QueueAiRaidAction;
use Modules\AI\Contracts\QueueAiRaid;
use Modules\AI\Domain\Decision\CandidateActionFactory;
use Modules\AI\Domain\Decision\QueueableRaid;
use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Domain\Decision\RaidWavePlan;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Domain\Perception\PlayerObservationService;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiCandidateReason;
use Modules\AI\Enums\AiCandidateRejectionReason;
use Modules\AI\Enums\AiCapability;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceFeatureVersion;
use Modules\AI\Enums\AiExperienceOutcome;
use Modules\AI\Enums\AiExperienceRulesetVersion;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Infrastructure\Battle\NativeRaidEstimator;
use Modules\AI\Models\AiAffectState;
use Modules\AI\Models\AiExperienceCase;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\MoonDestructionMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Highscore;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\MessageService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiRaid::class, QueueAiRaidAction::class);
});

test('one attack cap, stated once: the wave plan honours the planner bashing limit', function (): void {
    expect(RaidWavePlan::forTarget(1_000_000)->count())->toBe(RaidPlanner::BASHING_LIMIT);
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

// 9480: the loot tier turned away raids on a declared enemy that a farmer would also refuse.
test('the raid planner flies at a war enemy whose pile does not clear the farm tier', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 1);
    $farUser = User::factory()->create();
    Planet::factory()->create([
        'user_id' => $farUser->id, 'galaxy' => 5, 'system' => 10, 'planet' => 15,
        'metal' => 100, 'crystal' => 0, 'deuterium' => 0, 'time_last_update' => now()->subHour()->getTimestamp(),
    ]);
    AiRelationship::unguarded(fn () => AiRelationship::create([
        'player_id' => $this->currentUserId, 'other_player_id' => $farUser->id, 'threat' => '0.9000', 'revision' => 0,
    ]));
    $reportId = raidDepthReport($this->currentUserId, 5, 10, 15, ['metal' => 100, 'crystal' => 0, 'deuterium' => 0]);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->not->toBeNull();
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

// Legality is the host's own answer, not a constant: the intel projection mirrors
// AttackMission's own-body / vacation / banned / admin checks (HL-003 / W9-3).
test('a report on the account own planet is published not attackable', function (): void {
    raidDepthProfile($this->currentUserId);
    $own = Planet::query()->whereKey($this->currentPlanetId)->firstOrFail();
    $reportId = raidDepthReport($this->currentUserId, $own->galaxy, $own->system, $own->planet, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]);

    $reports = app(PlayerObservationService::class)->ownedState($this->currentUserId)['target_reports'];
    $byReport = array_column($reports, null, 'report_id');

    expect($byReport[$reportId]['attack_permitted'])->toBeFalse();
});

test('a report on a vacationing player is published not attackable', function (): void {
    raidDepthProfile($this->currentUserId);
    $vacationUser = User::factory()->create(['vacation_mode' => true]);
    Planet::factory()->create([
        'user_id' => $vacationUser->id,
        'galaxy' => 1,
        'system' => 2,
        'planet' => 3,
        'time_last_update' => now()->subHour()->getTimestamp(),
    ]);
    $reportId = raidDepthReport($this->currentUserId, 1, 2, 3, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]);

    $reports = app(PlayerObservationService::class)->ownedState($this->currentUserId)['target_reports'];
    $byReport = array_column($reports, null, 'report_id');

    expect($byReport[$reportId]['attack_permitted'])->toBeFalse();
});

test('a report on an ordinary foreign planet is published attackable', function (): void {
    raidDepthProfile($this->currentUserId);
    $foreign = $this->createForeignPlanet();
    $reportId = raidDepthReport(
        $this->currentUserId,
        $foreign->getPlanetCoordinates()->galaxy,
        $foreign->getPlanetCoordinates()->system,
        $foreign->getPlanetCoordinates()->position,
        ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000],
    );

    $reports = app(PlayerObservationService::class)->ownedState($this->currentUserId)['target_reports'];
    $byReport = array_column($reports, null, 'report_id');

    expect($byReport[$reportId]['attack_permitted'])->toBeTrue();
});

// W9-2: the recovery signal the scorer reads is published from the account's own
// decaying affect (a battle it lost appraises to Anger), not left at a constant 0.
test('owned state publishes a recovery factor from the account anger', function (): void {
    raidDepthProfile($this->currentUserId);
    AiAffectState::create([
        'player_id' => $this->currentUserId,
        'emotion' => AiAffectEmotion::Anger,
        'intensity' => 0.7,
        'revision' => 0,
        'updated_for' => now(),
    ]);

    expect(app(PlayerObservationService::class)->ownedState($this->currentUserId)['recovery_factor'])->toBeGreaterThan(0.5);
});

test('owned state publishes zero recovery when no affect is recorded', function (): void {
    raidDepthProfile($this->currentUserId);

    expect(app(PlayerObservationService::class)->ownedState($this->currentUserId)['recovery_factor'])->toBe(0.0);
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
        'fleetSlotsFree' => 2,
    ]);

    $generation = app(CandidateActionFactory::class)->create($snapshot);

    expect($generation->rejections)->toHaveKey('report:8', AiCandidateRejectionReason::ScoreBelowViability->value)
        ->and(array_column($generation->candidates, 'reason'))->not->toContain('fresh_visible_report');
});

// The successful half of the raid gate: a permitted, fresh, viable report whose
// planner accepts the flight becomes a candidate, and its source timestamp is the report that made it.
test('a viable report is offered as a fresh-report raid candidate', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 20);
    $foreign = $this->createForeignPlanet();
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $reportId = raidDepthReport(
        $this->currentUserId,
        $foreign->getPlanetCoordinates()->galaxy,
        $foreign->getPlanetCoordinates()->system,
        $foreign->getPlanetCoordinates()->position,
        ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000],
    );

    $now = CarbonImmutable::create(2026, 9, 11, 8, 0, 0, 'UTC');
    $snapshot = app()->makeWith(PerceptionSnapshot::class, [
        'playerId' => $this->currentUserId,
        'observedAt' => $now,
        'planets' => [['id' => $this->currentPlanetId, 'resources' => ['metal' => 5_000, 'crystal' => 5_000, 'deuterium' => 5_000]]],
        'targetReports' => [[
            'report_id' => $reportId,
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
        'fleetSlotsFree' => 2,
    ]);

    $generation = app(CandidateActionFactory::class)->create($snapshot);

    $raid = collect($generation->candidates)->first(static fn ($candidate): bool => $candidate->type === AiCandidateActionType::Raid);

    expect($raid)->not->toBeNull()
        ->and($raid?->sourceTimestamps)->toHaveKey(AiCandidateReason::reportSource($reportId));
});

// A report that names its target user is compared against that user's own score rather than a null.
test('a report naming its target user is scored against that user', function (): void {
    raidDepthProfile($this->currentUserId);
    Highscore::query()->where('player_id', $this->currentUserId)->update(['general' => 1000]);
    $targetUser = User::factory()->create();
    Highscore::query()->updateOrCreate(['player_id' => $targetUser->id], ['general' => 500]);

    $reportId = raidDepthReport($this->currentUserId, 1, 2, 3, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000], $targetUser->id);

    $reports = app(PlayerObservationService::class)->ownedState($this->currentUserId)['target_reports'];
    $byReport = array_column($reports, null, 'report_id');

    expect($byReport[$reportId]['score_viable'])->toBeTrue();
});

// A raid estimate against a body the host no longer treats as a planet is empty, never an error:
// the estimator refuses the question rather than crash inside the battle engine.
test('a raid estimate for a body that is not a planet is empty, not an error', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddUnit('small_cargo', 1);

    $debris = $this->createForeignPlanet();
    Planet::query()->whereKey($debris->getPlanetId())->update(['planet_type' => PlanetType::DebrisField->value]);

    $estimate = app(NativeRaidEstimator::class)->estimate($this->currentUserId, $this->currentPlanetId, $debris->getPlanetId(), 1);

    expect($estimate->samples)->toBe(0)
        ->and($estimate->p20NetProfit)->toBe(0.0)
        ->and($estimate->p20Loot)->toBe(0.0)
        ->and($estimate->pWin)->toBe(0.0);
});

// The survival floor reads the host's own outcome: a defenceless target is
// survived every run, an overwhelming defence wipes the fleet every run.
test('the estimator reports pWin as the survived fraction', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddUnit('small_cargo', 1);
    $foreign = $this->createForeignPlanet();

    $clear = app(NativeRaidEstimator::class)->estimate($this->currentUserId, $this->currentPlanetId, $foreign->getPlanetId(), 1);
    expect($clear->pWin)->toBe(1.0);

    $foreign->addUnit('rocket_launcher', 500);
    $wiped = app(NativeRaidEstimator::class)->estimate($this->currentUserId, $this->currentPlanetId, $foreign->getPlanetId(), 1);
    expect($wiped->pWin)->toBe(0.0);
});

test('the raid planner skips a target it already hit inside the cooldown', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 20);
    $foreign = $this->createForeignPlanet();
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $coordinates = $foreign->getPlanetCoordinates();
    $reportId = raidDepthReport($this->currentUserId, $coordinates->galaxy, $coordinates->system, $coordinates->position, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeInstanceOf(QueueableRaid::class);

    raidFleetMission($this->currentUserId, $foreign->getPlanetId(), $coordinates, now()->getTimestamp());

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeNull();
});

test('the raid planner blacklists a target that keeps coming home empty', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 20);
    $foreign = $this->createForeignPlanet();
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $coordinates = $foreign->getPlanetCoordinates();
    $reportId = raidDepthReport($this->currentUserId, $coordinates->galaxy, $coordinates->system, $coordinates->position, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeInstanceOf(QueueableRaid::class);

    for ($i = 0; $i < 3; $i++) {
        AiExperienceCase::create([
            'player_id' => $this->currentUserId,
            'outcome_observation_id' => 9_000 + $i,
            'family' => AiExperienceCaseFamily::Raid,
            'outcome' => AiExperienceOutcome::Failed,
            'feature_version' => AiExperienceFeatureVersion::RaidV1->value,
            'ruleset_version' => AiExperienceRulesetVersion::HostBattleReportLootV1->value,
            'features' => [
                'galaxy' => $coordinates->galaxy,
                'system' => $coordinates->system,
                'position' => $coordinates->position,
                'loot' => 0,
            ],
            'utility' => 0,
            'uncertainty' => 0,
        ]);
    }

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeNull();
});

test('the raid planner leaves a target alone when its real loot stays worth it', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 20);
    $foreign = $this->createForeignPlanet();
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $coordinates = $foreign->getPlanetCoordinates();
    $reportId = raidDepthReport($this->currentUserId, $coordinates->galaxy, $coordinates->system, $coordinates->position, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]);

    for ($i = 0; $i < 3; $i++) {
        AiExperienceCase::create([
            'player_id' => $this->currentUserId,
            'outcome_observation_id' => 9_100 + $i,
            'family' => AiExperienceCaseFamily::Raid,
            'outcome' => AiExperienceOutcome::Succeeded,
            'feature_version' => AiExperienceFeatureVersion::RaidV1->value,
            'ruleset_version' => AiExperienceRulesetVersion::HostBattleReportLootV1->value,
            'features' => [
                'galaxy' => $coordinates->galaxy,
                'system' => $coordinates->system,
                'position' => $coordinates->position,
                'loot' => 100_000,
            ],
            'utility' => 1,
            'uncertainty' => 0,
        ]);
    }

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeInstanceOf(QueueableRaid::class);
});

// U6: the launch is not the stock. A target fielding a light-fighter swarm draws cruisers — the
// hull with rapid fire against it — and the account's own light fighters stay home.
test('a light-fighter swarm draws cruisers as the launch subset', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(10_000_000, 10_000_000, 10_000_000));
    $this->planetAddUnit('light_fighter', 50);
    $this->planetAddUnit('cruiser', 50);
    $this->planetAddUnit('small_cargo', 20);
    $foreign = $this->createForeignPlanet();
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $foreign->addUnit('light_fighter', 200);
    // The planner reads the defender from the report, never the live planet (step 2), so the swarm
    // has to be in the report: a report whose ship list is empty is a report that saw no ships.
    $reportId = raidDepthReport(
        $this->currentUserId,
        $foreign->getPlanetCoordinates()->galaxy,
        $foreign->getPlanetCoordinates()->system,
        $foreign->getPlanetCoordinates()->position,
        ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000],
        null,
        ['light_fighter' => 200],
    );

    $plan = app(RaidPlanner::class)->plan($this->currentUserId, $reportId);

    expect($plan)->toBeInstanceOf(QueueableRaid::class)
        ->and($plan->launchUnits)->toHaveKey('cruiser')
        ->and($plan->launchUnits)->not->toHaveKey('light_fighter');
});

// 9492: a far target needs more fuel than the hulls picked for the fight can hold; the account adds freighters, it does not
// turn the trip down.
test('a far raid is topped up with freighters until the fleet holds its fuel', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(50_000_000, 50_000_000, 50_000_000));
    $this->planetAddUnit('bomber', 3000);
    $this->planetAddUnit('large_cargo', 400);
    $farUser = User::factory()->create();
    Planet::factory()->create([
        'user_id' => $farUser->id, 'galaxy' => 9, 'system' => 10, 'planet' => 15,
        'metal' => 10_000, 'crystal' => 10_000, 'deuterium' => 10_000, 'time_last_update' => now()->subHour()->getTimestamp(),
    ]);
    AiRelationship::unguarded(fn () => AiRelationship::create([
        'player_id' => $this->currentUserId, 'other_player_id' => $farUser->id, 'threat' => '0.9000', 'revision' => 0,
    ]));
    $reportId = raidDepthReport($this->currentUserId, 9, 10, 15, ['metal' => 10_000, 'crystal' => 10_000, 'deuterium' => 10_000], null, ['light_fighter' => 100]);

    $plan = app(RaidPlanner::class)->plan($this->currentUserId, $reportId);
    $player = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
    $fleet = new UnitCollection();
    foreach ($plan?->launchUnits ?? [] as $name => $amount) {
        $fleet->addUnit(ObjectService::getUnitObjectByMachineName($name), $amount);
    }

    expect($plan)->toBeInstanceOf(QueueableRaid::class)
        ->and($plan->launchUnits['large_cargo'] ?? 0)->toBeGreaterThan(1)
        ->and($fleet->getTotalFuelCapacity($player))->toBeGreaterThan(3_000_000);
});

// U6: an undefended farm gets cargo for the haul plus one kill hull, never the whole stock.
test('an undefended farm sends kill ships plus cargo, not the whole stock', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(10_000_000, 10_000_000, 10_000_000));
    $this->planetAddUnit('small_cargo', 20);
    $this->planetAddUnit('light_fighter', 20);
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

    expect($plan)->toBeInstanceOf(QueueableRaid::class)
        ->and($plan->launchUnits)->toHaveKey('small_cargo')
        ->and($plan->launchUnits['light_fighter'])->toBe(1);
});

// U6: the subset screen is the same engine, one draw, over a caller-supplied fleet.
test('the estimator simulates a counter-selected subset, not the whole stock', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddUnit('cruiser', 10);
    $foreign = $this->createForeignPlanet();
    $foreign->addUnit('light_fighter', 100);

    $fleet = new UnitCollection();
    $fleet->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), 10);

    $estimate = app(NativeRaidEstimator::class)->estimateFleet($this->currentUserId, $this->currentPlanetId, $foreign->getPlanetId(), $fleet, 1);

    expect($estimate->samples)->toBe(1)
        ->and($estimate->pWin)->toBeIn([0.0, 1.0]);
});

// RV-007: the ladder rung is the sample counter — one draw screens a candidate, the wide pass is
// requested only for the winner.
test('the subset estimator screens one draw by default and confirms wide on request', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddUnit('cruiser', 10);
    $foreign = $this->createForeignPlanet();
    $foreign->addUnit('light_fighter', 100);

    $fleet = new UnitCollection();
    $fleet->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), 10);
    $estimator = app(NativeRaidEstimator::class);

    expect($estimator->estimateFleet($this->currentUserId, $this->currentPlanetId, $foreign->getPlanetId(), $fleet, 1)->samples)->toBe(1)
        ->and($estimator->estimateFleet($this->currentUserId, $this->currentPlanetId, $foreign->getPlanetId(), $fleet, 1, 50)->samples)->toBe(50);
});

// A late-game stack of tens of thousands against a report of hundreds of thousands of hulls once held a login inside the native
// engine for minutes: the screen fights a shrunk copy of both sides, so the plan returns in a bounded time.
test('the raid planner plans a fifty-thousand-unit fleet against a report of 300 thousand probes quickly', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 100_000_000));
    $this->planetAddUnit('battle_ship', 30_000);
    $this->planetAddUnit('large_cargo', 20_000);
    $foreign = $this->createForeignPlanet();
    $foreign->addResources(new Resources(100_000_000, 100_000_000, 100_000_000));
    $coordinates = $foreign->getPlanetCoordinates();
    $reportId = raidDepthReport($this->currentUserId, $coordinates->galaxy, $coordinates->system, $coordinates->position, ['metal' => 100_000_000, 'crystal' => 100_000_000, 'deuterium' => 100_000_000], ships: ['espionage_probe' => 300_000]);

    $started = hrtime(true);
    $plan = app(RaidPlanner::class)->plan($this->currentUserId, $reportId);

    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
        ->and($plan)->toBeInstanceOf(QueueableRaid::class);
});

// The shrunk fight decides the outcome; the haul of a win is still the whole fleet's hold, not the shrunk copy's.
test('a shrunk screen still loots with the whole fleet hold', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddUnit('large_cargo', 50_000);
    $foreign = $this->createForeignPlanet();
    $foreign->addResources(new Resources(1_000_000_000, 1_000_000_000, 1_000_000_000));
    $foreign->addUnit('rocket_launcher', 1);

    $estimate = app(NativeRaidEstimator::class)->estimate($this->currentUserId, $this->currentPlanetId, $foreign->getPlanetId(), 1);

    // 200 shrunk cargo hulls would carry 5 M; the whole fleet carries over a billion.
    expect($estimate->pWin)->toBe(1.0)
        ->and($estimate->p20Loot)->toBeGreaterThan(1_000_000_000.0);
});

// A fleet holding solar satellites must still be priced for flight: the
// stationary hulls are left out of the quote instead of making the host's
// slowest-speed division by zero crash the raid decision.
test('the raid planner prices a flight over a fleet holding solar satellites', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 20);
    $this->planetAddUnit('solar_satellite', 13);
    $foreign = $this->createForeignPlanet();
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $coordinates = $foreign->getPlanetCoordinates();
    $reportId = raidDepthReport($this->currentUserId, $coordinates->galaxy, $coordinates->system, $coordinates->position, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeInstanceOf(QueueableRaid::class);
});

// RV-011: target-class escalation. An active target is made active by touching
// the owner's last-activity stamp; the factory default (time = null) already
// reads as inactive, which is the farm every phase may hit.

test('the opening phase raids inactives only, not active players', function (): void {
    raidDepthProfile($this->currentUserId);
    // The fixture starts with two planets (a colonised account); a one-planet
    // opening is the phase under test, so every colony is removed first.
    Planet::query()->where('user_id', $this->currentUserId)->where('id', '!=', $this->currentPlanetId)->update(['destroyed' => 1]);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 20);
    $foreign = $this->createForeignPlanet();
    makeTargetActive($foreign);
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $coordinates = $foreign->getPlanetCoordinates();
    $reportId = raidDepthReport($this->currentUserId, $coordinates->galaxy, $coordinates->system, $coordinates->position, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeNull();
});

test('a colonised account raids an active, fleet-less player', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 20);
    $foreign = $this->createForeignPlanet();
    makeTargetActive($foreign);
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $coordinates = $foreign->getPlanetCoordinates();
    $reportId = raidDepthReport($this->currentUserId, $coordinates->galaxy, $coordinates->system, $coordinates->position, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeInstanceOf(QueueableRaid::class);
});

test('a colonised account fights an active fleet it beats, without waiting for a research milestone', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 20);
    $this->planetAddUnit('cruiser', 10);
    $foreign = $this->createForeignPlanet();
    makeTargetActive($foreign);
    $foreign->addUnit('light_fighter', 5);
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $coordinates = $foreign->getPlanetCoordinates();
    $reportId = raidDepthReport($this->currentUserId, $coordinates->galaxy, $coordinates->system, $coordinates->position, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000], null, ['light_fighter' => 5]);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeInstanceOf(QueueableRaid::class);
});

test('a colonised account refuses an active fleet the simulation says would beat it', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 20);
    $this->planetAddUnit('light_fighter', 5);
    $foreign = $this->createForeignPlanet();
    makeTargetActive($foreign);
    $foreign->addUnit('battle_ship', 200);
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $coordinates = $foreign->getPlanetCoordinates();
    $reportId = raidDepthReport($this->currentUserId, $coordinates->galaxy, $coordinates->system, $coordinates->position, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000], null, ['battle_ship' => 200]);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeNull();
});

test('an astrophysics-23 account crashes an active fleet', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->playerSetResearchLevel('astrophysics', 23);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 20);
    $this->planetAddUnit('cruiser', 10);
    $foreign = $this->createForeignPlanet();
    makeTargetActive($foreign);
    $foreign->addUnit('light_fighter', 5);
    $foreign->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $coordinates = $foreign->getPlanetCoordinates();
    $reportId = raidDepthReport($this->currentUserId, $coordinates->galaxy, $coordinates->system, $coordinates->position, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000], null, ['light_fighter' => 5]);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeInstanceOf(QueueableRaid::class);
});

// RV-011 helper: the owner's last-activity stamp sits in a non-fillable column,
// so it is set directly on the model rather than mass-assigned.
function makeTargetActive(PlanetService $foreign): void
{
    $user = User::query()->find($foreign->getPlayer()->getId());
    $user->time = (string) now()->timestamp;
    $user->save();
}

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

function raidDepthReport(int $playerId, int $galaxy, int $system, int $position, array $resources, ?int $targetUserId = null, array $ships = []): int
{
    $report = new EspionageReport();
    $report->planet_galaxy = $galaxy;
    $report->planet_system = $system;
    $report->planet_position = $position;
    $report->planet_type = 1;
    $report->planet_user_id = $targetUserId
        ?? Planet::query()->where('galaxy', $galaxy)->where('system', $system)->where('planet', $position)->value('user_id');
    $report->resources = $resources + ['energy' => 0];
    $report->debris = [];
    $report->buildings = [];
    $report->research = [];
    $report->ships = $ships;
    $report->defense = [];
    $report->player_info = ['player_name' => 'Target', 'player_status' => 'inactive'];
    $report->save();

    $player = app(PlayerServiceFactory::class)->make($playerId, true);
    app(MessageService::class)->sendEspionageReportMessageToPlayer($player, $report->id);

    return $report->id;
}

/** An attack mission already sent at the target, as the host records one. */
function raidFleetMission(int $playerId, int $targetPlanetId, Coordinate $coordinates, int $departure): void
{
    $mission = new FleetMission();
    $mission->user_id = $playerId;
    $mission->planet_id_from = null;
    $mission->planet_id_to = $targetPlanetId;
    $mission->mission_type = AttackMission::getTypeId();
    $mission->galaxy_to = $coordinates->galaxy;
    $mission->system_to = $coordinates->system;
    $mission->position_to = $coordinates->position;
    $mission->time_departure = $departure;
    $mission->time_arrival = $departure + 60;
    $mission->processed = 0;
    $mission->canceled = 0;
    $mission->save();
}

test('a won raid on an enemy moon flies as moon destruction with every deathstar the origin holds', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 20);
    $this->planetAddUnit('deathstar', 2);
    $foreign = $this->createForeignPlanet();
    $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($foreign, 2_000_000, 20, 15);
    $coordinates = $foreign->getPlanetCoordinates();
    $reportId = raidDepthReport($this->currentUserId, $coordinates->galaxy, $coordinates->system, $coordinates->position, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000], $foreign->getPlayer()?->getId());
    EspionageReport::query()->whereKey($reportId)->update(['planet_type' => $moon->getPlanetType()->value]);

    $plan = app(RaidPlanner::class)->plan($this->currentUserId, $reportId);

    expect($plan)->toBeInstanceOf(QueueableRaid::class)
        ->and($plan->missionType)->toBe(MoonDestructionMission::getTypeId())
        ->and($plan->launchUnits['deathstar'] ?? 0)->toBe(2);
});

test('a raid launches from the moon the fleet sits on, not the home holding probes', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('espionage_probe', 5);
    $player = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
    $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($this->planetService, 2_000_000, 20);
    Planet::query()->whereKey($moon->getPlanetId())->update(['small_cargo' => 50, 'deuterium' => 1_000_000]);
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

    expect($plan?->originPlanetId)->toBe($moon->getPlanetId());
});

// 9460 and 9461: the top club sent 123 and 157 raids at the solos beside it, against 142 and 180 at the alliance it
// fought. At war the fleet goes to the enemy; the others wait for a session with no enemy to hit.
test('at war an account raids its enemy first and the other targets wait', function (): void {
    raidDepthProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 40);
    $enemy = $this->createForeignPlanet();
    $enemy->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $solo = $this->createForeignPlanet();
    $solo->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $now = CarbonImmutable::create(2026, 9, 11, 8, 0, 0, 'UTC');
    $reports = [];
    foreach ([$enemy, $solo] as $planet) {
        $coordinates = $planet->getPlanetCoordinates();
        $reports[] = raidDepthReport($this->currentUserId, $coordinates->galaxy, $coordinates->system, $coordinates->position, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]);
    }
    $playerId = $this->currentUserId;
    $planetId = $this->currentPlanetId;
    $snapshot = static fn (array $ids): PerceptionSnapshot => app()->makeWith(PerceptionSnapshot::class, [
        'playerId' => $playerId,
        'observedAt' => $now,
        'planets' => [['id' => $planetId, 'resources' => ['metal' => 5_000, 'crystal' => 5_000, 'deuterium' => 5_000]]],
        'targetReports' => array_map(static fn (int $id): array => [
            'report_id' => $id, 'observed_at' => $now->getTimestamp(), 'expires_at' => $now->addHour()->getTimestamp(),
            'confidence' => 0.8, 'travel_cost' => 0.2, 'attack_permitted' => true, 'score_viable' => true,
        ], $ids),
        'availableActions' => array_fill_keys(array_map(static fn (AiCapability $capability): string => $capability->value, AiCapability::cases()), false),
        'fleetsaveEligible' => false,
        'recoveryFactor' => 0.1,
        'sourceTimestamps' => [],
        'fleetSlotsFree' => 2,
    ]);
    $raids = static fn ($generation): array => collect($generation->candidates)
        ->filter(static fn ($candidate): bool => $candidate->type === AiCandidateActionType::Raid)
        ->map(static fn ($candidate): int => $candidate->parameters['report_id'])->values()->all();

    expect($raids(app(CandidateActionFactory::class)->create($snapshot($reports))))->toBe($reports);

    AiRelationship::unguarded(fn () => AiRelationship::create([
        'player_id' => $this->currentUserId, 'other_player_id' => $enemy->getPlayer()?->getId(), 'threat' => '0.9000', 'trust' => '-0.5000',
        'affinity' => '0.0000', 'revision' => 0, 'last_interaction_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]));
    $war = app(CandidateActionFactory::class)->create($snapshot($reports));

    expect($raids($war))->toBe([$reports[0]])
        ->and($war->rejections)->toHaveKey('report:' . $reports[1], AiCandidateRejectionReason::EnemyPreferred->value);
});
