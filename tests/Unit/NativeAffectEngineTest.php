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

    $gratitude = $engine->appraiseObservedEvent(app()->makeWith(ObservedStimulus::class, [
        'archetype' => AiArchetype::Miner,
        'harm' => 0,
        'aid' => 0.5,
        'threat' => 0,
        'relationshipTrust' => 0.5,
    ]));
    $fear = $engine->appraiseObservedEvent(app()->makeWith(ObservedStimulus::class, [
        'archetype' => AiArchetype::Miner,
        'harm' => 0.2,
        'aid' => 0,
        'threat' => 0.8,
        'relationshipTrust' => 0,
    ]));

    expect($engine)->toBeInstanceOf(NativeAffectEngine::class)
        ->and($gratitude->emotion)->toBe(AiAffectEmotion::Gratitude)
        ->and($gratitude->intensity)->toBe(0.75)
        ->and($fear->emotion)->toBe(AiAffectEmotion::Fear)
        ->and($fear->intensity)->toBe(0.8);
});

test('harm appraisal varies by archetype while remaining bounded', function (AiArchetype $archetype, float $expectedIntensity): void {
    $appraisal = app(AffectEngine::class)->appraiseObservedEvent(app()->makeWith(ObservedStimulus::class, [
        'archetype' => $archetype,
        'harm' => 4,
        'aid' => 0,
        'threat' => 0,
        'relationshipTrust' => 0,
    ]));

    expect($appraisal->emotion)->toBe(AiAffectEmotion::Anger)
        ->and($appraisal->intensity)->toBe($expectedIntensity);
})->with([
    'fleeter' => [AiArchetype::Fleeter, 1.0],
    'turtle' => [AiArchetype::Turtle, 1.0],
    'miner' => [AiArchetype::Miner, 0.8],
    'trader' => [AiArchetype::Trader, 0.8],
    'casual' => [AiArchetype::Casual, 0.8],
]);
