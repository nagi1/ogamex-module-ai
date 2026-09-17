<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\AdvanceAiCampaignStateAction;
use Modules\AI\Actions\ConsultCampaignDecisionAction;
use Modules\AI\Actions\DeclareAiCampaignObjectiveAction;
use Modules\AI\Actions\OpenAiCampaignAction;
use Modules\AI\Actions\RecordAiScoreSamplesAction;
use Modules\AI\Actions\RecordObservedBattleReportAction;
use Modules\AI\Actions\ResolveAiCampaignObjectiveFromBattleReportAction;
use Modules\AI\Contracts\CampaignConsultationGateway;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRecommendation;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRequest;
use Modules\AI\Domain\Decision\CandidateAction;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCampaignConsultationRisk;
use Modules\AI\Enums\AiCampaignConsultationStatus;
use Modules\AI\Enums\AiCampaignConsultationTrigger;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiCampaignConsultationReceipt;
use Modules\AI\Models\AiCampaignConsultationSignal;
use Modules\AI\Models\AiCampaignObjective;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\FixtureAiClock;
use OGame\Models\BattleReport;
use OGame\Models\Highscore;
use OGame\Models\Planet;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/FixtureAiClock.php';

uses(IsolatedAccountTestCase::class);

/**
 * The campaign consultation lane, wired into its callers: a material campaign event records one
 * signal, and the next session decision consumes it once — the lane's own admission decides
 * whether anything happens, so `off` leaves the native decision untouched and no provider is
 * ever contacted.
 */
function wiringActiveCampaign(): AiCampaign
{
    $campaign = app(OpenAiCampaignAction::class)->handle(now()->subHour()->toImmutable(), now()->addDay()->toImmutable());
    app(AdvanceAiCampaignStateAction::class)->handle();

    return $campaign->refresh();
}

/** @param array<int, array<string, mixed>> $rounds */
function wiringBattleReport(int $defenderPlayerId, int $attackerPlayerId, array $rounds): BattleReport
{
    return BattleReport::withoutEvents(
        fn (): BattleReport => BattleReport::unguarded(fn (): BattleReport => BattleReport::create([
            'planet_galaxy' => 1,
            'planet_system' => 1,
            'planet_position' => 1,
            'planet_type' => 1,
            'planet_user_id' => $defenderPlayerId,
            'attacker' => ['player_id' => $attackerPlayerId],
            'defender' => ['player_id' => $defenderPlayerId],
            'rounds' => $rounds,
        ])),
    );
}

function wiringProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 1,
        'enabled' => true,
    ]);
}

function wiringCandidate(AiCandidateActionType $type, float $score): ScoredCandidate
{
    $candidate = app()->makeWith(CandidateAction::class, [
        'type' => $type,
        'reason' => 'fixture',
        'parameters' => [],
        'features' => ['resource_need' => 0.0, 'safety' => 0.0, 'target_confidence' => 0.0, 'travel_cost' => 0.0, 'recovery' => 0.0],
        'sourceTimestamps' => [],
    ]);

    return app()->makeWith(ScoredCandidate::class, ['candidate' => $candidate, 'score' => $score, 'components' => []]);
}

/** @param array<int, ScoredCandidate> $scored */
function wiringTrace(int $playerId, array $scored): DecisionTrace
{
    return app()->makeWith(DecisionTrace::class, [
        'perception' => app()->makeWith(PerceptionSnapshot::class, [
            'playerId' => $playerId,
            'observedAt' => CarbonImmutable::instance(now()),
            'planets' => [],
            'targetReports' => [],
            'availableActions' => [],
            'fleetsaveEligible' => false,
            'recoveryFactor' => 0.0,
            'sourceTimestamps' => [],
            'inboundFleets' => [],
        ]),
        'candidates' => $scored,
        'selected' => $scored[0],
        'rejections' => [],
        'inputHash' => 'wiring-fixture',
    ]);
}

function wiringCompleted(int $candidateId): CampaignConsultationRecommendation
{
    return new CampaignConsultationRecommendation(
        AiCampaignConsultationStatus::Completed,
        $candidateId,
        AiCampaignConsultationRisk::Low,
        'a reason',
        [],
        10,
        5,
        'inv-1',
        'openai',
        'gpt-5-mini',
    );
}

function wiringGateway(CampaignConsultationRecommendation $recommendation): CampaignConsultationGateway
{
    return new class ($recommendation) implements CampaignConsultationGateway {
        public function __construct(private readonly CampaignConsultationRecommendation $recommendation)
        {
        }

        public function recommend(CampaignConsultationRequest $request): CampaignConsultationRecommendation
        {
            return $this->recommendation;
        }
    };
}

test('a campaign entering its window records a new-phase signal', function (): void {
    $campaign = wiringActiveCampaign();

    expect(AiCampaignConsultationSignal::query()
        ->where('campaign_id', $campaign->id)
        ->where('trigger', AiCampaignConsultationTrigger::NewPhase)
        ->whereNull('consumed_at')
        ->exists())->toBeTrue();
});

test('a battle at a standing stronghold that did not fall records a contested-objective signal', function (): void {
    $planet = Planet::query()->find($this->createForeignPlanet()->getPlanetId());
    $campaign = wiringActiveCampaign();
    app(DeclareAiCampaignObjectiveAction::class)->handle($campaign->id, $planet->id);

    $report = BattleReport::withoutEvents(fn (): BattleReport => BattleReport::unguarded(fn (): BattleReport => BattleReport::create([
        'planet_galaxy' => $planet->galaxy,
        'planet_system' => $planet->system,
        'planet_position' => $planet->planet,
        'planet_type' => $planet->planet_type,
        'planet_user_id' => $planet->user_id,
        'rounds' => [['attacker_ships' => [], 'defender_ships' => ['light_fighter' => 2]]],
    ])));

    expect(app(ResolveAiCampaignObjectiveFromBattleReportAction::class)->handle($report->id))->toBe(0)
        ->and(AiCampaignObjective::query()->sole()->completed_at)->toBeNull()
        ->and(AiCampaignConsultationSignal::query()
            ->where('campaign_id', $campaign->id)
            ->where('trigger', AiCampaignConsultationTrigger::ContestedObjective)
            ->exists())->toBeTrue();
});

test('an off lane consumes the signal and leaves the native decision untouched', function (): void {
    $campaign = wiringActiveCampaign();
    $profile = wiringProfile($this->currentUserId);
    app()->bind(CampaignConsultationGateway::class, function (): never {
        throw new LogicException('The gateway must not be resolved when the lane is off.');
    });

    $trace = app(ConsultCampaignDecisionAction::class)->handle(
        $profile,
        wiringTrace($this->currentUserId, [wiringCandidate(AiCandidateActionType::Research, 2.0), wiringCandidate(AiCandidateActionType::Build, 1.0)]),
        'wiring-key',
    );

    expect($trace->selected->candidate->type)->toBe(AiCandidateActionType::Research)
        ->and(AiCampaignConsultationSignal::query()->where('campaign_id', $campaign->id)->whereNull('consumed_at')->exists())->toBeFalse()
        ->and(AiCampaignConsultationReceipt::query()->count())->toBe(0);
});

test('an advice lane nudges the recommended candidate and consumes the signal', function (): void {
    config(['ai.campaign-consultation.mode' => 'advice']);
    $campaign = wiringActiveCampaign();
    $profile = wiringProfile($this->currentUserId);
    app()->bind(CampaignConsultationGateway::class, fn (): CampaignConsultationGateway => wiringGateway(wiringCompleted(AiCandidateActionType::Build->value)));

    $trace = app(ConsultCampaignDecisionAction::class)->handle(
        $profile,
        wiringTrace($this->currentUserId, [wiringCandidate(AiCandidateActionType::Research, 2.0), wiringCandidate(AiCandidateActionType::Build, 1.0)]),
        'wiring-key',
    );

    $build = collect($trace->candidates)->first(fn (ScoredCandidate $candidate): bool => $candidate->candidate->type === AiCandidateActionType::Build);

    expect($build->score)->toBe(1.0 + AiSkillBand::Standard->selectionMargin())
        ->and(AiCampaignConsultationSignal::query()->where('campaign_id', $campaign->id)->whereNull('consumed_at')->exists())->toBeFalse()
        ->and(AiCampaignConsultationReceipt::query()->sole()->changed_ranking)->toBeTrue();
});

test('a battle between two coalition members records a coalition-conflict signal', function (): void {
    config(['ai.cognition.affect.enrichment' => false]);
    $campaign = wiringActiveCampaign();
    $coalitionA = $this->createUser()->id;
    $coalitionB = $this->createUser()->id;

    $report = wiringBattleReport($coalitionA, $coalitionB, [['attacker_ships' => ['light_fighter' => 5], 'defender_ships' => []]]);

    app(RecordObservedBattleReportAction::class)->handle($report->id);

    expect(AiCampaignConsultationSignal::query()
        ->where('campaign_id', $campaign->id)
        ->where('trigger', AiCampaignConsultationTrigger::CoalitionConflict)
        ->whereNull('consumed_at')
        ->exists())->toBeTrue();
});

test('a faction fleet wiped out records a fleet-loss signal', function (): void {
    config(['ai.cognition.affect.enrichment' => false]);
    $campaign = wiringActiveCampaign();
    $factionPlayerId = $this->createUser()->id;
    $attackerPlayerId = $this->createUser()->id;
    wiringProfile($factionPlayerId);

    $report = wiringBattleReport($factionPlayerId, $attackerPlayerId, [['attacker_ships' => ['light_fighter' => 5], 'defender_ships' => []]]);

    app(RecordObservedBattleReportAction::class)->handle($report->id);

    expect(AiCampaignConsultationSignal::query()
        ->where('campaign_id', $campaign->id)
        ->where('trigger', AiCampaignConsultationTrigger::FleetLoss)
        ->whereNull('consumed_at')
        ->exists())->toBeTrue();
});

test('a second faction fleet loss records a repeated-setback signal', function (): void {
    config(['ai.cognition.affect.enrichment' => false]);
    $campaign = wiringActiveCampaign();
    $factionPlayerId = $this->createUser()->id;
    $attackerPlayerId = $this->createUser()->id;
    wiringProfile($factionPlayerId);

    $loss = fn (): BattleReport => wiringBattleReport($factionPlayerId, $attackerPlayerId, [['attacker_ships' => ['light_fighter' => 5], 'defender_ships' => []]]);

    app(RecordObservedBattleReportAction::class)->handle($loss()->id);

    // A session decision consumes the first signal before the next defeat lands.
    AiCampaignConsultationSignal::query()
        ->where('campaign_id', $campaign->id)
        ->where('trigger', AiCampaignConsultationTrigger::FleetLoss)
        ->update(['consumed_at' => now()]);

    app(RecordObservedBattleReportAction::class)->handle($loss()->id);

    expect(AiCampaignConsultationSignal::query()
        ->where('campaign_id', $campaign->id)
        ->where('trigger', AiCampaignConsultationTrigger::RepeatedSetback)
        ->whereNull('consumed_at')
        ->exists())->toBeTrue()
        ->and(AiCampaignConsultationSignal::query()
            ->where('campaign_id', $campaign->id)
            ->where('trigger', AiCampaignConsultationTrigger::FleetLoss)
            ->count())->toBe(2);
});

test('a rank that moves between samples records a rank-change signal', function (): void {
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse('2024-01-01 12:00:00'),
    ]));

    $campaign = wiringActiveCampaign();
    $factionPlayerId = $this->createUser()->id;
    wiringProfile($factionPlayerId);
    Highscore::updateOrCreate(['player_id' => $factionPlayerId], ['general' => 100, 'general_rank' => 5]);

    app(RecordAiScoreSamplesAction::class)->handle();

    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse('2024-01-01 13:00:00'),
    ]));
    Highscore::query()->where('player_id', $factionPlayerId)->update(['general_rank' => 3]);

    app(RecordAiScoreSamplesAction::class)->handle();

    expect(AiCampaignConsultationSignal::query()
        ->where('campaign_id', $campaign->id)
        ->where('trigger', AiCampaignConsultationTrigger::RankChange)
        ->whereNull('consumed_at')
        ->exists())->toBeTrue();
});
