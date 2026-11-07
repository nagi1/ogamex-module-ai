<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Modules\AI\Actions\AdvanceAiAlliancePoliticsAction;
use Modules\AI\Actions\BuildAiSettingsPanelAction;
use Modules\AI\Actions\ConsultCampaignDecisionAction;
use Modules\AI\Actions\RunAiConversationCycleAction;
use Modules\AI\Ai\Agents\OgameMessageAgent;
use Modules\AI\Domain\Advisor\Advisor;
use Modules\AI\Domain\Advisor\AdvisorAgent;
use Modules\AI\Domain\Advisor\AdvisorArea;
use Modules\AI\Domain\Advisor\AdvisorQuestion;
use Modules\AI\Domain\Advisor\AdvisorReceipt;
use Modules\AI\Domain\Decision\CandidateAction;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiUsageReservationState;
use Modules\AI\Models\AiAllianceRelation;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Models\AiUsageReservation;
use Modules\AI\Support\RandomSource;
use OGame\Models\BattleReport;
use OGame\Models\ChatMessage;
use OGame\Models\EspionageReport;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Services\AllianceService;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// The advisor only chooses among the rules' legal options; off, a cap, a failure or an invalid answer keeps the rule's
// choice, observe records without applying, advice applies. Its brief holds only what the account can see.

beforeEach(function (): void {
    config(['ai.classification.enabled' => false, 'ai.language.compose' => false]);
    Queue::fake();
    Cache::flush();
});

function advisorMode(AdvisorArea $area, string $mode): void
{
    app(SettingsService::class)->set('ai_advisor_' . $area->value . '_mode', $mode);
}

function advisorAnswer(string $choice, array $evidence = [], string $text = ''): array
{
    return ['choice' => $choice, 'reason' => 'they broke a pact before', 'evidence_ids' => $evidence, 'text' => $text];
}

function advisorQuestion(int $playerId, array|null $speech = null): AdvisorQuestion
{
    return new AdvisorQuestion(AdvisorArea::Diplomacy, $playerId, 'pact_offer', [
        ['id' => 'accept', 'summary' => 'sign the pact'],
        ['id' => 'decline', 'summary' => 'refuse the pact'],
    ], 'accept', ['them' => ['tag' => 'HUM']], [['kind' => 'own_spy_report', 'age_hours' => 3, 'value' => ['ships' => 10]]], $speech);
}

function advisorProfile(int $playerId): AiProfile
{
    return AiProfile::create(['player_id' => $playerId, 'archetype' => AiArchetype::Fleeter, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 1, 'enabled' => true]);
}

test('every area is off by default, never prompts, and the rule choice stands', function (): void {
    AdvisorAgent::fake()->preventStrayPrompts();

    foreach (AdvisorArea::cases() as $area) {
        expect(app(Advisor::class)->enabled($area))->toBeFalse();
    }

    expect(app(Advisor::class)->consult(advisorQuestion($this->currentUserId))->choice)->toBe('accept')
        ->and(AdvisorReceipt::query()->count())->toBe(0);
    AdvisorAgent::assertNeverPrompted();
});

test('observe records the advice beside the rule choice and applies nothing; advice applies it', function (string $mode, string $acted, bool $applied): void {
    advisorMode(AdvisorArea::Diplomacy, $mode);
    AdvisorAgent::fake([advisorAnswer('decline', [1])]);

    $verdict = app(Advisor::class)->consult(advisorQuestion($this->currentUserId));
    $receipt = AdvisorReceipt::query()->sole();

    expect($verdict->choice)->toBe($acted)
        ->and($verdict->advice)->toBe('decline')
        ->and($receipt->rule_choice)->toBe('accept')
        ->and($receipt->advice)->toBe('decline')
        ->and($receipt->status)->toBe('completed')
        ->and($receipt->applied)->toBe($applied)
        ->and($receipt->evidence_ids)->toBe([1])
        ->and(AiUsageReservation::query()->whereKey($receipt->usage_reservation_id)->value('state'))->toBe(AiUsageReservationState::Settled);
})->with([
    'observe' => ['observe', 'accept', false],
    'advice' => ['advice', 'decline', true],
]);

test('an answer outside the options or the evidence, or a failing provider, keeps the rule choice', function (string|array $answer, string $status): void {
    advisorMode(AdvisorArea::Diplomacy, 'advice');
    AdvisorAgent::fake([is_string($answer) ? fn () => throw new RuntimeException($answer) : $answer]);

    expect(app(Advisor::class)->consult(advisorQuestion($this->currentUserId))->choice)->toBe('accept')
        ->and(AdvisorReceipt::query()->sole()->status)->toBe($status);
})->with([
    'an invented action' => [advisorAnswer('attack_their_moon'), 'invalid'],
    'unknown evidence' => [advisorAnswer('decline', [7]), 'invalid'],
    'a provider failure' => ['Provider unavailable.', 'failed'],
    'a timeout' => ['The request timed out.', 'timed_out'],
]);

test('"none" keeps the rule choice', function (): void {
    advisorMode(AdvisorArea::Diplomacy, 'advice');
    AdvisorAgent::fake([advisorAnswer('none')]);

    expect(app(Advisor::class)->consult(advisorQuestion($this->currentUserId))->choice)->toBe('accept');
});

test('the per-account daily cap stops the call before any prompt', function (): void {
    advisorMode(AdvisorArea::Diplomacy, 'advice');
    config(['ai.campaign-consultation.advisor.daily_limits.account.attempts' => 0]);
    AdvisorAgent::fake()->preventStrayPrompts();

    expect(app(Advisor::class)->consult(advisorQuestion($this->currentUserId))->choice)->toBe('accept')
        ->and(AdvisorReceipt::query()->sole()->status)->toBe('capped');
    AdvisorAgent::assertNeverPrompted();
});

test('a written line is used only when it keeps every fact', function (string $text, string|null $kept): void {
    advisorMode(AdvisorArea::Diplomacy, 'advice');
    AdvisorAgent::fake([advisorAnswer('decline', [], $text)]);

    $verdict = app(Advisor::class)->consult(advisorQuestion($this->currentUserId, ['authored' => 'No pact with [HUM].', 'facts' => ['[HUM]'], 'language' => 'de']));

    expect($verdict->text)->toBe($kept);
})->with([
    'keeps the tag' => ['Kein Pakt mit [HUM].', 'Kein Pakt mit [HUM].'],
    'drops the tag' => ['Kein Pakt.', null],
]);

test('the costly gate consults only an action worth the configured share of the account', function (): void {
    expect(app(Advisor::class)->shouldConsult(900.0, 1000.0))->toBeFalse();

    advisorMode(AdvisorArea::Costly, 'observe');
    config(['ai.campaign-consultation.advisor.costly_share' => 0.3]);

    expect(app(Advisor::class)->shouldConsult(400.0, 1000.0))->toBeTrue()
        ->and(app(Advisor::class)->shouldConsult(200.0, 1000.0))->toBeFalse();
});

test('the settings panel shows the four advisor modes, off by default', function (): void {
    advisorMode(AdvisorArea::Conversation, 'advice');
    $rows = collect(app(BuildAiSettingsPanelAction::class)->handle()['live'])->keyBy('field');

    expect($rows['advisor_diplomacy_mode']['value'])->toBe('off')
        ->and($rows['advisor_conversation_mode']['value'])->toBe('advice')
        ->and($rows['advisor_offense_mode']['group'])->toBe('advisor')
        ->and($rows['advisor_costly_mode']['options'])->toBe(['off', 'observe', 'advice']);
});

// Area 2: a pact offer. The rule takes it; the advisor in advice mode may refuse it.

test('diplomacy advice can refuse a pact offer the rule would take', function (string $mode, bool $pact): void {
    advisorMode(AdvisorArea::Diplomacy, $mode);
    advisorProfile($this->currentUserId);
    $ours = app(AllianceService::class)->createAlliance($this->currentUserId, 'OURS', 'Ours');
    $human = $this->createUser();
    $theirs = app(AllianceService::class)->createAlliance($human->id, 'HUM', 'Humans');
    AdvisorAgent::fake([advisorAnswer('decline')]);
    OgameMessageAgent::fake([['text' => 'no pact with HUM']]);

    app(AdvanceAiAlliancePoliticsAction::class)->answerHumanLeader($human->id, $this->currentUserId, 'pact_offer');

    expect(in_array($theirs->id, AiAllianceRelation::counterparts($ours->id, AiAllianceStance::Nap), true))->toBe($pact)
        ->and(AdvisorReceipt::query()->sole()->moment)->toBe('pact_offer');
})->with([
    'observe' => ['observe', true],
    'advice' => ['advice', false],
]);

test('the diplomacy brief is byte-identical when only the other side\'s hidden state changes', function (): void {
    advisorMode(AdvisorArea::Diplomacy, 'observe');
    advisorProfile($this->currentUserId);
    app(AllianceService::class)->createAlliance($this->currentUserId, 'OURS', 'Ours');
    $human = $this->createUser();
    app(AllianceService::class)->createAlliance($human->id, 'HUM', 'Humans');
    $briefs = [];
    AdvisorAgent::fake(function ($prompt) use (&$briefs): array {
        $briefs[] = $prompt;

        return advisorAnswer('decline');
    });

    app(AdvanceAiAlliancePoliticsAction::class)->answerHumanLeader($human->id, $this->currentUserId, 'pact_offer');
    AiAllianceRelation::query()->delete();
    Cache::flush();
    hideOtherSideState($human->id, $this->currentUserId);
    app(AdvanceAiAlliancePoliticsAction::class)->answerHumanLeader($human->id, $this->currentUserId, 'pact_offer');

    expect($briefs)->toHaveCount(2)
        ->and($briefs[1])->toBe($briefs[0]);
});

// Area 3: a post-battle comment. The rule comments; the advisor may stay silent or word it.

function observedAttack(int $defenderId, int $attackerId): void
{
    $report = BattleReport::unguarded(fn (): BattleReport => BattleReport::create([
        'planet_galaxy' => 1, 'planet_system' => 1, 'planet_position' => 1, 'planet_user_id' => $defenderId,
        'attacker' => ['player_id' => $attackerId], 'defender' => ['player_id' => $defenderId],
    ]));
    AiObservation::query()->firstOrCreate(
        ['player_id' => $defenderId, 'source_type' => AiObservationSource::BattleReport, 'source_id' => $report->id],
        ['kind' => AiObservationKind::BattleReportObserved, 'subject_player_id' => $attackerId, 'source_time' => CarbonImmutable::now(), 'observed_at' => CarbonImmutable::now()],
    );
}

test('conversation advice can stay silent after a battle or word the comment', function (array $answer, string|null $said): void {
    advisorMode(AdvisorArea::Conversation, 'advice');
    advisorProfile($this->currentUserId);
    $attacker = $this->createUser();
    observedAttack($this->currentUserId, $attacker->id);
    AdvisorAgent::fake([$answer]);

    app(RunAiConversationCycleAction::class)->handle($this->currentUserId, CarbonImmutable::now());

    expect(ChatMessage::query()->where('sender_id', $this->currentUserId)->value('message'))->toBe($said)
        ->and(AdvisorReceipt::query()->sole()->moment)->toBe('post_battle');
})->with([
    'silent' => [advisorAnswer('silent'), null],
    'worded' => [advisorAnswer('reply', [], 'nice hit, enjoy it while it lasts'), 'nice hit, enjoy it while it lasts'],
]);

test('the conversation brief is byte-identical when only the attacker\'s hidden state changes', function (): void {
    advisorMode(AdvisorArea::Conversation, 'observe');
    // The authored line is picked by exchange id; a fixed pick keeps the two runs comparable.
    $this->app->instance(RandomSource::class, new class () implements RandomSource {
        public function unitInterval(int $seed, string $context): float
        {
            return 0.0;
        }
    });
    advisorProfile($this->currentUserId);
    $attacker = $this->createUser();
    observedAttack($this->currentUserId, $attacker->id);
    $briefs = [];
    AdvisorAgent::fake(function ($prompt) use (&$briefs): array {
        $briefs[] = $prompt;

        return advisorAnswer('reply');
    });

    app(RunAiConversationCycleAction::class)->handle($this->currentUserId, CarbonImmutable::now());
    AiSocialExchange::query()->delete();
    ChatMessage::query()->where('sender_id', $this->currentUserId)->delete();
    Cache::flush();
    hideOtherSideState($attacker->id, $this->currentUserId);
    app(RunAiConversationCycleAction::class)->handle($this->currentUserId, CarbonImmutable::now());

    expect($briefs)->toHaveCount(2)
        ->and($briefs[1])->toBe($briefs[0]);
});

/** What the account cannot see: the other player's stock and fleet, and a spy report only the other player received. */
function hideOtherSideState(int $otherId, int $selfId): void
{
    Planet::query()->where('user_id', $otherId)->update(['metal' => 9_999_999, 'crystal' => 7_777_777, 'light_fighter' => 5_000]);
    $report = EspionageReport::unguarded(fn (): EspionageReport => EspionageReport::create([
        'planet_galaxy' => 1, 'planet_system' => 1, 'planet_position' => 1, 'planet_user_id' => $selfId, 'planet_type' => 1,
        'resources' => ['metal' => 1], 'debris' => [], 'buildings' => [], 'research' => [], 'ships' => [], 'defense' => [], 'player_info' => [],
    ]));
    Message::unguarded(fn () => Message::create(['user_id' => $otherId, 'key' => 'espionage_report', 'espionage_report_id' => $report->id, 'subject' => '', 'body' => '']));
}

// Area 1: a defended target among the session's raid candidates. The advisor picks a target or holds.

function offenseReport(int $selfId, int $ownerId, array $ships, int $position): int
{
    $report = EspionageReport::unguarded(fn (): EspionageReport => EspionageReport::create([
        'planet_galaxy' => 1, 'planet_system' => 2, 'planet_position' => $position, 'planet_user_id' => $ownerId, 'planet_type' => 1,
        'resources' => ['metal' => 50_000, 'crystal' => 20_000, 'deuterium' => 0], 'debris' => [], 'buildings' => [], 'research' => [],
        'ships' => $ships, 'defense' => [], 'player_info' => [],
    ]));
    Message::unguarded(fn () => Message::create(['user_id' => $selfId, 'key' => 'espionage_report', 'espionage_report_id' => $report->id, 'subject' => '', 'body' => '']));

    return (int) $report->id;
}

function offenseCandidate(AiCandidateActionType $type, float $score, array $parameters = []): ScoredCandidate
{
    return new ScoredCandidate(new CandidateAction($type, 'fixture', $parameters, ['resource_need' => 0.0, 'safety' => 0.0, 'target_confidence' => 0.0, 'travel_cost' => 0.0, 'recovery' => 0.0], []), $score, []);
}

function offenseTrace(int $playerId, array $scored): DecisionTrace
{
    return new DecisionTrace(app()->makeWith(PerceptionSnapshot::class, [
        'playerId' => $playerId, 'observedAt' => CarbonImmutable::instance(now()), 'planets' => [], 'targetReports' => [], 'availableActions' => [],
        'fleetsaveEligible' => false, 'recoveryFactor' => 0.0, 'sourceTimestamps' => [], 'inboundFleets' => [],
    ]), $scored, $scored[0], [], 'offense-fixture');
}

function offenseDecision(int $playerId, int $targetId, array $ships): DecisionTrace
{
    $raid = offenseCandidate(AiCandidateActionType::Raid, 9.0, ['report_id' => offenseReport($playerId, $targetId, $ships, 8)]);

    return app(ConsultCampaignDecisionAction::class)->handle(advisorProfile($playerId), offenseTrace($playerId, [$raid, offenseCandidate(AiCandidateActionType::Build, 5.0)]), 'offense');
}

test('offense advice can hold back from a defended target; observe and off keep the raid', function (string $mode, AiCandidateActionType $selected, int $receipts): void {
    advisorMode(AdvisorArea::Offense, $mode);
    $target = $this->createUser();
    AdvisorAgent::fake([advisorAnswer('hold')]);

    expect(offenseDecision($this->currentUserId, $target->id, ['light_fighter' => 40])->selected->candidate->type)->toBe($selected)
        ->and(AdvisorReceipt::query()->count())->toBe($receipts);
})->with([
    'advice' => ['advice', AiCandidateActionType::Build, 1],
    'observe' => ['observe', AiCandidateActionType::Raid, 1],
    'off' => ['off', AiCandidateActionType::Raid, 0],
]);

test('an undefended farm is no big moment and is not consulted', function (): void {
    advisorMode(AdvisorArea::Offense, 'advice');
    $target = $this->createUser();
    AdvisorAgent::fake()->preventStrayPrompts();

    expect(offenseDecision($this->currentUserId, $target->id, [])->selected->candidate->type)->toBe(AiCandidateActionType::Raid);
    AdvisorAgent::assertNeverPrompted();
});

test('the offense brief is byte-identical when only the target\'s hidden state changes', function (): void {
    advisorMode(AdvisorArea::Offense, 'observe');
    $target = $this->createUser();
    $briefs = [];
    AdvisorAgent::fake(function ($prompt) use (&$briefs): array {
        $briefs[] = $prompt;

        return advisorAnswer('hold');
    });
    $raid = offenseCandidate(AiCandidateActionType::Raid, 9.0, ['report_id' => offenseReport($this->currentUserId, $target->id, ['cruiser' => 10], 8)]);
    $trace = offenseTrace($this->currentUserId, [$raid, offenseCandidate(AiCandidateActionType::Build, 5.0)]);
    $profile = advisorProfile($this->currentUserId);

    app(ConsultCampaignDecisionAction::class)->handle($profile, $trace, 'offense');
    Cache::flush();
    hideOtherSideState($target->id, $this->currentUserId);
    app(ConsultCampaignDecisionAction::class)->handle($profile, $trace, 'offense');

    expect($briefs)->toHaveCount(2)
        ->and($briefs[1])->toBe($briefs[0]);
});
