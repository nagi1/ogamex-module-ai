<?php

use Modules\AI\Ai\Doctrine\ClaimType;
use Modules\AI\Ai\Doctrine\DefMinerStance;
use Modules\AI\Ai\Doctrine\StanceConfidence;

beforeEach(function (): void {
    $stanceFile = dirname(__DIR__, 3) . '/app/Ai/Doctrine/DefMinerStance.php';

    // Loaded from its pinned path: an autoload miss must not be what decides
    // whether the stance behaviour is right.
    if (is_file($stanceFile)) {
        require_once $stanceFile;
    }
});

it('records the def-miner school position as a documented claim', function (): void {
    expect(app(DefMinerStance::class)->schoolPosition())->toBe(ClaimType::Documented);
});

it('records the def-miner activity rhythm as an anecdotal remark', function (): void {
    expect(app(DefMinerStance::class)->activityRhythm())->toBe(ClaimType::Anecdotal);
});

it('records the def-miner implicit mechanics as contested', function (): void {
    expect(app(DefMinerStance::class)->implicitMechanics())->toBe(ClaimType::Contested);
});

it('states medium confidence in the play-style position', function (): void {
    expect(app(DefMinerStance::class)->confidence())->toBe(StanceConfidence::Medium);
});

it('states no numeric threshold for the play style', function (): void {
    expect(app(DefMinerStance::class)->numericThresholds())->toBe([]);
});

it('raises instead of returning a build ratio the source does not state', function (): void {
    expect(fn () => app(DefMinerStance::class)->buildRatio())->toThrow(LogicException::class);
});
