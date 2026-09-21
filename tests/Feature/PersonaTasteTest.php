<?php

use Modules\AI\Domain\Persona\PersonaTaste;
use Modules\AI\Domain\Routine\RoutineProfile;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\SeededRandomSource;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

test('the taste is deterministic per seed and bounded to the unit interval', function (): void {
    $random = new SeededRandomSource();

    foreach ([1, 7, 42, 9_001] as $seed) {
        $first = PersonaTaste::fromSeed($seed, $random);
        $again = PersonaTaste::fromSeed($seed, $random);

        expect($again->diligence)->toBe($first->diligence)
            ->and($again->aggression)->toBe($first->aggression)
            ->and($again->sociability)->toBe($first->sociability)
            ->and($first->diligence)->toBeBetween(0.0, 1.0)
            ->and($first->aggression)->toBeBetween(0.0, 1.0)
            ->and($first->sociability)->toBeBetween(0.0, 1.0);
    }
});

test('different seeds are different players, not the same player again', function (): void {
    $random = new SeededRandomSource();

    $diligence = collect(range(0, 20))->map(fn (int $seed): float => PersonaTaste::fromSeed($seed, $random)->diligence)->unique();
    $aggression = collect(range(0, 20))->map(fn (int $seed): float => PersonaTaste::fromSeed($seed, $random)->aggression)->unique();
    $sociability = collect(range(0, 20))->map(fn (int $seed): float => PersonaTaste::fromSeed($seed, $random)->sociability)->unique();

    expect($diligence->count())->toBeGreaterThan(1)
        ->and($aggression->count())->toBeGreaterThan(1)
        ->and($sociability->count())->toBeGreaterThan(1);
});

test('two miners play a different number of sessions a day', function (): void {
    $sessionsPerDay = collect(range(0, 20))->map(function (int $seed): int {
        $profile = app()->makeWith(AiProfile::class, ['attributes' => [
            'player_id' => 1_000_000 + $seed,
            'archetype' => AiArchetype::Miner,
            'skill_band' => AiSkillBand::Standard,
            'random_seed' => $seed,
        ]]);

        return RoutineProfile::fromAiProfile($profile)->sessionsPerDay;
    })->unique();

    expect($sessionsPerDay->count())->toBeGreaterThan(1);
});
