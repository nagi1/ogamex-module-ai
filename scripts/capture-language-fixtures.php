<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

/**
 * Records what the language provider really answers, verbatim, into the fixture the LLM
 * feature tests replay through `Http::fake`.
 *
 *   php scripts/capture-language-fixtures.php --confirm
 *
 * Why this exists: the language-gateway tests must exercise the module's parser against the
 * real wire format. A response literal hand-written in a test drifts from what the provider
 * actually sends, and the suite then stays green while production parsing breaks. Capturing
 * the real body once, into `tests/Fixtures/llm/`, and replaying it afterwards keeps the tests
 * offline and deterministic without inventing the payload shape.
 *
 * The request body matches the one the module's own gateway sends through the Laravel AI SDK
 * for a structured reply: a chat-completion with `response_format: json_object` and the same
 * structured envelope the reply agent's schema defines.
 *
 * Run inside the application container with provider credentials in the environment:
 *
 *   docker compose -f local-docker-dev/docker-compose.yml exec ogamex-app \
 *     php Modules/AI/scripts/capture-language-fixtures.php --confirm
 */

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (!in_array('--confirm', $argv, true)) {
    fwrite(STDERR, "this rewrites tests/Fixtures/llm/*.json from the live provider; pass --confirm\n");

    exit(2);
}

$provider = (string) config('ai.language.provider', 'deepseek');
$model = (string) config('ai.language.model', 'deepseek-flash');
$baseUrl = rtrim((string) config('ai.providers.'.$provider.'.url', 'https://api.deepseek.com/v1'), '/');
$key = (string) config('ai.providers.'.$provider.'.key', '');

if ($key === '') {
    fwrite(STDERR, "no credential for provider [{$provider}]; set ai.providers.{$provider}.key\n");

    exit(2);
}

$body = [
    'model' => $model,
    'response_format' => ['type' => 'json_object'],
    'messages' => [
        ['role' => 'system', 'content' => 'You are a helpful assistant.'],
        ['role' => 'user', 'content' => 'Reply to: "Can you spare some metal?" with a short refusal.'],
    ],
];

$response = Http::baseUrl($baseUrl)
    ->withToken($key)
    ->timeout((int) config('ai.language.timeout_seconds', 20))
    ->post('chat/completions', $body);

$fixture = [
    '_meta' => [
        'captured_from' => $baseUrl.'/chat/completions',
        'model' => $model,
        'response_format' => 'json_object',
        'captured_at' => now()->toIso8601String(),
    ],
    'structured_reply' => [
        'status' => $response->status(),
        'body' => $response->json(),
    ],
];

$path = dirname(__DIR__).'/tests/Fixtures/llm/'.$provider.'-'.$model.'.json';
file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

printf("captured %s -> %s\n", $response->status(), $path);
