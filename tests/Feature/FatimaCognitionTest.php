<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
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
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The FAtiMA driver is opt-in. Every failure branch must leave the native appraisal
 * and the native social response in place, because both feed ordinary gameplay and a
 * stopped sidecar must never change what an AI decides.
 */
beforeEach(function (): void {
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

/** @param array<string, mixed> $emotions */
function fakeFatima(array $emotions = [], mixed $socialExchanges = [], int|null &$reloads = null): void
{
    $reloads = 0;

    Http::fake([
        '*/scenarios' => function () use (&$reloads) {
            $reloads++;

            return Http::response('"Scenario named \'OgameCognition\' created containing \'5\' characters"');
        },
        '*/socialexchanges' => Http::response($socialExchanges),
        '*/emotions' => Http::response($emotions),
        '*/beliefs' => Http::response('"Belief updated."'),
        '*/perceptions' => Http::response('"1 event(s) perceived by Miner"'),
    ]);
}

function fatimaEmotions(string $type, float $intensity, string $cause): array
{
    return [
        'Name' => 'Miner',
        'Mood' => 0.0,
        'Emotions' => [[
            'Type' => $type,
            'Intensity' => $intensity,
            'Target' => 'Other',
            'CauseEventId' => 1,
            'CauseEventName' => $cause,
        ]],
    ];
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

/** Seeds a persona and a trusted relationship, so the native engine accepts the request. */
function acceptedFatimaHelpRequest(int $playerId, int $counterpartyId): int
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 1,
        'enabled' => true,
    ]);

    $source = fatimaObservation($playerId, $counterpartyId, 'accepted-' . $counterpartyId);
    app(RecordAiRelationshipInteractionAction::class)->handle(
        $playerId,
        $counterpartyId,
        $source->id,
        CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        0.9,
        0.0,
        0.9,
    );

    return app(RecordAiSocialExchangeAction::class)->handle(
        $playerId,
        $counterpartyId,
        $source->id,
        AiSocialExchangeType::HelpRequest,
        [AiSocialTerm::Amount->value => 10],
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

test('the driver appraises each stimulus branch from signed OCC values', function (string $type, float $harm, float $aid, float $threat, AiAffectEmotion $emotion): void {
    $branch = match (true) {
        $aid > $harm => 'Aid',
        $threat > $harm => 'Threaten',
        default => 'Harm',
    };
    $event = sprintf('Event(Action-End, Other, %s, Miner)', $branch);
    fakeFatima(fatimaEmotions($type, 0.4, $event), [], $reloads);

    config(['ai.cognition.driver' => 'fatima']);
    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, $harm, $aid, $threat));

    expect($appraisal->emotion)->toBe($emotion)
        ->and($appraisal->intensity)->toBe(0.4)
        // Each appraisal resets the driver, so mood and goal state cannot accumulate.
        ->and($reloads)->toBe(1);

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/perceptions')
        && $request->data() === [$event]);
})->with([
    'aid becomes gratitude' => ['Gratitude', 0.0, 0.5, 0.0, AiAffectEmotion::Gratitude],
    'harm becomes anger' => ['Anger', 0.4, 0.0, 0.0, AiAffectEmotion::Anger],
    'threat becomes fear' => ['Fear', 0.0, 0.0, 0.6, AiAffectEmotion::Fear],
    'aid outranks harm' => ['Gratitude', 0.2, 0.5, 0.0, AiAffectEmotion::Gratitude],
    'threat outranks harm' => ['Fear', 0.2, 0.0, 0.6, AiAffectEmotion::Fear],
]);

test('the signed OCC value follows the branch that was actually observed', function (): void {
    fakeFatima(['Name' => 'Miner', 'Mood' => 0.0, 'Emotions' => []], [], $reloads);

    // Harm answers despite the threat, because threat must outrank harm to win.
    app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.4, 0.0, 0.2));

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/beliefs')
        && $request->data() === ['name' => 'StimulusDesirability(SELF, Other)', 'value' => '-0.4']);
});

test('the aid branch is sent as a positive OCC desirability', function (): void {
    fakeFatima(['Name' => 'Miner', 'Mood' => 0.0, 'Emotions' => []], [], $reloads);

    app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0));

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/beliefs')
        && $request->data() === ['name' => 'StimulusDesirability(SELF, Other)', 'value' => '0.5']);
});

test('the threat branch lowers the goal success probability instead of the desirability', function (): void {
    fakeFatima(['Name' => 'Miner', 'Mood' => 0.0, 'Emotions' => []], [], $reloads);

    app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.0, 0.6));

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/beliefs')
        && $request->data() === ['name' => 'StimulusThreat(SELF, Other)', 'value' => '-0.6']);
});

test('an emotion the module cannot represent is declined rather than approximated', function (): void {
    Log::spy();
    fakeFatima(fatimaEmotions('Joy', 0.9, 'Event(Action-End, Other, Aid, Miner)'), [], $reloads);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0));

    // The native engine answers instead, so no invented meaning reaches module state.
    expect($appraisal->emotion)->toBe(AiAffectEmotion::Gratitude)
        ->and($appraisal->intensity)->toBe(0.5);

    Log::shouldHaveReceived('info')->once();
});

test('an emotion caused by a different event is ignored', function (): void {
    fakeFatima(fatimaEmotions('Anger', 0.9, 'Event(Action-End, Other, Harm, Miner)'), [], $reloads);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0));

    expect($appraisal->emotion)->toBe(AiAffectEmotion::Gratitude);
});

test('an empty emotional pool falls back to the native appraisal', function (): void {
    fakeFatima(['Name' => 'Miner', 'Mood' => 0.0, 'Emotions' => []], [], $reloads);

    expect(app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0))->intensity)->toBe(0.5);
});

test('a normalised intensity is clamped to the module ceiling', function (): void {
    config(['ai.cognition.fatima.intensity_ceiling' => 0.75]);
    fakeFatima(fatimaEmotions('Anger', 9.0, 'Event(Action-End, Other, Harm, Miner)'), [], $reloads);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.4, 0.0, 0.0));

    expect($appraisal->intensity)->toBe(0.75);
});

test('a malformed driver payload falls back to the native appraisal', function (mixed $payload): void {
    Http::fake([
        '*/scenarios' => Http::response('"created"'),
        '*/emotions' => Http::response($payload),
    ]);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0));

    expect($appraisal->emotion)->toBe(AiAffectEmotion::Gratitude)
        ->and($appraisal->intensity)->toBe(0.5);
})->with([
    // The driver reports failures as a plain JSON string while still answering HTTP 200.
    'error text' => ['"There are already stored property values that will collide"'],
    'missing pool' => [['Name' => 'Miner', 'Mood' => 0.0]],
    'non-object pool' => [['Name' => 'Miner', 'Emotions' => 'none']],
    'malformed entry' => [['Name' => 'Miner', 'Emotions' => [['Type' => 'Anger', 'Intensity' => 'high']]]],
    'missing cause event' => [['Name' => 'Miner', 'Emotions' => [['Type' => 'Anger', 'Intensity' => 1.0]]]],
]);

test('an unreachable driver falls back to the native appraisal', function (): void {
    Http::fake(['*' => fn () => throw new ConnectionException('Connection refused')]);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0));

    expect($appraisal->emotion)->toBe(AiAffectEmotion::Gratitude)
        ->and($appraisal->intensity)->toBe(0.5);
});

test('a driver server error falls back to the native appraisal', function (): void {
    Http::fake(['*' => Http::response('boom', 500)]);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0));

    expect($appraisal->emotion)->toBe(AiAffectEmotion::Gratitude)
        ->and($appraisal->intensity)->toBe(0.5);
});

test('the driver is skipped while its circuit is open and retried once it clears', function (): void {
    config(['ai.cognition.circuit.failures' => 1]);
    // The driver answers failures with HTTP 200 and a plain string, so this is a
    // recorded request that still fails the contract.
    Http::fake(['*' => Http::response('"broken"')]);

    $engine = app(AffectEngine::class);
    // The first contract failure reaches the threshold, so the circuit opens.
    $engine->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0));

    Http::fake(['*' => Http::response('"broken"')]);
    expect($engine->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0))->emotion)->toBe(AiAffectEmotion::Gratitude);
    expect(Http::recorded())->toHaveCount(0);

    Cache::forget('ai:cognition:fatima:open');
    Cache::forget('ai:cognition:fatima:failures');

    expect($engine->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.0, 0.5, 0.0))->emotion)->toBe(AiAffectEmotion::Gratitude);
    expect(Http::recorded())->not->toHaveCount(0);
});

test('a contested session lock degrades to the native appraisal', function (): void {
    Log::spy();
    fakeFatima(fatimaEmotions('Anger', 0.4, 'Event(Action-End, Other, Harm, Miner)'), [], $reloads);

    // Another appraisal holds the character, so this one cannot run within its own
    // timeout and must not risk interleaving state on a shared character.
    $held = Cache::lock('ai:cognition:fatima', 30);
    $held->acquire();
    config(['ai.cognition.fatima.lock_seconds' => 1]);

    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(fatimaStimulus(AiArchetype::Miner, 0.4, 0.0, 0.0));

    $held->release();

    expect($appraisal->emotion)->toBe(AiAffectEmotion::Anger)
        ->and($appraisal->intensity)->toEqualWithDelta(0.08, 1e-9)
        ->and($reloads)->toBe(0);

    Log::shouldHaveReceived('warning')->once();
});

test('a missing scenario fixture aborts loudly instead of appraising nothing', function (): void {
    config(['ai.cognition.fatima.scenario_path' => '/tmp/ogamex-missing-scenarios']);

    expect(fn (): string => app(FatimaScenarioTemplate::class)->assetsJson())
        ->toThrow(RuntimeException::class, 'The FAtiMA scenario fixture is missing');
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
    fakeFatima([], [['Name' => 'CooperativeMove', 'Step' => 'Start', 'Volitions' => []]], $reloads);

    $evaluation = app(EvaluateAiSocialExchangeAction::class)
        ->handle(acceptedFatimaHelpRequest($this->currentUserId, $counterparty->id), 100, CarbonImmutable::parse('2026-09-11 13:00:00 UTC'));

    expect($evaluation?->response)->toBe(AiSocialResponse::Reject)
        ->and($evaluation?->response_reason)->toBe(AiSocialResponseReason::SocialExchangeVolition);

    Log::shouldHaveReceived('info')->once();

    // The counterparty is addressed through a name the module derives from its own identity.
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/socialexchanges')
        && $request->data() === ['target' => 'Player' . $counterparty->id]);

    // Trust and affinity are reduced to one rapport value on the driver's scale.
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/beliefs')
        && $request->data() === ['name' => 'RapportLevel(SELF, Player' . $counterparty->id . ')', 'value' => '9']);
});

test('a usable volition leaves the native acceptance in place', function (): void {
    $counterparty = $this->createUser();
    fakeFatima([], [['Name' => 'CooperativeMove', 'Step' => 'Start', 'Volitions' => ['*' => 7.0]]], $reloads);

    $evaluation = app(EvaluateAiSocialExchangeAction::class)
        ->handle(acceptedFatimaHelpRequest($this->currentUserId, $counterparty->id), 100, CarbonImmutable::parse('2026-09-11 13:00:00 UTC'));

    expect($evaluation?->response)->toBe(AiSocialResponse::Accept);
});

test('an unusable driver signal leaves the native acceptance in place', function (mixed $exchanges): void {
    $counterparty = $this->createUser();
    fakeFatima([], $exchanges, $reloads);

    $evaluation = app(EvaluateAiSocialExchangeAction::class)
        ->handle(acceptedFatimaHelpRequest($this->currentUserId, $counterparty->id), 100, CarbonImmutable::parse('2026-09-11 13:00:00 UTC'));

    expect($evaluation?->response)->toBe(AiSocialResponse::Accept);
})->with([
    'no authored exchange' => [[]],
    'a different exchange' => [[['Name' => 'SomethingElse', 'Step' => 'Start', 'Volitions' => []]]],
    // The endpoint answers a JSON object rather than a list when it cannot resolve the
    // scenario, instance or character.
    'a non-list payload' => [['Message' => 'The given key was not present in the dictionary.']],
    'a malformed entry' => [[['Name' => 'CooperativeMove', 'Step' => 'Start']]],
    'a non-numeric volition' => [[['Name' => 'CooperativeMove', 'Step' => 'Start', 'Volitions' => ['*' => 'many']]]],
]);

test('an unreachable social driver leaves the native acceptance in place', function (): void {
    $counterparty = $this->createUser();
    Http::fake(['*' => fn () => throw new ConnectionException('refused')]);

    $evaluation = app(EvaluateAiSocialExchangeAction::class)
        ->handle(acceptedFatimaHelpRequest($this->currentUserId, $counterparty->id), 100, CarbonImmutable::parse('2026-09-11 13:00:00 UTC'));

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
