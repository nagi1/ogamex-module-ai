<?php

use Illuminate\Support\Facades\Http;
use Modules\AI\Contracts\LanguageGateway;
use Modules\AI\Domain\Conversation\ConversationContext;
use Modules\AI\Domain\Conversation\LanguageRequest;
use Modules\AI\Domain\Language\AiProviderLadder;
use Modules\AI\Enums\AiLanguageResultStatus;
use Modules\AI\Infrastructure\Language\LaravelAiLanguageGateway;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The language gateway against the real provider wire format, replayed through `Http::fake`.
 *
 * The Laravel AI SDK builds its clients with the Http facade, so faking the provider URL
 * exercises the SDK's own transport and response parser instead of its agent fake — the
 * difference between "the module mapped a real payload" and "the module mapped what the test
 * assumed the payload looked like". The fixture is captured by
 * `scripts/capture-language-fixtures.php`, not written from memory of the docs.
 */

/** @return array{status: int, body: array<string, mixed>} */
function llmFixture(): array
{
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/llm/deepseek-flash-structured.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $fixture['structured_reply'];
}

function deepseekLanguageRequest(): LanguageRequest
{
    return app()->makeWith(LanguageRequest::class, [
        'replyId' => 1,
        'playerId' => 1,
        'counterpartyPlayerId' => 2,
        'requestKey' => 'llm-http-fixture',
        'context' => app()->makeWith(ConversationContext::class, [
            'sections' => [],
            'serialized' => '{"safe":true}',
            'protectedContentFits' => true,
        ]),
        'authorizedSourceMessageIds' => [1],
        'ladder' => app()->makeWith(AiProviderLadder::class, [
            'rungs' => [['provider' => 'deepseek', 'model' => 'deepseek-flash']],
        ]),
        'timeoutSeconds' => 20,
        'maximumReplyCharacters' => 1_200,
    ]);
}

test('the language gateway maps a real DeepSeek response replayed through Http::fake', function (): void {
    app()->bind(LanguageGateway::class, LaravelAiLanguageGateway::class);

    $recorded = llmFixture();
    Http::fake(['api.deepseek.com/*' => Http::response($recorded['body'], $recorded['status'])]);

    $result = app(LanguageGateway::class)->generateConversationReply(deepseekLanguageRequest());

    expect($result->status)->toBe(AiLanguageResultStatus::Completed)
        ->and($result->text)->toBe('Understood - noted.')
        ->and($result->inputTokens)->toBe(128)
        ->and($result->outputTokens)->toBe(12)
        ->and($result->provider)->toBe('deepseek')
        ->and($result->model)->toBe('deepseek-flash');
});

test('a replayed response reporting a cache hit splits the cached input from the uncached', function (): void {
    app()->bind(LanguageGateway::class, LaravelAiLanguageGateway::class);

    $recorded = llmFixture();
    $body = $recorded['body'];
    // DeepSeek reports the cache hit beside the prompt total and the SDK subtracts it out of
    // `promptTokens`, so 128 prompt tokens with 100 served from cache are 28 uncached tokens the
    // module used to record as the whole input.
    $body['usage']['prompt_cache_hit_tokens'] = 100;

    Http::fake(['api.deepseek.com/*' => Http::response($body, $recorded['status'])]);

    $result = app(LanguageGateway::class)->generateConversationReply(deepseekLanguageRequest());

    expect($result->status)->toBe(AiLanguageResultStatus::Completed)
        ->and($result->inputTokens)->toBe(28)
        ->and($result->cachedInputTokens)->toBe(100);
});

test('a provider failure through Http::fake maps to a failed result', function (): void {
    app()->bind(LanguageGateway::class, LaravelAiLanguageGateway::class);

    Http::fake([
        'api.deepseek.com/*' => Http::response(
            ['error' => ['type' => 'server_error', 'message' => 'boom']],
            500,
        ),
    ]);

    $result = app(LanguageGateway::class)->generateConversationReply(deepseekLanguageRequest());

    expect($result->status)->toBe(AiLanguageResultStatus::Failed);
});
