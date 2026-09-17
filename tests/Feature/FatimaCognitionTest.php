<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\AI\Actions\EvaluateAiSocialExchangeAction;
use Modules\AI\Actions\RecordAiRelationshipInteractionAction;
use Modules\AI\Actions\RecordAiSocialExchangeAction;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Cognition\ObservedStimulus;
use Modules\AI\Domain\Conversation\SocialExchangeContext;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Enums\AiSocialResponseReason;
use Modules\AI\Enums\AiSocialTerm;
use Modules\AI\Infrastructure\Cognition\FatimaAffectEngine;
use Modules\AI\Infrastructure\Cognition\FatimaClient;
use Modules\AI\Infrastructure\Cognition\FatimaCognitionSession;
use Modules\AI\Infrastructure\Cognition\FatimaSocialCognition;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Providers\AIServiceProvider;
use Modules\AI\Support\AffectEngineSelector;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\DriverCircuitBreaker;
use Modules\AI\Support\FatimaScenarioTemplate;
use Modules\AI\Support\SocialCognitionSelector;
use Modules\AI\Support\SystemAiClock;
use Modules\AI\Tests\Support\InteractsWithCognitionFixtures;
use Tests\IsolatedAccountTestCase;

require_once __DIR__.'/../Support/InteractsWithCognitionFixtures.php';

uses(IsolatedAccountTestCase::class, InteractsWithCognitionFixtures::class);

/**
 * The FAtiMA driver is opt-in. Every failure branch must leave the native appraisal
 * and the native social response in place, because both feed ordinary gameplay and a
 * stopped sidecar must never change what an AI decides.
 *
 * The successful paths replay what the running sidecar really answered, captured into
 * `tests/Fixtures/cognition/fatima.json`. The fake follows the driver's own rule — what it
 * answers depends on the beliefs and the event it was just handed — so its thresholds are
 * real rather than assumed: a rapport the module derives as 3 or less genuinely withholds the
 * social exchange, and nothing in this file states that threshold itself.
 */
beforeEach(function (): void {
    // Swap semantics: FAtiMA replaces native, with native as the per-call fallback.
    config(['ai.cognition.mode' => 'external']);
    config(['ai.cognition.driver' => 'fatima']);
    app()->bind(AiClock::class, SystemAiClock::class);

    // The module's own bindings are not active in this suite, so the driver is wired
    // here exactly as the provider wires it. Routing through the selectors keeps the
    // real selection logic under test rather than a test-local copy.
    app()->bind(AffectEngine::class, fn (): AffectEngine => app(AffectEngineSelector::class)->resolve());
    app()->bind(SocialCognition::class, fn (): SocialCognition => app(SocialCognitionSelector::class)->resolve());
    app()->singleton(FatimaScenarioTemplate::class);
    app()->when(FatimaClient::class)
        ->needs(DriverCircuitBreaker::class)
        ->give(fn (): DriverCircuitBreaker => app()->makeWith(DriverCircuitBreaker::class, [
            'driver' => AiCognitionDriver::Fatima->value,
        ]));
    app()->singleton(FatimaCognitionSession::class);
});

/** How many recorded calls the driver received on one endpoint. */
function fatimaCalls(string $suffix): int
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), $suffix))
        ->count();
}

function fatimaStimulus(AiArchetype $archetype, float $harm, float $aid, float $threat, float $trust = 0.0): ObservedStimulus
{
    return app()->makeWith(ObservedStimulus::class, [
        'archetype' => $archetype,
        'harm' => $harm,
        'aid' => $aid,
        'threat' => $threat,
        'relationshipTrust' => $trust,
    ]);
}

function fatimaObservation(int $playerId, int $counterpartyId, string $key): AiObservation
{
    return AiObservation::create([
        'player_id' => $playerId,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => crc32($key),
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => $counterpartyId,
        'source_time' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
    ]);
}

/**
 * Seeds a persona and a relationship, then records a greeting.
 *
 * A greeting is the one exchange the module's own rules accept whatever the counterparty's
 * standing is, so the driver's rapport threshold is the only thing that can withhold it. A help
 * request cannot show that: the trust that makes the native rules accept it is the same trust that
 * carries the rapport past the authored threshold.
 */
function greetedFatimaExchange(int $playerId, int $counterpartyId, float $trust, float $affinity): int
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 1,
        'enabled' => true,
    ]);

    $source = fatimaObservation($playerId, $counterpartyId, 'greeting-' . $counterpartyId . '-' . $trust);
    app(RecordAiRelationshipInteractionAction::class)->handle(
        $playerId,
        $counterpartyId,
        $source->id,
        CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        $trust,
        0.0,
        $affinity,
    );

    return app(RecordAiSocialExchangeAction::class)->handle(
        $playerId,
        $counterpartyId,
        $source->id,
        AiSocialExchangeType::Greeting,
        [],
    )->id;
}

test('the native affect engine remains the default when no driver is configured', function (): void {
    Http::fake();
    config(['ai.cognition.driver' => AiCognitionDriver::Native->value]);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0));

    expect($appraisal->emotion)->toBe(AiAffectEmotion::Gratitude)
        ->and($appraisal->intensity)->toBe(0.5);

    Http::assertNothingSent();
});

test('an unrecognised cognition driver is reported and falls back to native', function (): void {
    Log::spy();
    Http::fake();
    config(['ai.cognition.driver' => 'not-a-driver']);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0));

    expect($appraisal->emotion)->toBe(AiAffectEmotion::Gratitude);

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});

test('the driver appraises each stimulus branch from signed OCC values', function (float $harm, float $aid, float $threat, AiAffectEmotion $emotion, float $intensity): void {
    $branch = match (true) {
        $aid > $harm => 'Aid',
        $threat > $harm => 'Threaten',
        default => 'Harm',
    };
    $event = sprintf('Event(Action-End, Other, %s, Miner)', $branch);
    $this->fakeFatimaDriver();

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, $harm, $aid, $threat));

    // The intensity is the driver's own answer for the belief it was given, which is why an
    // aid of 0.5 comes back as 0.5 rather than as the module's persona-weighted figure.
    expect($appraisal->emotion)->toBe($emotion)
        ->and($appraisal->intensity)->toEqualWithDelta($intensity, 1e-9)
        // Each appraisal resets the driver, so mood and goal state cannot accumulate.
        ->and(fatimaCalls('/scenarios'))->toBe(1);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/perceptions')
        && $request->data() === [$event]);
})->with([
    'aid becomes gratitude' => [0.0, 0.5, 0.0, AiAffectEmotion::Gratitude, 0.5],
    'harm becomes anger' => [0.4, 0.0, 0.0, AiAffectEmotion::Anger, 0.4],
    'threat becomes fear' => [0.0, 0.0, 0.6, AiAffectEmotion::Fear, 0.6],
    'aid outranks harm' => [0.2, 0.5, 0.0, AiAffectEmotion::Gratitude, 0.5],
    'threat outranks harm' => [0.2, 0.0, 0.6, AiAffectEmotion::Fear, 0.6],
]);

test('the signed OCC value follows the branch that was actually observed', function (): void {
    $this->fakeFatimaDriver();

    // Harm answers despite the threat, because threat must outrank harm to win.
    app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.4, 0.0, 0.2));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/beliefs')
        && $request->data() === ['name' => 'StimulusDesirability(SELF, Other)', 'value' => '-0.4']);
});

test('the aid branch is sent as a positive OCC desirability', function (): void {
    $this->fakeFatimaDriver();

    app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/beliefs')
        && $request->data() === ['name' => 'StimulusDesirability(SELF, Other)', 'value' => '0.5']);
});

test('the threat branch lowers the goal success probability instead of the desirability', function (): void {
    $this->fakeFatimaDriver();

    app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.0, 0.6));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/beliefs')
        && $request->data() === ['name' => 'StimulusThreat(SELF, Other)', 'value' => '-0.6']);
});

test('an emotion the module cannot represent is declined rather than approximated', function (): void {
    Log::spy();
    $this->fakeFatimaDriver(['emotions' => $this->driverProbe('fatima.emotions.unrepresentable')]);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0));

    // The native engine answers instead, so no invented meaning reaches module state.
    expect($appraisal->emotion)->toBe(AiAffectEmotion::Gratitude)
        ->and($appraisal->intensity)->toBe(0.5);

    Log::shouldHaveReceived('info')->once();
});

test('an emotion caused by a different event is ignored', function (): void {
    $this->fakeFatimaDriver(['emotions' => $this->driverProbe('fatima.emotions.wrong_cause')]);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0));

    expect($appraisal->emotion)->toBe(AiAffectEmotion::Gratitude);
});

test('an empty emotional pool falls back to the native appraisal', function (): void {
    // A stimulus with no harm, aid or threat is the one appraisal the authored rules bind to no
    // intensity, which is what leaves the driver's pool empty.
    $this->fakeFatimaDriver();

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.0, 0.0));

    expect(fatimaCalls('/emotions'))->toBe(1)
        ->and($appraisal->emotion)->toBe(AiAffectEmotion::Anger)
        ->and($appraisal->intensity)->toBe(0.0)
        ->and($appraisal->driverEmotion)->toBeNull();
});

test('a normalised intensity is clamped to the module ceiling', function (): void {
    config(['ai.cognition.fatima.intensity_ceiling' => 0.75]);
    // The driver echoes the belief it is given, so an aid of 0.9 really does come back as 0.9.
    $this->fakeFatimaDriver();

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.9, 0.0));

    expect($appraisal->emotion)->toBe(AiAffectEmotion::Gratitude)
        ->and($appraisal->intensity)->toBe(0.75);
});

test('a malformed driver payload falls back to the native appraisal', function (string $probe): void {
    $this->fakeFatimaDriver(['emotions' => $this->driverProbe($probe)]);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0));

    expect($appraisal->emotion)->toBe(AiAffectEmotion::Gratitude)
        ->and($appraisal->intensity)->toBe(0.5);
})->with([
    // The driver reports failures as a plain JSON string while still answering HTTP 200.
    'error text' => ['fatima.emotions.plain_failure'],
    'missing pool' => ['fatima.emotions.missing_pool'],
    'non-object pool' => ['fatima.emotions.non_object_pool'],
    'malformed entry' => ['fatima.emotions.malformed_entry'],
    'missing cause event' => ['fatima.emotions.missing_cause'],
]);

test('an unreachable driver falls back to the native appraisal', function (): void {
    Http::fake(['*' => fn () => throw new ConnectionException('Connection refused')]);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0));

    expect($appraisal->emotion)->toBe(AiAffectEmotion::Gratitude)
        ->and($appraisal->intensity)->toBe(0.5);
});

test('a driver server error falls back to the native appraisal', function (): void {
    $this->fakeFatimaDriver(['*' => $this->driverProbe('fatima.server_error')]);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0));

    expect($appraisal->emotion)->toBe(AiAffectEmotion::Gratitude)
        ->and($appraisal->intensity)->toBe(0.5);
});

test('the driver is skipped while its circuit is open and retried once it clears', function (): void {
    config(['ai.cognition.circuit.failures' => 1]);
    // The driver answers its failures with HTTP 200 and a plain string, so this is a recorded
    // request that still fails the contract.
    $this->fakeFatimaDriver(['*' => $this->driverProbe('fatima.emotions.plain_failure')]);

    $engine = app(AffectEngine::class);
    // The first contract failure reaches the threshold, so the circuit opens.
    $engine->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0));

    // An empty fake clears the recorded log without replacing the installed stub.
    Http::fake();
    expect($engine->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0))->emotion)->toBe(AiAffectEmotion::Gratitude);
    expect(Http::recorded())->toHaveCount(0);

    Cache::forget('ai:cognition:fatima:open');
    Cache::forget('ai:cognition:fatima:failures');

    expect($engine->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0))->emotion)->toBe(AiAffectEmotion::Gratitude);
    expect(Http::recorded())->not->toHaveCount(0);
});

test('a contested session lock degrades to the native appraisal', function (): void {
    Log::spy();
    $this->fakeFatimaDriver();

    // Another appraisal holds the character, so this one cannot run within its own
    // timeout and must not risk interleaving state on a shared character.
    $held = Cache::lock('ai:cognition:fatima', 30);
    $held->acquire();
    config(['ai.cognition.fatima.lock_seconds' => 1]);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.4, 0.0, 0.0));

    $held->release();

    expect($appraisal->emotion)->toBe(AiAffectEmotion::Anger)
        ->and($appraisal->intensity)->toEqualWithDelta(0.08, 1e-9);

    // The lock is taken before the first call, so nothing reaches the driver at all.
    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});

test('a missing scenario fixture aborts loudly instead of appraising nothing', function (): void {
    config(['ai.cognition.fatima.scenario_path' => '/tmp/ogamex-missing-scenarios']);

    expect(fn (): string => app(FatimaScenarioTemplate::class)->assetsJson())
        ->toThrow(RuntimeException::class, 'The FAtiMA scenario fixture is missing');
});

test('an unset scenario path resolves to the fixture the module ships', function (): void {
    // With the module enabled the key exists holding null, and a stored null beats
    // config()'s default argument. Relying on that default resolved the scenario to the
    // filesystem root, so every appraisal silently degraded to native in an enabled
    // installation while the suite stayed green against an absent key.
    config(['ai.cognition.fatima.scenario_path' => null]);

    $template = app(FatimaScenarioTemplate::class);

    expect(strlen($template->assetsJson()))->toBeGreaterThan(0)
        ->and(json_decode($template->scenarioJson(), true))->toBeArray();
});

test('a blank scenario path resolves to the fixture the module ships', function (): void {
    config(['ai.cognition.fatima.scenario_path' => '   ']);

    expect(strlen(app(FatimaScenarioTemplate::class)->assetsJson()))->toBeGreaterThan(0);
});

test('a configured scenario path overrides the fixture the module ships', function (): void {
    $directory = sys_get_temp_dir() . '/ogamex-fatima-scenario-' . uniqid();
    mkdir($directory);
    file_put_contents($directory . '/ogame-cognition-assets.json', '{"characters":[]}');

    config(['ai.cognition.fatima.scenario_path' => $directory . '/']);

    try {
        // The trailing separator is tolerated, and the override wins over the fixture.
        expect(app(FatimaScenarioTemplate::class)->assetsJson())->toBe('{"characters":[]}');
    } finally {
        unlink($directory . '/ogame-cognition-assets.json');
        rmdir($directory);
    }
});

test('the module owned scenario fixture supplies the character that the archetype names', function (): void {
    $template = app(FatimaScenarioTemplate::class);
    $scenario = json_decode($template->scenarioJson(), true);

    $characters = array_column($scenario['root']['Characters'], 'KnowledgeBase');

    expect(array_column($characters, 'Perspective'))
        ->toEqualCanonicalizing(array_map(fn (AiArchetype $archetype): string => $archetype->name, AiArchetype::cases()))
        // The fixture is cached, so repeated reads do not re-read the file.
        ->and($template->scenarioJson())->toBe($template->scenarioJson());
});

test('a native social response that is not an acceptance is never overridden', function (AiSocialExchangeType $type, array $terms): void {
    Http::fake();
    $counterparty = $this->createUser();
    $source = fatimaObservation($this->currentUserId, $counterparty->id, 'declined-' . $type->value);

    $exchange = app(RecordAiSocialExchangeAction::class)->handle(
        $this->currentUserId,
        $counterparty->id,
        $source->id,
        $type,
        $terms,
    );

    $evaluation = app(EvaluateAiSocialExchangeAction::class)
        ->handle($exchange->id, 0, CarbonImmutable::parse('2026-09-11 13:00:00 UTC'));

    expect($evaluation?->response)->not->toBe(AiSocialResponse::Accept);

    // The driver is never consulted, because it may only withhold an acceptance.
    Http::assertNothingSent();
})->with([
    'missing amount' => [AiSocialExchangeType::HelpRequest, [AiSocialTerm::Amount->value => 0]],
    'unsupported trade' => [AiSocialExchangeType::TradeOffer, [AiSocialTerm::Resource->value => 'metal', AiSocialTerm::Amount->value => 10]],
]);

test('the driver withholds an exchange the native rules would have accepted', function (): void {
    Log::spy();
    $counterparty = $this->createUser();
    $this->fakeFatimaDriver();

    // Trust and affinity of 0.3 reduce to a rapport of 3, which is the authored threshold the
    // counterparty has to clear; the native rules accept the greeting regardless.
    $evaluation = app(EvaluateAiSocialExchangeAction::class)
        ->handle(greetedFatimaExchange($this->currentUserId, $counterparty->id, 0.3, 0.3), 100, CarbonImmutable::parse('2026-09-11 13:00:00 UTC'));

    expect($evaluation?->response)->toBe(AiSocialResponse::Reject)
        ->and($evaluation?->response_reason)->toBe(AiSocialResponseReason::SocialExchangeVolition);

    Log::shouldHaveReceived('info')->once();

    // The counterparty is addressed through a name the module derives from its own identity.
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/socialexchanges')
        && $request->data() === ['target' => 'Player' . $counterparty->id]);

    // Trust and affinity are reduced to one rapport value on the driver's scale.
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/beliefs')
        && $request->data() === ['name' => 'RapportLevel(SELF, Player' . $counterparty->id . ')', 'value' => '3']);
});

test('a usable volition leaves the native acceptance in place', function (): void {
    $counterparty = $this->createUser();
    $this->fakeFatimaDriver();

    // A rapport of 9 clears the authored threshold, so CiF offers a volition and the native
    // acceptance stands rather than being questioned.
    $evaluation = app(EvaluateAiSocialExchangeAction::class)
        ->handle(greetedFatimaExchange($this->currentUserId, $counterparty->id, 0.9, 0.9), 100, CarbonImmutable::parse('2026-09-11 13:00:00 UTC'));

    expect($evaluation?->response)->toBe(AiSocialResponse::Accept);
});

test('an unusable driver signal leaves the native acceptance in place', function (string $probe): void {
    $counterparty = $this->createUser();
    $this->fakeFatimaDriver(['socialexchanges' => $this->driverProbe($probe)]);

    $evaluation = app(EvaluateAiSocialExchangeAction::class)
        ->handle(greetedFatimaExchange($this->currentUserId, $counterparty->id, 0.3, 0.3), 100, CarbonImmutable::parse('2026-09-11 13:00:00 UTC'));

    expect($evaluation?->response)->toBe(AiSocialResponse::Accept);
})->with([
    'no authored exchange' => ['fatima.socialexchanges.none'],
    'a different exchange' => ['fatima.socialexchanges.other'],
    // The endpoint answers a JSON object rather than a list when it cannot resolve the
    // scenario, instance or character.
    'a non-list payload' => ['fatima.socialexchanges.non_list'],
    'a malformed entry' => ['fatima.socialexchanges.malformed_entry'],
    'a non-numeric volition' => ['fatima.socialexchanges.non_numeric_volition'],
]);

test('an unreachable social driver leaves the native acceptance in place', function (): void {
    $counterparty = $this->createUser();
    Http::fake(['*' => fn () => throw new ConnectionException('refused')]);

    $evaluation = app(EvaluateAiSocialExchangeAction::class)
        ->handle(greetedFatimaExchange($this->currentUserId, $counterparty->id, 0.3, 0.3), 100, CarbonImmutable::parse('2026-09-11 13:00:00 UTC'));

    expect($evaluation?->response)->toBe(AiSocialResponse::Accept);
});

test('the social driver declines to guess without a persona or a counterparty', function (AiArchetype|null $archetype, int|null $counterpartyPlayerId): void {
    Http::fake();

    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(app()->makeWith(SocialExchangeContext::class, [
        'exchangeId' => 1,
        'type' => AiSocialExchangeType::Greeting,
        'terms' => [],
        'trust' => 1.0,
        'affinity' => 1.0,
        'threat' => 0.0,
        'outstandingCommitments' => 0,
        'availableAmount' => 0.0,
        'evaluatedAt' => CarbonImmutable::parse('2026-09-11 13:00:00 UTC'),
        'archetype' => $archetype,
        'counterpartyPlayerId' => $counterpartyPlayerId,
    ]));

    expect($evaluation->response)->toBe(AiSocialResponse::Accept);

    Http::assertNothingSent();
})->with([
    'no persona' => [null, 7],
    'no counterparty' => [AiArchetype::Miner, null],
]);

test('the module provider wires one shared cognition session behind both contracts', function (): void {
    app()->makeWith(AIServiceProvider::class, ['app' => $this->app])->register();
    config(['ai.cognition.driver' => 'fatima']);

    expect(app(FatimaCognitionSession::class))->toBe(app(FatimaCognitionSession::class))
        ->and(app(FatimaScenarioTemplate::class))->toBe(app(FatimaScenarioTemplate::class))
        ->and(app(AffectEngine::class))->toBeInstanceOf(FatimaAffectEngine::class)
        ->and(app(SocialCognition::class))->toBeInstanceOf(FatimaSocialCognition::class);
});

test('the module provider binds the native pair when no driver is configured', function (): void {
    app()->makeWith(AIServiceProvider::class, ['app' => $this->app])->register();
    config(['ai.cognition.driver' => AiCognitionDriver::Native->value]);

    expect(app(AffectEngine::class))->not->toBeInstanceOf(FatimaAffectEngine::class)
        ->and(app(SocialCognition::class))->not->toBeInstanceOf(FatimaSocialCognition::class);
});

test('the module provider reports an unrecognised driver name once', function (): void {
    Log::spy();
    app()->makeWith(AIServiceProvider::class, ['app' => $this->app])->register();
    config(['ai.cognition.driver' => 'unknown']);

    expect(app(AffectEngine::class))->not->toBeInstanceOf(FatimaAffectEngine::class)
        ->and(app(SocialCognition::class))->not->toBeInstanceOf(FatimaSocialCognition::class);

    Log::shouldHaveReceived('warning')->once();
});
