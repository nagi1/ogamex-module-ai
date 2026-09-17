<?php

use Illuminate\Support\Facades\Http;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Cognition\NativeAffectEngine;
use Modules\AI\Domain\Conversation\NativeLongTermMemory;
use Modules\AI\Domain\Conversation\NativeSocialCognition;
use Modules\AI\Domain\Experience\NativeExperienceEngine;
use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Enums\AiExperienceDriver;
use Modules\AI\Enums\AiMemoryDriver;
use Modules\AI\Infrastructure\Cognition\HybridAffectEngine;
use Modules\AI\Infrastructure\Cognition\HybridSocialCognition;
use Modules\AI\Infrastructure\Experience\HybridExperienceEngine;
use Modules\AI\Infrastructure\Memory\AgentOsLongTermMemory;
use Modules\AI\Support\AffectEngineSelector;
use Modules\AI\Support\ExperienceEngineSelector;
use Modules\AI\Support\LongTermMemorySelector;
use Modules\AI\Support\SocialCognitionSelector;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * Absence is free (gate A1). No baseline may depend on a sidecar being up: a missing,
 * stopped or misconfigured driver degrades per call to native, and an empty or native
 * driver setting resolves native without ever attempting a request.
 *
 * The module's own bindings are not active in this suite, so they are wired here exactly
 * as AIServiceProvider::register() wires them. Routing through the selectors keeps the
 * real selection logic under test instead of a test-local copy.
 */
beforeEach(function (): void {
    app()->bind(AffectEngine::class, fn (): AffectEngine => app(AffectEngineSelector::class)->resolve());
    app()->bind(SocialCognition::class, fn (): SocialCognition => app(SocialCognitionSelector::class)->resolve());
    app()->bind(LongTermMemory::class, fn (): LongTermMemory => app(LongTermMemorySelector::class)->resolve());
    app()->bind(ExperienceEngine::class, fn (): ExperienceEngine => app(ExperienceEngineSelector::class)->resolve());
});

test('every optional contract resolves to its hybrid default when nothing is configured', function (string $contract, string $selector, string $default): void {
    Http::fake();

    expect(app($selector)->resolve())->toBeInstanceOf($default)
        ->and(app($contract))->toBeInstanceOf($default);

    Http::assertNothingSent();
})->with([
    'affect' => [AffectEngine::class, AffectEngineSelector::class, HybridAffectEngine::class],
    'social cognition' => [SocialCognition::class, SocialCognitionSelector::class, HybridSocialCognition::class],
    'long-term memory' => [LongTermMemory::class, LongTermMemorySelector::class, AgentOsLongTermMemory::class],
    'experience' => [ExperienceEngine::class, ExperienceEngineSelector::class, HybridExperienceEngine::class],
]);

test('every optional contract still resolves to native when its setting is empty', function (): void {
    Http::fake();
    config([
        'ai.cognition.driver' => '',
        'ai.cognition.memory.driver' => '',
        'ai.cognition.experience.driver' => '',
    ]);

    expect(app(AffectEngine::class))->toBeInstanceOf(NativeAffectEngine::class)
        ->and(app(SocialCognition::class))->toBeInstanceOf(NativeSocialCognition::class)
        ->and(app(LongTermMemory::class))->toBeInstanceOf(NativeLongTermMemory::class)
        ->and(app(ExperienceEngine::class))->toBeInstanceOf(NativeExperienceEngine::class);

    Http::assertNothingSent();
});

test('the configured native driver is used rather than merely falling back to it', function (): void {
    Http::fake();
    config([
        'ai.cognition.driver' => AiCognitionDriver::Native->value,
        'ai.cognition.memory.driver' => AiMemoryDriver::Native->value,
        'ai.cognition.experience.driver' => AiExperienceDriver::Native->value,
    ]);

    expect(app(AffectEngineSelector::class)->resolve())->toBeInstanceOf(NativeAffectEngine::class)
        ->and(app(SocialCognitionSelector::class)->resolve())->toBeInstanceOf(NativeSocialCognition::class)
        ->and(app(LongTermMemorySelector::class)->resolve())->toBeInstanceOf(NativeLongTermMemory::class)
        ->and(app(ExperienceEngineSelector::class)->resolve())->toBeInstanceOf(NativeExperienceEngine::class);

    Http::assertNothingSent();
});
