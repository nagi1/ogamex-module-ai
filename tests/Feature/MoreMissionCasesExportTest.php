<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\DrawAiMoreMissionCasesAction;
use OGame\Services\PlayerService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// plan/rl/WORLD-SIM-PLAN.md, W6/W8: colonise, expedition, missile and moon destruction answered by the host, each case
// the host's own dispatch, arrival and return on the drawn sides, replayable from its seed, leaving the world untouched.

/** The W6/W8 missions the arrival checks below are written for; the later ones (ACS, wreck, moon raid, relocation) draw three sides. */
const MORE_MISSION_CASES_W6 = ['colonise', 'expedition', 'missile', 'moon_destruction'];

/**
 * @param  list<PlayerService>  $accounts
 * @param  list<string>  $missions
 * @return array<string, mixed>
 */
function moreMissionCasesDraw(array $accounts, int $count, int $seed, array $missions = MORE_MISSION_CASES_W6): array
{
    return json_decode((string) json_encode(app(DrawAiMoreMissionCasesAction::class)->handle($accounts, $count, $missions, 4, $seed)), true);
}

/** @return list<mixed> every table a mission writes */
function moreMissionCasesWorld(): array
{
    return [
        DB::table('planets')->orderBy('id')->get()->toArray(),
        DB::table('users_tech')->orderBy('user_id')->get()->toArray(),
        DB::table('users')->orderBy('id')->get(['id', 'character_class', 'tactical_retreat_ratio', 'dark_matter'])->toArray(),
        DB::table('highscores')->orderBy('id')->pluck('general')->all(),
        DB::table('fleet_missions')->count(),
        DB::table('messages')->count(),
        DB::table('battle_reports')->count(),
        DB::table('settings')->where('key', 'battle_engine')->value('value'),
    ];
}

/**
 * @param  array<mixed>  $file
 * @return array<mixed>
 */
function moreMissionCasesWithoutIds(array $file): array
{
    unset($file['id']);

    return array_map(static fn ($value) => is_array($value) ? moreMissionCasesWithoutIds($value) : $value, $file);
}

test('every mission is dispatched, arrives and returns as the host plays it', function (): void {
    $file = moreMissionCasesDraw([$this->planetService->getPlayer(), $this->createForeignPlanet()->getPlayer()], 40, 7);

    expect($file['cases'])->toHaveCount(40)
        ->and(array_count_values(array_column($file['cases'], 'mission')))->toBe(array_fill_keys(MORE_MISSION_CASES_W6, 10));
    foreach ($file['cases'] as $case) {
        $label = "case {$case['index']} {$case['mission']}";
        expect($case)->toHaveKeys(['mission', 'mission_type', 'case_seed', 'perturbation', 'input', 'dispatch', 'arrival', 'return'], $label)
            ->and($case['input'])->toHaveKeys(['origin', 'target', 'target_coordinates', 'target_type', 'origin_player', 'units', 'cargo', 'speed', 'holding_hours'], $label);
        if (!$case['dispatch']['accepted']) {
            expect($case['dispatch']['error'])->toBeString($label)->and($case['arrival'])->toBeNull($label);

            continue;
        }

        $arrival = $case['arrival'];
        expect($arrival['time'])->toBeGreaterThanOrEqual($case['dispatch']['arrival'], $label)->and($arrival['messages'])->toBeArray($label);
        match ($case['mission']) {
            'colonise' => expect($arrival['planet_created'])->toBe($arrival['refusal'] === null, $label),
            'expedition' => expect($arrival['draws'])->toBeArray($label)->and($arrival['decode_check'])->not->toBeFalse($label)
                ->and($arrival['time'])->toBe($case['dispatch']['arrival'] + $case['dispatch']['holding_seconds'], $label),
            'missile' => expect($arrival['effective'])->toBe($arrival['missiles_sent'] - $arrival['intercepted'], $label),
            default => expect($arrival['roll_consistent'])->toBeTrue($label),
        };
    }
});

test('the draw is reproducible from its seed and rolls every write back', function (): void {
    // Every mission, so three sides: an ACS needs an owner, a mate and a raider.
    $accounts = [$this->planetService->getPlayer(), $this->createForeignPlanet()->getPlayer(), $this->createForeignPlanet()->getPlayer()];
    $missions = array_keys(DrawAiMoreMissionCasesAction::MISSIONS);
    $before = moreMissionCasesWorld();

    $first = moreMissionCasesDraw($accounts, count($missions), 11, $missions);
    $second = moreMissionCasesDraw($accounts, count($missions), 11, $missions);

    expect(moreMissionCasesWithoutIds($first))->toEqual(moreMissionCasesWithoutIds($second))
        ->and(moreMissionCasesWorld())->toEqual($before);
});

test('the more-mission case command is registered', function (): void {
    expect(Artisan::all())->toHaveKey('ai:rl-more-mission-cases');
});
