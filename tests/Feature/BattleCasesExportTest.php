<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\DrawAiBattleCasesAction;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// plan/rl/world-sim-spec.md, combat: the simulator's battles are checked against these host answers (rl/ogsim
// combat.rs), so every case must be the host's own fight on the drawn sides and leave the world untouched.

/**
 * The catalogue cell for these research levels, the first `by` id varying slowest.
 *
 * @param  array{by: list<int>, values: list<int>}  $table
 * @param  array<string, int>  $research
 */
function battleCaseCell(array $table, array $research, int $maxLevel): int
{
    $index = 0;
    foreach ($table['by'] as $id) {
        $index = $index * ($maxLevel + 1) + $research[(string) $id];
    }

    return $table['values'][$index];
}

/**
 * @param  array<string, int>  $a
 * @param  array<string, int>  $b
 * @return array<string, int>
 */
function battleCaseSum(array $a, array $b): array
{
    foreach ($b as $id => $amount) {
        $a[$id] = ($a[$id] ?? 0) + $amount;
    }
    ksort($a);

    return $a;
}

test('every case is a host fight on the drawn sides, its numbers following the host rules', function (): void {
    $maxLevel = 4;
    $cases = app(DrawAiBattleCasesAction::class)->handle($this->planetService->getPlayer(), $this->createForeignPlanet(), 12, $maxLevel, 7);
    $catalogue = $cases['catalogue'];
    $units = array_column($catalogue['units'], null, 'id');
    $settings = app(SettingsService::class);

    expect($cases['cases'])->toHaveCount(12);
    foreach ($cases['cases'] as $i => $case) {
        $input = json_decode((string) json_encode($case['host_input']), true);
        $result = $case['result'];
        $defenders = array_diff_key($case['defender']['units'], array_flip(array_map('strval', $catalogue['non_combatants'])));
        ksort($defenders);
        $attackers = $case['attacker']['units'];
        ksort($attackers);

        expect($input['seed'])->toBe($case['seed'], "case {$i}")
            ->and(battleCaseSum($result['attacker_left'], $result['attacker_lost']))->toBe($attackers, "case {$i} attacker")
            ->and(battleCaseSum($result['defender_left'], $result['defender_lost']))->toBe($defenders, "case {$i} defender")
            ->and($result['moon_chance'])->toBe($case['defender']['moon_exists'] ? 0 : (int) min(floor(array_sum($result['debris']) / 100000), $settings->maximumMoonChance()), "case {$i} moon");
        if ($result['defender_left'] !== []) {
            expect(array_sum($result['loot']))->toEqual(0, "case {$i} loot without a win");
        }

        foreach ($input['attacker_fleets'][0]['units'] as $id => $unit) {
            $properties = $units[$id]['properties'];
            expect($unit['amount'])->toBe($attackers[$id])
                ->and($unit['attack_power'])->toEqual(battleCaseCell($properties['attack'], $case['attacker']['research'], $maxLevel))
                ->and($unit['hull_plating'])->toEqual(intdiv(battleCaseCell($properties['structural_integrity'], $case['attacker']['research'], $maxLevel), 10));
        }
        foreach ($input['defender_fleets'][0]['units'] as $id => $unit) {
            expect($unit['shield_points'])->toEqual(battleCaseCell($units[$id]['properties']['shield'], $case['defender']['research'], $maxLevel));
        }
    }
});

test('the draw is reproducible from its seed and saves nothing', function (): void {
    $target = $this->createForeignPlanet();
    $attacker = $this->planetService->getPlayer();
    $before = [
        DB::table('planets')->where('id', $target->getPlanetId())->first(),
        DB::table('users_tech')->where('user_id', $attacker->getId())->first(),
        DB::table('users_tech')->where('user_id', $target->getPlayer()->getId())->first(),
    ];

    $first = app(DrawAiBattleCasesAction::class)->handle($attacker, $target, 5, 3, 11);
    $second = app(DrawAiBattleCasesAction::class)->handle($attacker, $target, 5, 3, 11);

    expect(json_encode($first))->toBe(json_encode($second))
        ->and([
            DB::table('planets')->where('id', $target->getPlanetId())->first(),
            DB::table('users_tech')->where('user_id', $attacker->getId())->first(),
            DB::table('users_tech')->where('user_id', $target->getPlayer()->getId())->first(),
        ])->toEqual($before);
});

test('the case command is registered', function (): void {
    expect(Artisan::all())->toHaveKey('ai:rl-battle-cases');
});
