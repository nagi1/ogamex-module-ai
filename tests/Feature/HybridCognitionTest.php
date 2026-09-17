<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\AI\Actions\RecordAiExperienceOutcomeAction;
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
use Modules\AI\Enums\AiBuildingExperienceFeature;
use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceFeatureVersion;
use Modules\AI\Enums\AiExperienceOutcome;
use Modules\AI\Enums\AiExperienceRulesetVersion;
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
use Modules\AI\Tests\Support\InteractsWithCognitionFixtures;
use Tests\IsolatedAccountTestCase;

require_once __DIR__.'/../Support/InteractsWithCognitionFixtures.php';

uses(IsolatedAccountTestCase::class, InteractsWithCognitionFixtures::class);

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

/** @return array<string, int> the module's real building-upgrade features */
function hybridBuildingFeatures(int $objectId, int $targetLevel, int $planetId = 1): array
{
    return [
        AiBuildingExperienceFeature::PlanetId->value => $planetId,
        AiBuildingExperienceFeature::ObjectId->value => $objectId,
        AiBuildingExperienceFeature::TargetLevel->value => $targetLevel,
    ];
}

function hybridBuildingCase(int $playerId, int $sourceId, int $objectId, int $targetLevel): int
{
    return app(RecordAiExperienceOutcomeAction::class)->handle(
        $playerId,
        $sourceId,
        AiExperienceCaseFamily::BuildingUpgrade,
        AiExperienceOutcome::Succeeded,
        AiExperienceFeatureVersion::BuildingUpgradeV1->value,
        AiExperienceRulesetVersion::HostBuildingCompletionV1->value,
        hybridBuildingFeatures($objectId, $targetLevel),
        0.5,
        0.25,
    )->id;
}

/** The production building-upgrade query, as `EconomyUpgrades` and the conformance trial ask it. */
function hybridBuildingQuery(int $playerId, int $limit): ExperienceQuery
{
    return app()->makeWith(ExperienceQuery::class, [
        'playerId' => $playerId,
        'family' => AiExperienceCaseFamily::BuildingUpgrade,
        'featureVersion' => AiExperienceFeatureVersion::BuildingUpgradeV1->value,
        'rulesetVersion' => AiExperienceRulesetVersion::HostBuildingCompletionV1->value,
        'features' => [
            AiBuildingExperienceFeature::ObjectId->value => 2,
            AiBuildingExperienceFeature::TargetLevel->value => 6,
        ],
        'limit' => $limit,
    ]);
}

/**
 * A greeting the module's own rules accept whatever the counterparty's standing is, so the
 * driver's rapport threshold is the only thing that can withhold it.
 */
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
    $this->fakeFatimaDriver();

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(hybridStimulus());
    $native = app(NativeAffectEngine::class)->appraiseObservedEvent(hybridStimulus());

    // The module's own persona-weighted figure is the appraisal; the driver's unweighted Anger is
    // evidence alongside it, and its intensity is the one the sidecar really returned.
    expect($appraisal)->toBeInstanceOf(AffectAppraisal::class)
        ->and($appraisal->emotion)->toBe($native->emotion)
        ->and($appraisal->intensity)->toBe($native->intensity)
        ->and($appraisal->intensity)->toBeLessThan(0.4)
        ->and($appraisal->mood)->toEqual(0.0)
        ->and($appraisal->driverEmotion)->toBe(AiAffectEmotion::Anger)
        ->and($appraisal->driverIntensity)->toEqual(0.4);
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
    $this->fakeFatimaDriver();

    // A standing of 0.2 reduces to a rapport of 2, which the authored scenario's starting
    // condition does not clear, so CiF offers no volition at all.
    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(hybridGreeting(0.2, 0.2));

    expect($evaluation->response)->toBe(AiSocialResponse::Reject)
        ->and($evaluation->reason)->toBe(AiSocialResponseReason::SocialExchangeVolition)
        ->and($evaluation->step)->toBe('Start')
        ->and($evaluation->volition)->toBeNull();
});

test('the hybrid social engine questions an acceptance the driver only weakly supports', function (): void {
    config(['ai.cognition.driver' => 'fatima', 'ai.cognition.mode' => 'hybrid']);
    // The authored influence rule sets a constant, so the driver answers 7 or nothing at all and
    // a volition below the module's own acceptance threshold can only be a probe.
    $this->fakeFatimaDriver(['socialexchanges' => $this->driverProbe('fatima.socialexchanges.lukewarm')]);

    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(hybridGreeting());

    expect($evaluation->response)->toBe(AiSocialResponse::Clarify)
        ->and($evaluation->reason)->toBe(AiSocialResponseReason::TermsNeedConfirmation)
        ->and($evaluation->volition)->toBe(2.0)
        ->and($evaluation->step)->toBe('Start');
});

test('the hybrid social engine keeps a native acceptance with strong driver evidence', function (): void {
    config(['ai.cognition.driver' => 'fatima', 'ai.cognition.mode' => 'hybrid']);
    $this->fakeFatimaDriver();

    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(hybridGreeting());

    // A standing of 0.5 clears the authored threshold, so CiF offers the volition it really does.
    expect($evaluation->response)->toBe(AiSocialResponse::Accept)
        ->and($evaluation->reason)->toBe(AiSocialResponseReason::RoutineAcknowledgement)
        ->and($evaluation->volition)->toBe(7.0)
        ->and($evaluation->step)->toBe('Start');
});

test('the hybrid social engine never overrides a native refusal', function (): void {
    config(['ai.cognition.driver' => 'fatima', 'ai.cognition.mode' => 'hybrid']);
    $this->fakeFatimaDriver();

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

    // The module's uniform mean scores a different object at the right level at 0.833 and the same
    // object at a distant level at 0.667, so native ranks the different object first. The driver's
    // categorical identity puts any same-object case above any other, so it ranks them the other
    // way round; the native similarity stays the figure the decision policy weighs.
    $otherObject = hybridBuildingCase($this->currentUserId, 4001, 3, 6);
    $distantLevel = hybridBuildingCase($this->currentUserId, 4002, 2, 2);

    $this->fakeCbrKitDriver();

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(hybridBuildingQuery($this->currentUserId, 5));

    expect($ranked)->toHaveCount(2)
        ->and($ranked[0]->caseId)->toBe($distantLevel)
        ->and($ranked[0]->similarity)->toEqualWithDelta(0.6666666666666667, 1e-9)
        ->and($ranked[0]->driverSimilarity)->toEqualWithDelta(0.7777777777777778, 1e-9)
        ->and($ranked[1]->caseId)->toBe($otherObject)
        ->and($ranked[1]->similarity)->toEqualWithDelta(0.8333333333333334, 1e-9)
        ->and($ranked[1]->driverSimilarity)->toEqualWithDelta(0.3333333333333333, 1e-9);
});

test('the hybrid experience engine keeps the native ranking when the driver is down', function (): void {
    config(['ai.cognition.experience.driver' => 'cbrkit', 'ai.cognition.mode' => 'hybrid']);
    hybridBuildingCase($this->currentUserId, 4003, 2, 6);

    Http::fake(fn (): never => throw new ConnectionException('down'));

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(hybridBuildingQuery($this->currentUserId, 5));

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->driverSimilarity)->toBeNull()
        ->and($ranked[0]->similarity)->toBe(1.0);
});

test('the hybrid experience engine keeps the native candidate set and only reorders within it', function (): void {
    config(['ai.cognition.experience.driver' => 'cbrkit', 'ai.cognition.mode' => 'hybrid']);

    $exact = hybridBuildingCase($this->currentUserId, 4010, 2, 6);
    $otherObject = hybridBuildingCase($this->currentUserId, 4011, 3, 6);
    $distantLevel = hybridBuildingCase($this->currentUserId, 4012, 2, 2);

    $this->fakeCbrKitDriver();

    // The driver ranks the distant level above the different object, but the native cut of two is
    // authoritative: a case native did not rank is not evidence for this choice, so the answer
    // holds the native pair and the excluded case never surfaces.
    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(hybridBuildingQuery($this->currentUserId, 2));
    $rankedIds = array_map(fn ($case): int => $case->caseId, $ranked);

    expect($rankedIds)->toBe([$exact, $otherObject])
        ->and($rankedIds)->not->toContain($distantLevel)
        ->and($ranked[0]->similarity)->toBe(1.0)
        ->and($ranked[0]->driverSimilarity)->toBe(1.0)
        ->and($ranked[1]->similarity)->toEqualWithDelta(0.8333333333333334, 1e-9)
        // The driver never ranked this one, so it arrives with no driver evidence at all.
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
