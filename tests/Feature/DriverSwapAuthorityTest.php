<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\AI\Actions\RecordAiCommitmentAction;
use Modules\AI\Actions\RecordAiExperienceOutcomeAction;
use Modules\AI\Actions\RecordAiRelationshipInteractionAction;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Domain\Cognition\ObservedStimulus;
use Modules\AI\Domain\Experience\ExperienceQuery;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiBuildingExperienceFeature;
use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceFeatureVersion;
use Modules\AI\Enums\AiExperienceOutcome;
use Modules\AI\Enums\AiExperienceRulesetVersion;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Infrastructure\Cognition\FatimaClient;
use Modules\AI\Infrastructure\Cognition\FatimaCognitionSession;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AffectEngineSelector;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\DriverCircuitBreaker;
use Modules\AI\Support\ExperienceEngineSelector;
use Modules\AI\Support\FatimaScenarioTemplate;
use Modules\AI\Support\SystemAiClock;
use Modules\AI\Tests\Support\InteractsWithCognitionFixtures;
use Tests\IsolatedAccountTestCase;

require_once __DIR__.'/../Support/InteractsWithCognitionFixtures.php';

uses(IsolatedAccountTestCase::class, InteractsWithCognitionFixtures::class);

/**
 * One authority (gate A2) and no truth widening (gate A5).
 *
 * Different engines legitimately disagree: FAtiMA's appraisal is not this module's
 * appraisal, and the plan forbids claiming bit-identical behaviour across engines. What
 * must not differ is ownership. Persona, relationships, commitments and outcome cases
 * belong to the module, so a selected driver may change an answer while changing no
 * module record, and may never surface a record the module would not.
 *
 * The module's own bindings are not active in this suite, so they are wired here exactly
 * as AIServiceProvider::register() wires them.
 */
beforeEach(function (): void {
    config(['ai.cognition.mode' => 'external']);
    app()->bind(AiClock::class, SystemAiClock::class);
    app()->bind(AffectEngine::class, fn (): AffectEngine => app(AffectEngineSelector::class)->resolve());
    app()->bind(ExperienceEngine::class, fn (): ExperienceEngine => app(ExperienceEngineSelector::class)->resolve());
    app()->singleton(FatimaScenarioTemplate::class);
    app()->singleton(FatimaCognitionSession::class);
    app()->when(FatimaClient::class)
        ->needs(DriverCircuitBreaker::class)
        ->give(fn (): DriverCircuitBreaker => app()->makeWith(DriverCircuitBreaker::class, [
            'driver' => AiCognitionDriver::Fatima->value,
        ]));
});

/** @return array<string, list<array<string, mixed>>> */
function moduleRecordSnapshot(): array
{
    $tables = [
        'ai_profiles', 'ai_work_items', 'ai_action_receipts', 'ai_schedules',
        'ai_decision_traces', 'ai_observations', 'ai_memory_facts', 'ai_relationships',
        'ai_commitments', 'ai_affect_states', 'ai_emotional_episodes', 'ai_social_exchanges',
        'ai_experience_cases', 'ai_usage_reservations', 'ai_conversation_replies',
        'ai_language_requests', 'ai_language_proposals',
    ];

    $snapshot = [];

    foreach ($tables as $table) {
        $snapshot[$table] = DB::table($table)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    return $snapshot;
}

function swapObservedStimulus(): ObservedStimulus
{
    return app()->makeWith(ObservedStimulus::class, [
        'archetype' => AiArchetype::Miner,
        'harm' => 0.4,
        'aid' => 0.0,
        'threat' => 0.0,
        'relationshipTrust' => 0.0,
    ]);
}

function swapExperienceCase(int $playerId, int $sourceId, int $objectId): int
{
    return app(RecordAiExperienceOutcomeAction::class)->handle(
        $playerId,
        $sourceId,
        AiExperienceCaseFamily::BuildingUpgrade,
        AiExperienceOutcome::Succeeded,
        AiExperienceFeatureVersion::BuildingUpgradeV1->value,
        AiExperienceRulesetVersion::HostBuildingCompletionV1->value,
        [
            AiBuildingExperienceFeature::PlanetId->value => 1,
            AiBuildingExperienceFeature::ObjectId->value => $objectId,
            AiBuildingExperienceFeature::TargetLevel->value => 5,
        ],
        1.0,
        0.0,
    )->id;
}

function swapExperienceQuery(int $playerId): ExperienceQuery
{
    return app()->makeWith(ExperienceQuery::class, [
        'playerId' => $playerId,
        'family' => AiExperienceCaseFamily::BuildingUpgrade,
        'featureVersion' => AiExperienceFeatureVersion::BuildingUpgradeV1->value,
        'rulesetVersion' => AiExperienceRulesetVersion::HostBuildingCompletionV1->value,
        'features' => [AiBuildingExperienceFeature::ObjectId->value => 2],
        'limit' => 5,
    ]);
}

/** Seeds each authority family through the module's own recorders. */
function seedSwapAuthority(int $playerId, int $counterpartyId): void
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 7,
        'enabled' => true,
    ]);

    $observation = AiObservation::create([
        'player_id' => $playerId,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => crc32('swap-authority'),
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => $counterpartyId,
        'source_time' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
    ]);

    app(RecordAiRelationshipInteractionAction::class)->handle(
        $playerId,
        $counterpartyId,
        $observation->id,
        CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        0.7,
        0.1,
        0.4,
    );

    app(RecordAiCommitmentAction::class)->handle(
        $playerId,
        $counterpartyId,
        ['resource' => 'metal', 'amount' => 500],
        $observation->id,
    );

    swapExperienceCase($playerId, $observation->id, 2);
}

test('disabling every driver answers from the module and writes no record', function (): void {
    $counterparty = $this->createUser();
    seedSwapAuthority($this->currentUserId, $counterparty->id);
    $before = moduleRecordSnapshot();

    config(['ai.cognition.driver' => AiCognitionDriver::Native->value, 'ai.cognition.experience.driver' => 'native']);
    Http::fake();

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(swapObservedStimulus());
    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(swapExperienceQuery($this->currentUserId));

    expect($appraisal->intensity)->toBeGreaterThan(0.0)
        ->and($ranked)->toHaveCount(1)
        ->and(moduleRecordSnapshot())->toBe($before);

    Http::assertNothingSent();
});

test('a swapped driver may change its answer while changing no module record', function (): void {
    $counterparty = $this->createUser();
    seedSwapAuthority($this->currentUserId, $counterparty->id);
    $before = moduleRecordSnapshot();

    // Both sidecars answer from their recordings, so the swap is not vacuously passing. Each
    // fake is registered against its own endpoint, so neither can shadow the other.
    $this->fakeFatimaDriver();
    $this->fakeCbrKitDriver();

    config(['ai.cognition.driver' => AiCognitionDriver::Native->value, 'ai.cognition.experience.driver' => 'native']);
    $baselineIntensity = app(AffectEngine::class)->appraiseObservedEvent(swapObservedStimulus())->intensity;
    app(ExperienceEngine::class)->rankSimilarExperiences(swapExperienceQuery($this->currentUserId));

    // The baseline path never reaches the network, even with a driver configured as an option.
    Http::assertNothingSent();

    config(['ai.cognition.driver' => AiCognitionDriver::Fatima->value, 'ai.cognition.experience.driver' => 'cbrkit']);
    $driverAppraisal = app(AffectEngine::class)->appraiseObservedEvent(swapObservedStimulus());
    app(ExperienceEngine::class)->rankSimilarExperiences(swapExperienceQuery($this->currentUserId));

    // Both drivers were genuinely consulted, so the swap is not vacuously passing.
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/perceptions'));
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/retrieve'));

    // The driver's judgement legitimately differs from the baseline ...
    expect($driverAppraisal->intensity)->not->toBe($baselineIntensity)
        // ... while persona, relationships, commitments and outcome cases are untouched.
        ->and(moduleRecordSnapshot())->toBe($before);
});

test('a driver cannot surface a case the module did not send', function (): void {
    $foreignOwner = $this->createUser();
    $ownCaseId = swapExperienceCase($this->currentUserId, 6001, 2);
    // An identical case owned by somebody else must never leave the module.
    swapExperienceCase($foreignOwner->id, 6002, 2);

    config(['ai.cognition.experience.driver' => 'cbrkit']);
    // The retriever scores only what it is handed, so an answer naming a case the module never
    // sent can only be a probe.
    $this->fakeCbrKitDriver($this->driverProbe('cbrkit.scores_an_unsent_case', [
        '@sent' => (string) $ownCaseId,
    ]));

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(swapExperienceQuery($this->currentUserId));

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->caseId)->toBe($ownCaseId);

    Http::assertSent(function ($request) use ($ownCaseId): bool {
        $casebase = $request->data()['casebase'];

        // Only the owner-scoped casebase leaves the module: one case, and no foreign id.
        return count($casebase) === 1
            && (int) array_key_first($casebase) === $ownCaseId;
    });
});
