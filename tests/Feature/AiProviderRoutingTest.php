<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\GenerateAiReplyAction;
use Modules\AI\Actions\ResolveAiProviderRouteAction;
use Modules\AI\Ai\Agents\OgameConversationReplyAgent;
use Modules\AI\Contracts\ContextBuilder;
use Modules\AI\Contracts\LanguageGateway;
use Modules\AI\Domain\Conversation\ConversationContext;
use Modules\AI\Domain\Conversation\LanguageRequest;
use Modules\AI\Domain\Conversation\NativeContextBuilder;
use Modules\AI\Domain\Language\AiProviderLadder;
use Modules\AI\Enums\AiLanguageResultStatus;
use Modules\AI\Enums\AiLanguageTaskKind;
use Modules\AI\Infrastructure\Language\LaravelAiLanguageGateway;
use Modules\AI\Models\AiLanguageRequest;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\FixtureAiClock;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/FixtureAiClock.php';
require_once __DIR__ . '/../Support/LanguageTestFixtures.php';

uses(IsolatedAccountTestCase::class);

/**
 * Routing decides an order, so every test here is about the order: which rungs survive a window,
 * which survive a missing credential, and what the SDK's own provider list ends up holding.
 */
beforeEach(function (): void {
    config([
        'ai.language.enabled' => true,
        'ai.language.provider' => 'openai',
        'ai.language.model' => 'gpt-5-mini',
        'ai.language.timeout_seconds' => 17,
        'ai.language.maximum_reply_characters' => 1_200,
        'ai.routing.enabled' => true,
        'ai.routing.windows' => [
            'deepseek_peak' => ['days' => [1, 2, 3, 4, 5], 'periods' => [['01:00', '04:00'], ['06:00', '10:00']]],
        ],
        'ai.routing.vendors' => ['deepseek' => ['window' => 'deepseek_peak'], 'openai' => []],
        'ai.routing.ladders' => [
            'conversation_reply' => [
                ['provider' => 'deepseek', 'model' => 'deepseek-flash'],
                ['provider' => 'openai', 'model' => 'gpt-5-mini'],
            ],
            'conformance' => [['provider' => 'deepseek', 'model' => 'deepseek-flash']],
        ],
        // Availability is read from the SDK's own provider config, so the credentials a test sees
        // are the ones it sets rather than whatever this machine happens to have exported.
        'ai.providers.deepseek.key' => 'test-deepseek-key',
        'ai.providers.openai.key' => 'test-openai-key',
    ]);
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse('2024-01-01 00:00:00 UTC'),
    ]));
    app()->bind(ContextBuilder::class, NativeContextBuilder::class);
    app()->bind(LanguageGateway::class, LaravelAiLanguageGateway::class);
});

/** @return list<string> */
function routingRungs(AiProviderLadder $ladder): array
{
    return array_map(static fn (array $rung): string => $rung['provider'] . '/' . $rung['model'], $ladder->rungs());
}

function routingLadder(string $utc, AiLanguageTaskKind $task = AiLanguageTaskKind::ConversationReply): AiProviderLadder
{
    return app(ResolveAiProviderRouteAction::class)->handle($task, CarbonImmutable::parse($utc, 'UTC'));
}

test('routing off keeps the single configured pair, exactly as it behaved before ladders', function (): void {
    config(['ai.routing.enabled' => false]);

    expect(routingRungs(routingLadder('2026-09-14 12:00:00')))->toBe(['openai/gpt-5-mini']);
});

test('a configured ladder is answered in its own order', function (): void {
    expect(routingRungs(routingLadder('2026-09-14 12:00:00')))->toBe(['deepseek/deepseek-flash', 'openai/gpt-5-mini']);
});

test('a conformance run can name its own ladder', function (): void {
    expect(routingRungs(routingLadder('2026-09-14 12:00:00', AiLanguageTaskKind::Conformance)))->toBe(['deepseek/deepseek-flash']);
});

test('a peak-gated rung answers only inside its vendor peak window', function (string $utc, bool $isPeak): void {
    config([
        'ai.routing.ladders.conversation_reply' => [
            ['provider' => 'deepseek', 'model' => 'deepseek-flash', 'during' => 'peak'],
            ['provider' => 'openai', 'model' => 'gpt-5-mini'],
        ],
    ]);

    $rungs = routingRungs(routingLadder($utc));

    expect($rungs)->toBe($isPeak ? ['deepseek/deepseek-flash', 'openai/gpt-5-mini'] : ['openai/gpt-5-mini']);
})->with([
    'peak opens at 01:00 Monday' => ['2026-09-14 01:00:00', true],
    'peak runs to 04:00 Monday' => ['2026-09-14 03:59:00', true],
    'peak ends at 04:00 Monday' => ['2026-09-14 04:00:00', false],
    'second peak opens at 06:00 Monday' => ['2026-09-14 06:00:00', true],
    'second peak ends at 10:00 Monday' => ['2026-09-14 10:00:00', false],
    'Saturday is never peak' => ['2026-09-12 02:00:00', false],
    'Sunday is never peak' => ['2026-09-13 02:00:00', false],
]);

test('an off-peak-gated rung answers only outside the window', function (string $utc, bool $answers): void {
    config([
        'ai.routing.ladders.conversation_reply' => [
            ['provider' => 'deepseek', 'model' => 'deepseek-flash', 'during' => 'off_peak'],
        ],
    ]);

    expect(routingRungs(routingLadder($utc)))->toBe($answers ? ['deepseek/deepseek-flash'] : ['openai/gpt-5-mini']);
})->with([
    'Monday noon is off-peak' => ['2026-09-14 12:00:00', true],
    'Monday 02:00 is peak' => ['2026-09-14 02:00:00', false],
]);

test('a vendor with no credential is dropped instead of called', function (): void {
    config(['ai.providers.openai.key' => null]);

    expect(routingRungs(routingLadder('2026-09-14 12:00:00')))->toBe(['deepseek/deepseek-flash']);
});

test('a peak gate on a vendor with no window is never selected', function (): void {
    config([
        'ai.routing.ladders.conversation_reply' => [['provider' => 'openai', 'model' => 'gpt-5-mini', 'during' => 'peak']],
    ]);

    // OpenAI has no window in this configuration, so the gated rung is skipped and the run falls
    // back to the configured pair rather than calling a vendor the ladder declined to use.
    expect(routingRungs(routingLadder('2026-09-14 02:00:00')))->toBe(['openai/gpt-5-mini']);
});

test('no available credential leaves an empty ladder rather than a vendor with no key', function (): void {
    config(['ai.providers.deepseek.key' => '', 'ai.providers.openai.key' => null, 'ai.language.provider' => 'deepseek']);

    $ladder = routingLadder('2026-09-14 12:00:00');

    expect($ladder->isEmpty())->toBeTrue()
        ->and($ladder->primary())->toBeNull()
        ->and($ladder->toProviderMap())->toBe([]);
});

test('the provider map keeps the first rung of a vendor named twice', function (): void {
    $ladder = app()->makeWith(AiProviderLadder::class, ['rungs' => [
        ['provider' => 'deepseek', 'model' => 'deepseek-flash'],
        ['provider' => 'deepseek', 'model' => 'deepseek-v4-pro'],
        ['provider' => 'openai', 'model' => 'gpt-5-mini'],
    ]]);

    expect($ladder->toProviderMap())->toBe(['deepseek' => 'deepseek-flash', 'openai' => 'gpt-5-mini'])
        ->and($ladder->primary())->toBe(['provider' => 'deepseek', 'model' => 'deepseek-flash']);
});

test('a malformed ladder is a configuration bug rather than a quiet skip', function (Closure $configure, string $message): void {
    $configure();

    expect(fn (): AiProviderLadder => routingLadder('2026-09-14 12:00:00'))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a rung without a model' => [
        fn () => config(['ai.routing.ladders.conversation_reply' => [['provider' => 'deepseek']]]),
        'needs a provider and a model',
    ],
    'a rung that is not an array' => [
        fn () => config(['ai.routing.ladders.conversation_reply' => ['deepseek']]),
        'needs a provider and a model',
    ],
    'an unknown provider' => [
        fn () => config(['ai.routing.ladders.conversation_reply' => [['provider' => 'deepsek', 'model' => 'x']]]),
        'Unknown provider [deepsek]',
    ],
    'an unknown gate' => [
        fn () => config(['ai.routing.ladders.conversation_reply' => [['provider' => 'deepseek', 'model' => 'x', 'during' => 'sometimes']]]),
        'Unknown window gate [sometimes]',
    ],
    'a window the vendor names but the file does not define' => [
        fn () => config(['ai.routing.vendors.deepseek.window' => 'missing_window']),
        'Unknown peak window [missing_window]',
    ],
    'a period that is not a pair' => [
        fn () => config(['ai.routing.windows.deepseek_peak.periods' => [['01:00']]]),
        'must be a [start, end] pair',
    ],
    'a period that is not a time' => [
        fn () => config(['ai.routing.windows.deepseek_peak.periods' => [['1:00', '04:00']]]),
        'must be HH:MM in UTC',
    ],
    'a period that would wrap past midnight' => [
        fn () => config(['ai.routing.windows.deepseek_peak.periods' => [['22:00', '02:00']]]),
        'must not wrap past midnight',
    ],
]);

test('an empty ladder has no rung to attribute', function (): void {
    $ladder = app()->makeWith(AiProviderLadder::class, ['rungs' => []]);

    expect(fn (): array => $ladder->firstOrFail())
        ->toThrow(LogicException::class, 'An empty provider ladder has no rung to attribute.');
});

test('a request with no available vendor refuses before contacting anyone', function (): void {
    OgameConversationReplyAgent::fake()->preventStrayPrompts();

    $result = app(LaravelAiLanguageGateway::class)->generateConversationReply(
        app()->makeWith(LanguageRequest::class, [
            'replyId' => 1,
            'playerId' => 1,
            'counterpartyPlayerId' => 2,
            'requestKey' => 'routing-refusal',
            'context' => app()->makeWith(ConversationContext::class, ['sections' => [], 'serialized' => '{"safe":true}', 'protectedContentFits' => true]),
            'authorizedSourceMessageIds' => [1],
            'ladder' => app()->makeWith(AiProviderLadder::class, ['rungs' => []]),
            'timeoutSeconds' => 17,
            'maximumReplyCharacters' => 1_200,
        ]),
    );

    expect($result->status)->toBe(AiLanguageResultStatus::Failed)
        ->and($result->provider)->toBe('')
        ->and($result->model)->toBe('')
        ->and($result->text)->toBeNull();
});

test('a reply with no available vendor is answered with the authored text and spends no attempt', function (): void {
    config(['ai.providers.deepseek.key' => null, 'ai.providers.openai.key' => null, 'ai.language.provider' => 'deepseek']);
    OgameConversationReplyAgent::fake()->preventStrayPrompts();

    [$reply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I owe you 500 metal.');
    $delivered = app(GenerateAiReplyAction::class)->handle($reply->id);

    expect($delivered)->not->toBeNull()
        ->and(AiLanguageRequest::query()->where('conversation_reply_id', $reply->id)->count())->toBe(0);
});
