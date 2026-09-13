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
use Modules\AI\Support\AffectEngineSelector;
use Modules\AI\Support\ExperienceEngineSelector;
use Modules\AI\Support\LongTermMemorySelector;
use Modules\AI\Support\SocialCognitionSelector;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * Absence is free (gate A1). No Phase 3 baseline may depend on a sidecar, so every
 * optional contract must resolve to its native implementation when nothing is
 * configured, and must never attempt a request while doing so.
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

test('every optional contract resolves to its native implementation when nothing is configured', function (string $contract, string $selector, string $native): void {
    Http::fake();

    expect(app($selector)->resolve())->toBeInstanceOf($native)
        ->and(app($contract))->toBeInstanceOf($native);

    Http::assertNothingSent();
})->with([
    'affect' => [AffectEngine::class, AffectEngineSelector::class, NativeAffectEngine::class],
    'social cognition' => [SocialCognition::class, SocialCognitionSelector::class, NativeSocialCognition::class],
    'long-term memory' => [LongTermMemory::class, LongTermMemorySelector::class, NativeLongTermMemory::class],
    'experience' => [ExperienceEngine::class, ExperienceEngineSelector::class, NativeExperienceEngine::class],
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
