<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\DrawAiMissionCasesAction;
use OGame\Services\PlayerService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// plan/rl/world-sim-spec.md, world: the simulator's missions are checked against these host answers, so every case
// must be the host's own dispatch, arrival and return on the drawn sides, and leave the world untouched.

/**
 * @param  list<PlayerService>  $accounts
 * @return array<string, mixed>
 */
function missionCasesDraw(array $accounts, int $count, int $seed): array
{
    return json_decode((string) json_encode(app(DrawAiMissionCasesAction::class)->handle($accounts, $count, array_keys(DrawAiMissionCasesAction::MISSIONS), 4, $seed)), true);
}

/**
 * @param  array<string, int>  $a
 * @param  array<string, int>  $b
 * @return array<string, int>
 */
function missionCasesLess(array $a, array $b): array
{
    foreach ($b as $id => $amount) {
        $a[$id] = ($a[$id] ?? 0) - $amount;
    }

    return array_filter($a);
}

/**
 * The file without row ids: a rolled-back colony keeps MySQL's auto-increment moving, so its id differs between runs.
 *
 * @param  array<mixed>  $file
 * @return array<mixed>
 */
function missionCasesWithoutIds(array $file): array
{
    unset($file['id']);

    return array_map(static fn ($value) => is_array($value) ? missionCasesWithoutIds($value) : $value, $file);
}

/** @return list<mixed> every table a mission writes */
function missionCasesWorld(): array
{
    return [
        DB::table('planets')->orderBy('id')->get()->toArray(),
        DB::table('users_tech')->orderBy('user_id')->get()->toArray(),
        DB::table('users')->orderBy('id')->get(['id', 'character_class', 'tactical_retreat_ratio'])->toArray(),
        DB::table('fleet_missions')->count(),
        DB::table('debris_fields')->count(),
        DB::table('battle_reports')->count(),
        DB::table('espionage_reports')->count(),
        DB::table('settings')->where('key', 'battle_engine')->value('value'),
    ];
}

test('every mission is dispatched, arrives and returns as the host plays it', function (): void {
    $file = missionCasesDraw([$this->planetService->getPlayer(), $this->createForeignPlanet()->getPlayer()], 15, 7);

    expect($file['cases'])->toHaveCount(15)
        ->and(array_count_values(array_column($file['cases'], 'mission')))->toBe(array_fill_keys(array_keys(DrawAiMissionCasesAction::MISSIONS), 3));
    foreach ($file['cases'] as $case) {
        $label = "case {$case['index']} {$case['mission']}";
        expect($case)->toHaveKeys(['mission', 'mission_type', 'case_seed', 'perturbation', 'input', 'dispatch', 'arrival', 'return'], $label)
            ->and($case['input'])->toHaveKeys(['origin', 'target', 'target_coordinates', 'target_type', 'origin_player', 'target_player', 'units', 'cargo', 'speed', 'holding_hours', 'debris_before'], $label);
        if (!$case['dispatch']['accepted']) {
            expect($case['dispatch']['error'])->toBeString($label)->and($case['arrival'])->toBeNull($label);

            continue;
        }

        $input = $case['input'];
        $dispatch = $case['dispatch'];
        $arrival = $case['arrival'];
        expect($dispatch['arrival'])->toBeGreaterThan($dispatch['departure'], $label)
            ->and(missionCasesLess($input['origin']['ships'], $dispatch['origin_after']['ships']))->toEqual($input['units'], $label)
            ->and($input['origin']['resources'][2] - $dispatch['origin_after']['resources'][2])->toEqual($input['cargo'][2] + $dispatch['fuel'], $label)
            ->and($arrival['time'])->toBe($dispatch['arrival'], $label);

        if ($arrival['return_mission'] !== null) {
            // The host flies home as long as it flew out, and lands what it carries.
            expect($arrival['return_mission']['arrival'] - $arrival['return_mission']['departure'])->toBe($dispatch['arrival'] - $dispatch['departure'], $label)
                ->and(missionCasesLess($case['return']['origin_after']['ships'], $case['return']['origin_before']['ships']))->toEqual($arrival['return_mission']['units'], $label);
        }
        if ($case['mission'] === 'attack') {
            expect($arrival['battle']['replays'])->toBeTrue("{$label}: the recorded seed replays the host's fight");
        }
        if ($case['mission'] === 'espionage') {
            expect($arrival['espionage']['roll_consistent'])->toBeTrue($label)
                ->and($arrival['espionage']['sections'])->toBeArray($label);
        }
        if ($case['mission'] === 'deploy') {
            expect($case['return'])->toBeNull($label)
                ->and(missionCasesLess($arrival['target_after']['ships'], $arrival['target_before']['ships']))->toEqual($input['units'], $label);
        }
    }
});

test('the draw is reproducible from its seed and rolls every write back', function (): void {
    $accounts = [$this->planetService->getPlayer(), $this->createForeignPlanet()->getPlayer()];
    $before = missionCasesWorld();

    $first = missionCasesDraw($accounts, 10, 11);
    $second = missionCasesDraw($accounts, 10, 11);

    expect(missionCasesWithoutIds($first))->toEqual(missionCasesWithoutIds($second))
        ->and(missionCasesWorld())->toEqual($before);
});

test('the case command is registered', function (): void {
    expect(Artisan::all())->toHaveKey('ai:rl-mission-cases');
});
