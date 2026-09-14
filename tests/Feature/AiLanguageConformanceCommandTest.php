<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;
use Modules\AI\Ai\Agents\OgameConversationReplyAgent;
use Modules\AI\Contracts\ContextBuilder;
use Modules\AI\Contracts\LanguageGateway;
use Modules\AI\Domain\Conversation\NativeContextBuilder;
use Modules\AI\Infrastructure\Language\LaravelAiLanguageGateway;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;
use Modules\AI\Tests\Support\FixtureAiClock;

require_once __DIR__ . '/../Support/AiQueueModuleTestCase.php';
require_once __DIR__ . '/../Support/FixtureAiClock.php';
require_once __DIR__ . '/../Support/LanguageGatewayTimeout.php';

uses(AiQueueModuleTestCase::class);

beforeEach(function (): void {
    Storage::fake('local');
    config([
        'ai.language.enabled' => true,
        'ai.language.provider' => 'openai',
        'ai.language.model' => 'gpt-5-mini',
        'ai.language.timeout_seconds' => 17,
        'ai.language.maximum_reply_characters' => 1_200,
    ]);
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse('2024-01-01 00:00:00 UTC'),
    ]));
    app()->bind(ContextBuilder::class, NativeContextBuilder::class);
    app()->bind(LanguageGateway::class, LaravelAiLanguageGateway::class);
});

/** @return array{path: string, report: array<string, mixed>} */
function conformanceEvidence(): array
{
    $files = Storage::disk('local')->allFiles('ai-language-conformance');
    $path = $files[0] ?? '';

    return [
        'path' => $path,
        'report' => json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR),
    ];
}

test('the conformance run refuses to contact a provider without explicit confirmation', function (): void {
    OgameConversationReplyAgent::fake()->preventStrayPrompts();

    $this->artisan('ai:language-conformance')->assertExitCode(1);

    OgameConversationReplyAgent::assertNeverPrompted();
    expect(Storage::disk('local')->allFiles('ai-language-conformance'))->toBe([]);
});

test('the conformance run refuses while the language capability is disabled', function (): void {
    config(['ai.language.enabled' => false]);
    OgameConversationReplyAgent::fake()->preventStrayPrompts();

    $this->artisan('ai:language-conformance --confirm')->assertExitCode(1);

    OgameConversationReplyAgent::assertNeverPrompted();
});

test('the smoke run sends one sanitized prompt and records real usage evidence', function (): void {
    OgameConversationReplyAgent::fake([[
        'text' => 'Still around, thanks for asking.',
        'interpretation' => 'none',
        'candidates' => [],
    ]])->preventStrayPrompts();

    $this->artisan('ai:language-conformance --confirm')
        ->expectsOutputToContain('1/1 case(s) completed')
        ->assertExitCode(0);

    OgameConversationReplyAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->provider->name() === 'openai'
        && $prompt->model === 'gpt-5-mini'
        && $prompt->timeout === 17
        && $prompt->contains('Hey, are you still playing on this server?')
        && $prompt->contains('no_tools'));

    $evidence = conformanceEvidence();

    expect($evidence['path'])->toEndWith('.json')
        ->and($evidence['report']['completed_cases'])->toBe(1)
        ->and($evidence['report']['repeated_reply_count'])->toBe(0)
        ->and($evidence['report']['cases'])->toHaveCount(1)
        ->and($evidence['report']['cases'][0]['case'])->toBe('greeting-en')
        ->and($evidence['report']['cases'][0]['status'])->toBe('completed')
        ->and($evidence['report']['cases'][0]['text'])->toBe('Still around, thanks for asking.');
});

test('the corpus run records repetition and fails when a provider attempt does not complete', function (): void {
    $repeated = ['text' => 'Same reply.', 'interpretation' => 'none', 'candidates' => []];

    OgameConversationReplyAgent::fake([
        $repeated,
        $repeated,
        ['text' => 'A different reply.', 'interpretation' => 'none', 'candidates' => []],
        fn () => throw app()->makeWith(RuntimeException::class, ['message' => 'Provider unavailable.']),
    ])->preventStrayPrompts();

    $this->artisan('ai:language-conformance --corpus --confirm')
        ->expectsOutputToContain('3/4 case(s) completed')
        ->assertExitCode(1);

    $report = conformanceEvidence()['report'];

    expect($report['completed_cases'])->toBe(3)
        ->and($report['repeated_reply_count'])->toBe(1)
        ->and($report['cases'])->toHaveCount(4)
        ->and(array_column($report['cases'], 'status'))->toBe(['completed', 'completed', 'completed', 'failed'])
        ->and(array_column($report['cases'], 'locale'))->toBe(['en', 'en', 'en', 'en'])
        ->and($report['cases'][2]['text'])->toBe('A different reply.')
        ->and($report['cases'][3]['characters'])->toBeNull();
});
