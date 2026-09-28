<?php

use Modules\AI\Domain\Persona\AiPersona;
use Modules\AI\Domain\Persona\AiPersonaFactory;
use Modules\AI\Enums\AiActivityBand;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiDefenseDoctrine;
use Modules\AI\Enums\AiEconomicRole;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiStockpileStrategy;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use OGame\Enums\CharacterClass;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    $this->app->bind(RandomSource::class, SeededRandomSource::class);
});

function personaFactory(): AiPersonaFactory
{
    return app(AiPersonaFactory::class);
}

test('the factory generates a typed, deterministic persona per seed', function (): void {
    foreach ([1, 7, 42, 9_001] as $seed) {
        $first = personaFactory()->create(AiArchetype::Miner, AiSkillBand::Standard, $seed);
        $again = personaFactory()->create(AiArchetype::Miner, AiSkillBand::Standard, $seed);

        expect($first)->toBeInstanceOf(AiPersona::class)
            ->and($first->activityBand)->toBeInstanceOf(AiActivityBand::class)
            ->and($first->defenseDoctrine)->toBeInstanceOf(AiDefenseDoctrine::class)
            ->and($first->stockpileStrategy)->toBeInstanceOf(AiStockpileStrategy::class)
            ->and($first->economicRole)->toBeInstanceOf(AiEconomicRole::class)
            ->and($again->activityBand)->toBe($first->activityBand)
            ->and($again->defenseDoctrine)->toBe($first->defenseDoctrine)
            ->and($again->stockpileStrategy)->toBe($first->stockpileStrategy)
            ->and($again->economicRole)->toBe($first->economicRole);
    }
});

test('one archetype is many players, not one doctrine', function (): void {
    $doctrines = collect(range(0, 60))->map(
        fn (int $seed): AiDefenseDoctrine => personaFactory()->create(AiArchetype::Miner, AiSkillBand::Standard, $seed)->defenseDoctrine,
    )->unique();

    expect($doctrines->count())->toBeGreaterThan(1);
});

test('skill shifts the defence doctrine the way a player has learned', function (): void {
    $pick = fn (AiSkillBand $skill): Illuminate\Support\Collection => collect(range(0, 2_000))->map(
        fn (int $seed): AiDefenseDoctrine => personaFactory()->create(AiArchetype::Turtle, $skill, $seed)->defenseDoctrine,
    );

    $veteran = $pick(AiSkillBand::Veteran);
    $novice = $pick(AiSkillBand::Novice);

    expect($veteran->filter(fn (AiDefenseDoctrine $d): bool => $d === AiDefenseDoctrine::Adaptive)->count())
        ->toBeGreaterThan($novice->filter(fn (AiDefenseDoctrine $d): bool => $d === AiDefenseDoctrine::Adaptive)->count())
        ->and($novice->filter(fn (AiDefenseDoctrine $d): bool => $d === AiDefenseDoctrine::Bunker)->count())
        ->toBeGreaterThan($veteran->filter(fn (AiDefenseDoctrine $d): bool => $d === AiDefenseDoctrine::Bunker)->count());
});

test('character class selection is weighted, valid and deterministic', function (): void {
    foreach ([1, 7, 42] as $seed) {
        $first = personaFactory()->characterClass(AiArchetype::Miner, AiSkillBand::Standard, $seed);
        $again = personaFactory()->characterClass(AiArchetype::Miner, AiSkillBand::Standard, $seed);

        expect($first)->toBeInstanceOf(CharacterClass::class)
            ->and($again)->toBe($first);
    }

    // A miner leans on the economy class, a fleeter on the combat class.
    $minerClasses = collect(range(0, 60))->map(
        fn (int $seed): CharacterClass => personaFactory()->characterClass(AiArchetype::Miner, AiSkillBand::Standard, $seed),
    );
    $fleeterClasses = collect(range(0, 60))->map(
        fn (int $seed): CharacterClass => personaFactory()->characterClass(AiArchetype::Fleeter, AiSkillBand::Standard, $seed),
    );

    expect($minerClasses->filter(fn (CharacterClass $c): bool => $c === CharacterClass::COLLECTOR)->count())
        ->toBeGreaterThan($fleeterClasses->filter(fn (CharacterClass $c): bool => $c === CharacterClass::COLLECTOR)->count());
});

test('the profile casts the decomposed columns to their enums', function (): void {
    $profile = AiProfile::create([
        'player_id' => 7_100_001,
        'archetype' => AiArchetype::Turtle,
        'skill_band' => AiSkillBand::Veteran,
        'activity_band' => AiActivityBand::Active,
        'defense_doctrine' => AiDefenseDoctrine::Bunker,
        'stockpile_strategy' => AiStockpileStrategy::GoalSaver,
        'economic_role' => AiEconomicRole::DeutSeller,
        'random_seed' => 1,
    ]);

    expect($profile->activity_band)->toBe(AiActivityBand::Active)
        ->and($profile->defense_doctrine)->toBe(AiDefenseDoctrine::Bunker)
        ->and($profile->stockpile_strategy)->toBe(AiStockpileStrategy::GoalSaver)
        ->and($profile->economic_role)->toBe(AiEconomicRole::DeutSeller)
        ->and($profile->fresh()->persona_version)->toBe(1);
});
