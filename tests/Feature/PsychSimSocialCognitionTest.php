<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\SocialExchangeContext;
use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Enums\AiSocialResponseReason;
use Modules\AI\Infrastructure\Cognition\PsychSimClient;
use Modules\AI\Infrastructure\Cognition\PsychSimSocialCognition;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\DriverCircuitBreaker;
use Modules\AI\Support\SocialCognitionSelector;
use Modules\AI\Support\SystemAiClock;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The PsychSim driver is opt-in. Every failure branch must leave the native social
 * response in place, because it feeds ordinary gameplay and a stopped sidecar must never
 * change what an AI decides.
 *
 * The driver is a pure function of the counterparty's defection incentive — the module
 * maps its own threat rating into it — so the fake here replays the two answers the
 * deployed sidecar really gives (`cooperate` below the threshold, `defect` above it) and
 * nothing in this file states the threshold itself.
 */
beforeEach(function (): void {
    config(['ai.cognition.mode' => 'external']);
    config(['ai.cognition.driver' => 'psychsim']);
    app()->bind(AiClock::class, SystemAiClock::class);

    // The module's own bindings are not active in this suite, so the driver is wired
    // here exactly as the provider wires it. Routing through the selector keeps the
    // real selection logic under test rather than a test-local copy.
    app()->bind(SocialCognition::class, fn (): SocialCognition => app(SocialCognitionSelector::class)->resolve());
    app()->when(PsychSimClient::class)
        ->needs(DriverCircuitBreaker::class)
        ->give(fn (): DriverCircuitBreaker => app()->makeWith(DriverCircuitBreaker::class, [
            'driver' => AiCognitionDriver::PsychSim->value,
        ]));
});

function psychsimExchange(float $threat, int|null $counterparty = 7, AiSocialExchangeType $type = AiSocialExchangeType::Greeting): SocialExchangeContext
{
    return app()->makeWith(SocialExchangeContext::class, [
        'exchangeId' => 1,
        'type' => $type,
        'terms' => [],
        'trust' => 1.0,
        'affinity' => 1.0,
        'threat' => $threat,
        'outstandingCommitments' => 0,
        'availableAmount' => 0.0,
        'evaluatedAt' => CarbonImmutable::parse('2026-09-11 13:00:00 UTC'),
        'counterpartyPlayerId' => $counterparty,
    ]);
}

test('the psychsim driver resolves when it is configured', function (): void {
    Http::fake();

    expect(app(SocialCognitionSelector::class)->resolve())->toBeInstanceOf(PsychSimSocialCognition::class);

    Http::assertNothingSent();
});

test('the driver withholds an exchange the native rules would have accepted', function (): void {
    Log::spy();
    Http::fake(['*/evaluate' => Http::response(['decision' => 'defect'])]);

    // A greeting is accepted by the native rules whatever the counterparty's standing, so
    // the driver's depth-one stance is the only thing that can withhold it.
    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(psychsimExchange(threat: 0.75));

    expect($evaluation->response)->toBe(AiSocialResponse::Reject)
        ->and($evaluation->reason)->toBe(AiSocialResponseReason::SocialExchangeVolition);

    Log::shouldHaveReceived('info')->once();

    // The module maps its own threat rating into the counterparty's defection incentive.
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/evaluate')
        && $request->data() === ['temptation' => 1.5]);
});

test('a cooperate stance leaves the native acceptance in place', function (): void {
    Http::fake(['*/evaluate' => Http::response(['decision' => 'cooperate'])]);

    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(psychsimExchange(threat: 0.25));

    expect($evaluation->response)->toBe(AiSocialResponse::Accept);
});

test('an unusable driver signal leaves the native acceptance in place', function (array $body): void {
    Http::fake(['*/evaluate' => Http::response($body)]);

    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(psychsimExchange(threat: 0.75));

    expect($evaluation->response)->toBe(AiSocialResponse::Accept);
})->with([
    'a non-decision value' => [['decision' => 'maybe']],
    'a missing decision' => [[]],
    'a non-object payload' => [['unexpected']],
]);

test('an unreachable driver leaves the native acceptance in place', function (): void {
    Http::fake(['*' => fn () => throw new ConnectionException('refused')]);

    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(psychsimExchange(threat: 0.75));

    expect($evaluation->response)->toBe(AiSocialResponse::Accept);
});

test('a native social response that is not an acceptance is never overridden', function (): void {
    Http::fake();

    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(psychsimExchange(
        threat: 0.75,
        type: AiSocialExchangeType::TradeOffer,
    ));

    expect($evaluation->response)->not->toBe(AiSocialResponse::Accept);

    // The driver is never consulted, because it may only withhold an acceptance.
    Http::assertNothingSent();
});

test('the driver declines to guess without a counterparty', function (): void {
    Http::fake();

    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(psychsimExchange(threat: 0.75, counterparty: null));

    expect($evaluation->response)->toBe(AiSocialResponse::Accept);

    Http::assertNothingSent();
});

test('the configured native mode ignores the psychsim setting entirely', function (): void {
    Http::fake();
    config(['ai.cognition.mode' => 'native']);

    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(psychsimExchange(threat: 0.75));

    expect($evaluation->response)->toBe(AiSocialResponse::Accept);

    Http::assertNothingSent();
});
