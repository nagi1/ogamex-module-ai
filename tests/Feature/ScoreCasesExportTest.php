<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\Rl\DrawAiScoreCasesAction;
use OGame\Services\PlayerService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// plan/rl/north-star-plan.md, N1: the simulator's four scores are checked against these host answers, so every case
// must be the host's own highscore of a state the host holds, and the draw must leave the world untouched.

/**
 * @param  list<PlayerService>  $accounts
 * @return array<string, mixed>
 */
function scoreCasesDraw(array $accounts, int $count, int $seed): array
{
    return json_decode((string) json_encode(app(DrawAiScoreCasesAction::class)->handle($accounts, $count, 4, $seed, 1704067200)), true);
}

/**
 * The file without row ids: a rolled-back moon or fleet keeps auto-increment moving, so ids differ between runs.
 *
 * @param  array<mixed>  $file
 * @return array<mixed>
 */
function scoreCasesWithoutIds(array $file): array
{
    unset($file['id']);

    return array_map(static fn ($value) => is_array($value) ? scoreCasesWithoutIds($value) : $value, $file);
}

/** @return list<mixed> every table a score case writes */
function scoreCasesWorld(): array
{
    return [
        DB::table('planets')->orderBy('id')->get()->toArray(),
        DB::table('users_tech')->orderBy('user_id')->get()->toArray(),
        DB::table('fleet_missions')->orderBy('id')->get()->toArray(),
    ];
}

test('every case is the host highscore of the state it recorded', function (): void {
    $file = scoreCasesDraw([$this->planetService->getPlayer(), $this->createForeignPlanet()->getPlayer()], 12, 5);

    expect($file['cases'])->toHaveCount(12)
        ->and(array_unique(array_column($file['cases'], 'account')))->each->toBeIn($file['accounts']);
    foreach ($file['cases'] as $case) {
        $label = "case {$case['index']}";
        expect($case)->toHaveKeys(['index', 'account', 'research', 'planets', 'fleets', 'score'], $label)
            ->and($case['planets'])->not->toBeEmpty($label)
            ->and($case['score'])->toHaveKeys(['total', 'economy', 'research', 'military'], $label);
        foreach ($case['score'] as $name => $score) {
            expect($score)->toBeInt($label)->toBeGreaterThanOrEqual(0, "{$label}: {$name}");
        }
        // A planet the case read back must carry the state it read, not the state it drew.
        foreach ($case['planets'] as $planet) {
            expect($planet)->toHaveKeys(['id', 'levels', 'ships', 'defence', 'moon_ships'], $label)
                ->and($planet['id'])->toBeInt($label)
                ->and(array_keys($planet['levels']))->each->toBeNumeric($label);
        }
    }
    // The draw covers what a season meets: ships away from home and research held.
    expect(count(array_filter($file['cases'], static fn (array $case): bool => $case['fleets'] !== [])))->toBeGreaterThan(0)
        ->and(count(array_filter($file['cases'], static fn (array $case): bool => $case['research'] !== [])))->toBe(12);
});

test('the draw is reproducible from its seed and rolls every write back', function (): void {
    $accounts = [$this->planetService->getPlayer(), $this->createForeignPlanet()->getPlayer()];
    $before = scoreCasesWorld();

    $first = scoreCasesDraw($accounts, 8, 11);
    $second = scoreCasesDraw($accounts, 8, 11);

    expect(scoreCasesWithoutIds($first))->toEqual(scoreCasesWithoutIds($second))
        ->and(scoreCasesWorld())->toEqual($before);
});

test('the case command is registered', function (): void {
    expect(Artisan::all())->toHaveKey('ai:rl-score-cases');
});
