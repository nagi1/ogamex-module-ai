<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\AI\Actions\AppraiseObservedBattleReportAction;
use Modules\AI\Actions\CurrentAiAffectIntensityAction;
use Modules\AI\Actions\EvaluateAiSocialExchangeAction;
use Modules\AI\Actions\RecordAiMemoryFactAction;
use Modules\AI\Actions\RecordAiRelationshipInteractionAction;
use Modules\AI\Actions\RecordAiSocialExchangeAction;
use Modules\AI\Actions\RecordObservedBattleReportAction;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\MemoryRecallQuery;
use Modules\AI\Domain\Conversation\NativeSocialCognition;
use Modules\AI\Domain\Decision\BuildCandidate;
use Modules\AI\Domain\Decision\EconomyUpgrades;
use Modules\AI\Domain\Experience\NativeExperienceEngine;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiMemoryDriver;
use Modules\AI\Enums\AiMemoryEvidenceKind;
use Modules\AI\Enums\AiMemoryPredicate;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Enums\AiSocialTerm;
use Modules\AI\Models\AiEmotionalEpisode;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AffectEngineSelector;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\LongTermMemorySelector;
use Modules\AI\Support\SystemAiClock;
use OGame\Models\BattleReport;
use OGame\Models\ChatMessage;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

const ABLATION_NOW = '2026-09-11 12:00:00 UTC';

/**
 * The module clock is the system clock, so a scenario that must decay affect has to happen at
 * the real present rather than at a fixed past instant.
 */
function ablationNow(): CarbonImmutable
{
    return CarbonImmutable::now()->startOfSecond();
}

/**
 * 3J configuration harness: one fixture, several configurations, measured side by side.
 *
 * The point of comparing A–G is attribution, so every configuration below runs the *same*
 * scenario and differs only in a switch. A capability that has no switch cannot be compared,
 * and that absence is recorded in the plan rather than filled in with a guess:
 *
 * | Configuration | Switch | Covered here |
 * | --- | --- | --- |
 * | A reduced baseline | `affect.enrichment=false`, `experience.decision_weight=0` | yes |
 * | B native affect | `affect.enrichment=true` | yes |
 * | C native structured CBR | `experience.decision_weight` | yes (cold start and zero weight) |
 * | D advanced recall | `memory.driver` | yes |
 * | E bounded theory of mind | none exists | **not runnable** |
 * | F semantic retrieval | none exists (would need an embedder) | **not runnable** |
 * | G language provider | `language.enabled` | provider **off** is the baseline; on needs credentials |
 *
 * A driver *substitution* is a different comparison from enabling or removing a capability,
 * and is covered by its own suites so the two results are never reported as one.
 */
beforeEach(function (): void {
    config([
        'ai.cognition.driver' => 'native',
        'ai.cognition.affect.enrichment' => true,
        'ai.cognition.experience.decision_weight' => 20,
        'ai.cognition.memory.driver' => AiMemoryDriver::Native->value,
    ]);
    $this->app->bind(AiClock::class, SystemAiClock::class);
    $this->app->bind(AffectEngine::class, fn (): AffectEngine => app(AffectEngineSelector::class)->resolve());
    $this->app->bind(SocialCognition::class, NativeSocialCognition::class);
    $this->app->bind(ExperienceEngine::class, NativeExperienceEngine::class);
    app()->bind(LongTermMemory::class, fn (): LongTermMemory => app(LongTermMemorySelector::class)->resolve());
});

/** @return array<string, mixed> */
function ablationSide(int $playerId, float $resourceLoss): array
{
    return ['player_id' => $playerId, 'resource_loss' => $resourceLoss];
}

function ablationBattleReport(int $defenderPlayerId, int $attackerPlayerId): BattleReport
{
    return BattleReport::withoutEvents(fn (): BattleReport => BattleReport::unguarded(
        fn (): BattleReport => BattleReport::create([
            'planet_galaxy' => 1,
            'planet_system' => 1,
            'planet_position' => 1,
            'planet_user_id' => $defenderPlayerId,
            'attacker' => ablationSide($attackerPlayerId, 0.0),
            'defender' => ablationSide($defenderPlayerId, 400.0),
        ]),
    ));
}

/**
 * One scenario, run identically under every configuration: a trusted counterparty is also the
 * one who just hit this AI, and then apologises. The only difference between configurations is
 * whether the harm was allowed to become an emotion.
 *
 * @return array{episodes:int,anger:float,response:AiSocialResponse|null,trust:float}
 */
function runAblationScenario(int $ai, int $counterpartyPlayerId): array
{
    $now = ablationNow();
    AiProfile::create(['player_id' => $ai, 'archetype' => AiArchetype::Fleeter, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 42, 'enabled' => true]);

    $report = ablationBattleReport($ai, $counterpartyPlayerId);
    $observationCount = app(RecordObservedBattleReportAction::class)->handle($report->id);

    $observation = AiObservation::query()
        ->where('player_id', $ai)
        ->where('kind', AiObservationKind::BattleReportObserved)
        ->firstOrFail();

    // Earned standing: a trusted counterparty, so the apology would be accepted if nothing else
    // weighed against it.
    app(RecordAiRelationshipInteractionAction::class)->handle(
        $ai,
        $counterpartyPlayerId,
        $observation->id,
        $now,
        0.6,
    );
    app(AppraiseObservedBattleReportAction::class)->handle($observation->id);

    $message = ChatMessage::create(['sender_id' => $counterpartyPlayerId, 'recipient_id' => $ai, 'message' => 'sorry about the hit']);
    // An enabled module's committed-message observer records this row itself, so the fixture
    // converges on the unique source identity rather than inserting a second one.
    $chat = AiObservation::query()->firstOrCreate([
        'player_id' => $ai,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => $message->id,
    ], [
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => $counterpartyPlayerId,
        'source_time' => $now,
        'observed_at' => $now,
    ]);
    $exchange = app(RecordAiSocialExchangeAction::class)->handle(
        $ai,
        $counterpartyPlayerId,
        $chat->id,
        AiSocialExchangeType::Apology,
        [AiSocialTerm::AcknowledgesHarm->value => true],
    );
    $evaluated = app(EvaluateAiSocialExchangeAction::class)->handle($exchange?->id ?? 0, 0, $now);

    expect($observationCount)->toBeGreaterThan(0);

    return [
        'episodes' => AiEmotionalEpisode::query()->where('player_id', $ai)->count(),
        'anger' => app(CurrentAiAffectIntensityAction::class)->handle($ai, AiAffectEmotion::Anger, $now),
        'response' => $evaluated?->response,
        'trust' => 0.6,
    ];
}

test('configuration a keeps the reduced baseline: the harm is not felt and earned standing decides', function (): void {
    config(['ai.cognition.affect.enrichment' => false, 'ai.cognition.experience.decision_weight' => 0]);

    $counterparty = $this->createUser();
    $result = runAblationScenario($this->currentUserId, $counterparty->id);

    expect($result['episodes'])->toBe(0)
        ->and($result['anger'])->toBe(0.0)
        ->and($result['response'])->toBe(AiSocialResponse::Accept);
});

test('configuration b feels the same harm and the same apology now needs compensation', function (): void {
    $counterparty = $this->createUser();
    $result = runAblationScenario($this->currentUserId, $counterparty->id);

    expect($result['episodes'])->toBe(1)
        ->and($result['anger'])->toBeGreaterThan(0.0)
        ->and($result['response'])->toBe(AiSocialResponse::Counter);
});

test('configuration c leaves the economy order untouched when the enrichment is zero', function (): void {
    $profile = AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 42, 'enabled' => true]);
    $upgrades = app(EconomyUpgrades::class);
    $order = fn (): array => array_map(
        static fn (BuildCandidate $candidate): int => $candidate->buildingId,
        $upgrades->pending($this->planetService, $profile),
    );

    // Weight zero is the ablation switch: no remembered outcome may move the order, and with no
    // evidence at all the enriched rule must also agree with itself exactly.
    config(['ai.cognition.experience.decision_weight' => 0]);
    $withoutEvidence = $order();

    config(['ai.cognition.experience.decision_weight' => 20]);

    expect($withoutEvidence)->toBe($order());
});

test('configuration d reorders recall only when the recall driver is selected', function (): void {
    $now = ablationNow();
    $subject = $this->createUser();
    $first = app(RecordAiMemoryFactAction::class)->handle($this->currentUserId, $subject->id, AiMemoryPredicate::AllianceMembership, AiMemoryEvidenceKind::Claimed, ['alliance_tag' => 'RAVEN'], 9001, $now);
    $second = app(RecordAiMemoryFactAction::class)->handle($this->currentUserId, $subject->id, AiMemoryPredicate::ResourceDebt, AiMemoryEvidenceKind::Claimed, ['amount' => 200], 9002, $now->subHour());

    $recall = fn (): array => app(LongTermMemory::class)->recallRelevantMemories(app()->makeWith(MemoryRecallQuery::class, [
        'playerId' => $this->currentUserId,
        'subjectPlayerId' => $subject->id,
        'now' => $now,
        'queryText' => 'debt',
    ]));

    $baseline = array_column($recall(), 'id');

    Http::fake(['*' => Http::response(['ranking' => [['id' => $second->id], ['id' => $first->id]]], 200)]);
    config(['ai.cognition.memory.driver' => AiMemoryDriver::AgentOs->value]);
    $driven = array_column($recall(), 'id');

    expect($baseline)->toBe([$first->id, $second->id])
        ->and($driven)->toBe([$second->id, $first->id]);
});
