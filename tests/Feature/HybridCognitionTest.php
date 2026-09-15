<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Cognition\AffectAppraisal;
use Modules\AI\Domain\Cognition\NativeAffectEngine;
use Modules\AI\Domain\Cognition\ObservedStimulus;
use Modules\AI\Domain\Conversation\NativeSocialCognition;
use Modules\AI\Domain\Conversation\SocialExchangeContext;
use Modules\AI\Domain\Experience\ExperienceQuery;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiMemoryDriver;
use Modules\AI\Enums\AiMemoryPredicate;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Enums\AiSocialResponseReason;
use Modules\AI\Enums\AiSocialTerm;
use Modules\AI\Infrastructure\Cognition\FatimaAffectEngine;
use Modules\AI\Infrastructure\Cognition\FatimaClient;
use Modules\AI\Infrastructure\Cognition\FatimaCognitionSession;
use Modules\AI\Infrastructure\Cognition\HybridAffectEngine;
use Modules\AI\Infrastructure\Cognition\HybridSocialCognition;
use Modules\AI\Infrastructure\Experience\CbrKitExperienceEngine;
use Modules\AI\Infrastructure\Experience\HybridExperienceEngine;
use Modules\AI\Infrastructure\Memory\AgentOsLongTermMemory;
use Modules\AI\Support\AffectEngineSelector;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\DriverCircuitBreaker;
use Modules\AI\Support\ExperienceEngineSelector;
use Modules\AI\Support\FatimaScenarioTemplate;
use Modules\AI\Support\LongTermMemorySelector;
use Modules\AI\Support\SocialCognitionSelector;
use Modules\AI\Support\SystemAiClock;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(AiClock::class, SystemAiClock::class);
    app()->bind(AffectEngine::class, fn (): AffectEngine => app(AffectEngineSelector::class)->resolve());
    app()->bind(SocialCognition::class, fn (): SocialCognition => app(SocialCognitionSelector::class)->resolve());
    app()->bind(ExperienceEngine::class, fn (): ExperienceEngine => app(ExperienceEngineSelector::class)->resolve());
    app()->bind(LongTermMemory::class, fn (): LongTermMemory => app(LongTermMemorySelector::class)->resolve());
    app()->singleton(FatimaScenarioTemplate::class);
    app()->singleton(FatimaCognitionSession::class);
    app()->when(FatimaClient::class)
        ->needs(DriverCircuitBreaker::class)
        ->give(fn (): DriverCircuitBreaker => app()->makeWith(DriverCircuitBreaker::class, [
            'driver' => AiCognitionDriver::Fatima->value,
        ]));
});

function hybridStimulus(AiArchetype $archetype = AiArchetype::Miner): ObservedStimulus
{
    return app()->makeWith(ObservedStimulus::class, [
        'archetype' => $archetype,
        'harm' => 0.4,
        'aid' => 0.0,
        'threat' => 0.0,
        'relationshipTrust' => 0.0,
    ]);
}

function hybridFatimaFake(array $emotions = [], mixed $socialExchanges = []): void
{
    Http::fake([
        '*/scenarios' => Http::response('"Scenario created"'),
        '*/emotions' => Http::response($emotions),
        '*/socialexchanges' => Http::response($socialExchanges),
        '*/beliefs' => Http::response('"Belief updated."'),
        '*/perceptions' => Http::response('"perceived"'),
    ]);
}

function hybridEmotion(string $type, float $intensity, string $cause): array
{
    return ['Type' => $type, 'Intensity' => $intensity, 'Target' => 'Other', 'CauseEventId' => 1, 'CauseEventName' => $cause];
}

function hybridExchange(float|null $volition): array
{
    return [['Name' => 'CooperativeMove', 'Step' => 'Start', 'Volitions' => $volition === null ? [] : ['*' => $volition]]];
}

function hybridGreeting(float $trust = 0.5, float $affinity = 0.5): SocialExchangeContext
{
    return app()->makeWith(SocialExchangeContext::class, [
        'exchangeId' => 1,
        'type' => AiSocialExchangeType::Greeting,
        'terms' => [],
        'trust' => $trust,
        'affinity' => $affinity,
        'threat' => 0.0,
        'outstandingCommitments' => 0,
        'availableAmount' => 0.0,
        'evaluatedAt' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        'archetype' => AiArchetype::Miner,
        'counterpartyPlayerId' => 42,
    ]);
}

test('the affect selector dispatches by mode', function (): void {
    Http::fake();
    config(['ai.cognition.driver' => 'fatima']);

    config(['ai.cognition.mode' => 'native']);
    expect(app(AffectEngineSelector::class)->resolve())->toBeInstanceOf(NativeAffectEngine::class);

    config(['ai.cognition.mode' => 'external']);
    expect(app(AffectEngineSelector::class)->resolve())->toBeInstanceOf(FatimaAffectEngine::class);

    config(['ai.cognition.mode' => 'hybrid']);
    expect(app(AffectEngineSelector::class)->resolve())->toBeInstanceOf(HybridAffectEngine::class);
});

test('the social selector dispatches by mode', function (): void {
    Http::fake();
    config(['ai.cognition.driver' => 'fatima']);

    config(['ai.cognition.mode' => 'native']);
    expect(app(SocialCognitionSelector::class)->resolve())->toBeInstanceOf(NativeSocialCognition::class);

    config(['ai.cognition.mode' => 'hybrid']);
    expect(app(SocialCognitionSelector::class)->resolve())->toBeInstanceOf(HybridSocialCognition::class);
});

test('the experience selector dispatches by mode', function (): void {
    Http::fake();
    config(['ai.cognition.experience.driver' => 'cbrkit']);

    config(['ai.cognition.mode' => 'native']);
    expect(app(ExperienceEngineSelector::class)->resolve())->toBeInstanceOf(Modules\AI\Domain\Experience\NativeExperienceEngine::class);

    config(['ai.cognition.mode' => 'external']);
    expect(app(ExperienceEngineSelector::class)->resolve())->toBeInstanceOf(CbrKitExperienceEngine::class);

    config(['ai.cognition.mode' => 'hybrid']);
    expect(app(ExperienceEngineSelector::class)->resolve())->toBeInstanceOf(HybridExperienceEngine::class);
});

test('the memory selector dispatches by mode', function (): void {
    Http::fake();
    config(['ai.cognition.memory.driver' => AiMemoryDriver::AgentOs->value]);

    config(['ai.cognition.mode' => 'native']);
    expect(app(LongTermMemorySelector::class)->resolve())->toBeInstanceOf(Modules\AI\Domain\Conversation\NativeLongTermMemory::class);

    // Both modes resolve to the same adapter; the mode selects the merge (external lets the
    // ranking decide the cut, hybrid reorders within the native cut) and is covered by the
    // hybrid behaviour tests in AgentOsMemoryDriverTest.
    config(['ai.cognition.mode' => 'external']);
    expect(app(LongTermMemorySelector::class)->resolve())->toBeInstanceOf(AgentOsLongTermMemory::class);

    config(['ai.cognition.mode' => 'hybrid']);
    expect(app(LongTermMemorySelector::class)->resolve())->toBeInstanceOf(AgentOsLongTermMemory::class);
});

test('the hybrid affect engine keeps the native taxonomy and carries the driver depth', function (): void {
    config(['ai.cognition.driver' => 'fatima', 'ai.cognition.mode' => 'hybrid']);
    hybridFatimaFake([
        'Name' => 'Miner',
        'Mood' => 0.5,
        'Emotions' => [hybridEmotion('Anger', 1.0, 'Event(Action-End, Other, Harm, Miner)')],
    ]);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(hybridStimulus());
    $native = app(NativeAffectEngine::class)->appraiseObservedEvent(hybridStimulus());

    expect($appraisal)->toBeInstanceOf(AffectAppraisal::class)
        ->and($appraisal->emotion)->toBe($native->emotion)
        ->and($appraisal->intensity)->toBe($native->intensity)
        ->and($appraisal->mood)->toBe(0.5)
        ->and($appraisal->driverEmotion)->toBe(AiAffectEmotion::Anger)
        ->and($appraisal->driverIntensity)->toBe(1.0);
});

test('the hybrid affect engine degrades to a plain native appraisal when the driver is down', function (): void {
    config(['ai.cognition.driver' => 'fatima', 'ai.cognition.mode' => 'hybrid']);
    Http::fake(fn (): never => throw new ConnectionException('down'));

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(hybridStimulus());
    $native = app(NativeAffectEngine::class)->appraiseObservedEvent(hybridStimulus());

    expect($appraisal->emotion)->toBe($native->emotion)
        ->and($appraisal->intensity)->toBe($native->intensity)
        ->and($appraisal->mood)->toBeNull()
        ->and($appraisal->driverEmotion)->toBeNull()
        ->and($appraisal->driverIntensity)->toBeNull();
});

test('the hybrid social engine withholds an acceptance the driver says cannot start', function (): void {
    config(['ai.cognition.driver' => 'fatima', 'ai.cognition.mode' => 'hybrid']);
    hybridFatimaFake([], hybridExchange(null));

    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(hybridGreeting());

    expect($evaluation->response)->toBe(AiSocialResponse::Reject)
        ->and($evaluation->reason)->toBe(AiSocialResponseReason::SocialExchangeVolition)
        ->and($evaluation->step)->toBe('Start')
        ->and($evaluation->volition)->toBeNull();
});

test('the hybrid social engine questions an acceptance the driver only weakly supports', function (): void {
    config(['ai.cognition.driver' => 'fatima', 'ai.cognition.mode' => 'hybrid']);
    hybridFatimaFake([], hybridExchange(2.0));

    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(hybridGreeting());

    expect($evaluation->response)->toBe(AiSocialResponse::Clarify)
        ->and($evaluation->reason)->toBe(AiSocialResponseReason::TermsNeedConfirmation)
        ->and($evaluation->volition)->toBe(2.0)
        ->and($evaluation->step)->toBe('Start');
});

test('the hybrid social engine keeps a native acceptance with strong driver evidence', function (): void {
    config(['ai.cognition.driver' => 'fatima', 'ai.cognition.mode' => 'hybrid']);
    hybridFatimaFake([], hybridExchange(7.0));

    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(hybridGreeting());

    expect($evaluation->response)->toBe(AiSocialResponse::Accept)
        ->and($evaluation->reason)->toBe(AiSocialResponseReason::RoutineAcknowledgement)
        ->and($evaluation->volition)->toBe(7.0)
        ->and($evaluation->step)->toBe('Start');
});

test('the hybrid social engine never overrides a native refusal', function (): void {
    config(['ai.cognition.driver' => 'fatima', 'ai.cognition.mode' => 'hybrid']);
    hybridFatimaFake([], hybridExchange(7.0));

    $context = app()->makeWith(SocialExchangeContext::class, [
        'exchangeId' => 2,
        'type' => AiSocialExchangeType::Warning,
        'terms' => [AiSocialTerm::Coercive->value => true],
        'trust' => 0.5,
        'affinity' => 0.5,
        'threat' => 0.0,
        'outstandingCommitments' => 0,
        'availableAmount' => 0.0,
        'evaluatedAt' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        'archetype' => AiArchetype::Miner,
        'counterpartyPlayerId' => 42,
    ]);

    $evaluation = app(SocialCognition::class)->evaluateSocialExchange($context);

    expect($evaluation->response)->toBe(AiSocialResponse::Reject)
        ->and($evaluation->reason)->toBe(AiSocialResponseReason::CoerciveWarning);
});

test('the hybrid social engine answers natively when the driver has no counterparty', function (): void {
    config(['ai.cognition.driver' => 'fatima', 'ai.cognition.mode' => 'hybrid']);
    Http::fake();

    $context = app()->makeWith(SocialExchangeContext::class, [
        'exchangeId' => 3,
        'type' => AiSocialExchangeType::Greeting,
        'terms' => [],
        'trust' => 0.5,
        'affinity' => 0.5,
        'threat' => 0.0,
        'outstandingCommitments' => 0,
        'availableAmount' => 0.0,
        'evaluatedAt' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
    ]);

    $evaluation = app(SocialCognition::class)->evaluateSocialExchange($context);

    expect($evaluation->response)->toBe(AiSocialResponse::Accept)
        ->and($evaluation->volition)->toBeNull()
        ->and($evaluation->step)->toBeNull();

    Http::assertNothingSent();
});

test('the hybrid experience engine merges the native similarity with the driver order', function (): void {
    config(['ai.cognition.experience.driver' => 'cbrkit', 'ai.cognition.mode' => 'hybrid']);

    $first = app(Modules\AI\Actions\RecordAiExperienceOutcomeAction::class)->handle(
        $this->currentUserId,
        4001,
        AiExperienceCaseFamily::SocialAssistance,
        Modules\AI\Enums\AiExperienceOutcome::Succeeded,
        'v1',
        'v1',
        ['amount' => 100, 'resource' => 'crystal'],
        0.5,
        0.25,
    )->id;
    $second = app(Modules\AI\Actions\RecordAiExperienceOutcomeAction::class)->handle(
        $this->currentUserId,
        4002,
        AiExperienceCaseFamily::SocialAssistance,
        Modules\AI\Enums\AiExperienceOutcome::Succeeded,
        'v1',
        'v1',
        ['amount' => 50, 'resource' => 'metal'],
        0.5,
        0.25,
    )->id;

    // The driver's own measure orders the cases the other way around from native.
    Http::fake(['*' => Http::response(['steps' => [['queries' => ['current' => ['similarities' => [(string) $first => 0.4, (string) $second => 0.9]]]]]])]);

    $query = app()->makeWith(ExperienceQuery::class, [
        'playerId' => $this->currentUserId,
        'family' => AiExperienceCaseFamily::SocialAssistance,
        'featureVersion' => 'v1',
        'rulesetVersion' => 'v1',
        'features' => ['amount' => 100, 'resource' => 'crystal'],
        'limit' => 5,
    ]);

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences($query);

    expect($ranked)->toHaveCount(2)
        ->and($ranked[0]->caseId)->toBe($second)
        ->and($ranked[0]->similarity)->toBe(0.25)
        ->and($ranked[0]->driverSimilarity)->toBe(0.9)
        ->and($ranked[1]->caseId)->toBe($first)
        ->and($ranked[1]->similarity)->toBe(1.0)
        ->and($ranked[1]->driverSimilarity)->toBe(0.4);
});

test('the hybrid experience engine keeps the native ranking when the driver is down', function (): void {
    config(['ai.cognition.experience.driver' => 'cbrkit', 'ai.cognition.mode' => 'hybrid']);

    app(Modules\AI\Actions\RecordAiExperienceOutcomeAction::class)->handle(
        $this->currentUserId,
        4003,
        AiExperienceCaseFamily::SocialAssistance,
        Modules\AI\Enums\AiExperienceOutcome::Succeeded,
        'v1',
        'v1',
        ['amount' => 100],
        0.5,
        0.25,
    );

    Http::fake(fn (): never => throw new ConnectionException('down'));

    $query = app()->makeWith(ExperienceQuery::class, [
        'playerId' => $this->currentUserId,
        'family' => AiExperienceCaseFamily::SocialAssistance,
        'featureVersion' => 'v1',
        'rulesetVersion' => 'v1',
        'features' => ['amount' => 100],
        'limit' => 5,
    ]);

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences($query);

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->driverSimilarity)->toBeNull()
        ->and($ranked[0]->similarity)->toBe(1.0);
});

test('the hybrid experience engine keeps the native candidate set and only reorders within it', function (): void {
    config(['ai.cognition.experience.driver' => 'cbrkit', 'ai.cognition.mode' => 'hybrid']);

    $best = app(Modules\AI\Actions\RecordAiExperienceOutcomeAction::class)->handle(
        $this->currentUserId,
        4010,
        AiExperienceCaseFamily::SocialAssistance,
        Modules\AI\Enums\AiExperienceOutcome::Succeeded,
        'v1',
        'v1',
        ['amount' => 100],
        0.5,
        0.25,
    )->id;
    $middle = app(Modules\AI\Actions\RecordAiExperienceOutcomeAction::class)->handle(
        $this->currentUserId,
        4011,
        AiExperienceCaseFamily::SocialAssistance,
        Modules\AI\Enums\AiExperienceOutcome::Succeeded,
        'v1',
        'v1',
        ['amount' => 80],
        0.5,
        0.25,
    )->id;
    $far = app(Modules\AI\Actions\RecordAiExperienceOutcomeAction::class)->handle(
        $this->currentUserId,
        4012,
        AiExperienceCaseFamily::SocialAssistance,
        Modules\AI\Enums\AiExperienceOutcome::Succeeded,
        'v1',
        'v1',
        ['amount' => 10],
        0.5,
        0.25,
    )->id;

    // The driver promotes `far` over the native top two, but the native set is authoritative:
    // `far` is not evidence and the driver only reorders the two cases native kept.
    Http::fake(['*' => Http::response(['steps' => [['queries' => ['current' => ['similarities' => [(string) $best => 0.1, (string) $middle => 0.2, (string) $far => 0.9]]]]]])]);

    $query = app()->makeWith(ExperienceQuery::class, [
        'playerId' => $this->currentUserId,
        'family' => AiExperienceCaseFamily::SocialAssistance,
        'featureVersion' => 'v1',
        'rulesetVersion' => 'v1',
        'features' => ['amount' => 100],
        'limit' => 2,
    ]);

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences($query);

    expect($ranked)->toHaveCount(2)
        ->and($ranked[0]->caseId)->toBe($middle)
        ->and($ranked[0]->similarity)->toBe(0.8)
        ->and($ranked[0]->driverSimilarity)->toBe(0.2)
        ->and($ranked[1]->caseId)->toBe($best)
        ->and($ranked[1]->similarity)->toBe(1.0)
        ->and($ranked[1]->driverSimilarity)->toBeNull();
});

test('an outstanding resource debt cools a new help request', function (): void {
    $debt = [
        'id' => 1, 'source_observation_id' => 9001, 'source_type' => null, 'source_id' => null,
        'subject_player_id' => 42, 'predicate' => AiMemoryPredicate::ResourceDebt->name,
        'evidence_kind' => 'Claimed', 'speaker_player_id' => null, 'value' => ['amount' => 200],
    ];

    $context = app()->makeWith(SocialExchangeContext::class, [
        'exchangeId' => 4,
        'type' => AiSocialExchangeType::HelpRequest,
        'terms' => [AiSocialTerm::Amount->value => 100],
        'trust' => 0.5,
        'affinity' => 0.5,
        'threat' => 0.0,
        'outstandingCommitments' => 0,
        'availableAmount' => 1000.0,
        'evaluatedAt' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        'history' => [$debt],
    ]);

    expect(app(NativeSocialCognition::class)->evaluateSocialExchange($context)->response)->toBe(AiSocialResponse::Clarify);

    $withoutDebt = app()->makeWith(SocialExchangeContext::class, [
        'exchangeId' => 5,
        'type' => AiSocialExchangeType::HelpRequest,
        'terms' => [AiSocialTerm::Amount->value => 100],
        'trust' => 0.5,
        'affinity' => 0.5,
        'threat' => 0.0,
        'outstandingCommitments' => 0,
        'availableAmount' => 1000.0,
        'evaluatedAt' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
    ]);

    expect(app(NativeSocialCognition::class)->evaluateSocialExchange($withoutDebt)->response)->toBe(AiSocialResponse::Accept);
});
