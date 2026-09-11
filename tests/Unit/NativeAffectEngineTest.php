<?php

use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Domain\Cognition\NativeAffectEngine;
use Modules\AI\Domain\Cognition\ObservedStimulus;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Enums\AiArchetype;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    $this->app->bind(AffectEngine::class, NativeAffectEngine::class);
});

test('aid and overwhelming threat receive their deterministic appraisals', function (): void {
    $engine = app(AffectEngine::class);

    $gratitude = $engine->appraiseObservedEvent(new ObservedStimulus(AiArchetype::Miner, 0, 0.5, 0, 0.5));
    $fear = $engine->appraiseObservedEvent(new ObservedStimulus(AiArchetype::Miner, 0.2, 0, 0.8, 0));

    expect($engine)->toBeInstanceOf(NativeAffectEngine::class)
        ->and($gratitude->emotion)->toBe(AiAffectEmotion::Gratitude)
        ->and($gratitude->intensity)->toBe(0.75)
        ->and($fear->emotion)->toBe(AiAffectEmotion::Fear)
        ->and($fear->intensity)->toBe(0.8);
});

test('harm appraisal varies by archetype while remaining bounded', function (AiArchetype $archetype, float $expectedIntensity): void {
    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(new ObservedStimulus($archetype, 4, 0, 0, 0));

    expect($appraisal->emotion)->toBe(AiAffectEmotion::Anger)
        ->and($appraisal->intensity)->toBe($expectedIntensity);
})->with([
    'fleeter' => [AiArchetype::Fleeter, 1.0],
    'turtle' => [AiArchetype::Turtle, 1.0],
    'miner' => [AiArchetype::Miner, 0.8],
    'trader' => [AiArchetype::Trader, 0.8],
    'casual' => [AiArchetype::Casual, 0.8],
]);
