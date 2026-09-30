<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;

/**
 * Drive a named situation on the cohort this container points at, in seconds.
 *
 * The harness could not test behaviour that needs time to pass: a hostile fleet takes hours to
 * arrive, a queue order takes hours to finish, a session only fires when the scheduler says so, and
 * the whole loop parks for a peak window. So nothing that depends on a situation -- a fleet save, a
 * recycle, a raid -- could be exercised except by waiting.
 *
 * This makes the time pass instead of waiting for it: it rewrites the rows the real clock is compared
 * against (host queues, the module's due work and schedules), runs the host's own apply step, and
 * drives `ai:run-due-work` with a synchronous queue so the sessions run in this process rather than in
 * a Horizon worker some seconds later. Then it reads back what the account did and exits non-zero when
 * the answer is missing.
 *
 *   php Modules/AI/scripts/cohort-scenario.php list
 *   php Modules/AI/scripts/cohort-scenario.php run idle-planet
 *   php Modules/AI/scripts/cohort-scenario.php run inbound-attack --confirm --accounts=2
 *   php Modules/AI/scripts/cohort-scenario.php run all --confirm --ticks=4
 *
 * `--arrival` must sit inside the account's own reaction lead (provoke-ai.php prints it:
 * 120 + 60 x session interval), or "it did not save" is the account correctly waiting for a fleet
 * that is still seven minutes out rather than a defect.
 *
 * Peak windows: no provider call is made anywhere in this file, so this is work to run *inside* a peak
 * window -- it is what the loop can do while it waits out the window instead of sleeping.
 *
 * DEV TOOLING, never shipped behaviour. The scenarios that write to the world (a hostile fleet, a
 * debris field, loot beside a planet) refuse to run without --confirm: on grand and pve those writes
 * are real, which is the point, but not something that should happen because a script was typed with
 * the wrong universe in front of it. The database the container points at decides the cohort.
 */

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$arguments = array_slice($argv, 1);
$command = $arguments[0] ?? 'help';
$subject = $arguments[1] ?? 'all';
$options = read_options($arguments);

if ($command === 'help' || $command === 'list') {
    print_catalogue();

    exit(0);
}

if ($command !== 'run') {
    fwrite(STDERR, "usage: cohort-scenario.php <list|run> [scenario|all] [--confirm] [--accounts=2] [--ticks=3] [--arrival=600] [--cleanup] [--json]\n");

    exit(2);
}

$names = array_keys(scenarios());

if ($subject !== 'all' && !in_array($subject, $names, true)) {
    fwrite(STDERR, "unknown scenario [{$subject}]. Run `cohort-scenario.php list`.\n");

    exit(2);
}

$chosen = $subject === 'all' ? $names : [$subject];
$context = cohort_context($options['accounts']);

echo 'cohort: '.count($context['players']).' enabled account(s), no provider call made (safe in a peak window)'."\n";

$results = [];
$started = microtime(true);

foreach ($chosen as $name) {
    $definition = scenarios()[$name];
    $results[] = run_scenario($name, $definition, $context, $options);
}

$seconds = microtime(true) - $started;
$failed = array_filter($results, static fn (array $result): bool => !$result['ok']);

echo "\nSCENARIOS: ".count($results) - count($failed).' passed, '.count($failed).' failed, '
    .sprintf('%.1fs', $seconds)."\n";

if ($options['json']) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

exit($failed === [] ? 0 : 1);

/**
 * The situations this can drive, each naming the fact that must appear when it has been driven.
 *
 * Every scenario is a *situation plus a read-back*, never a policy: what the account should do with
 * the situation belongs to the module, and the assertion only asks whether the recorded work exists.
 *
 * @return array<string, array<string, mixed>>
 */
function scenarios(): array
{
    return [
        'idle-planet' => [
            'writes' => false,
            'proves' => 'an account with nothing to react to still advances its economy',
            'plant' => null,
            'expect' => static function (array $context): array {
                $orders = orders_since($context['planets'], $context['before']);

                return [$orders > 0, $orders.' queue order(s) created with nothing to react to'];
            },
        ],
        'inbound-attack' => [
            'writes' => true,
            'proves' => 'a visible hostile fleet produces a fleet save (FLEET-001: one in the cohort lifetime)',
            'plant' => static function (array $context): array {
                $mission = DB::table('fleet_missions')->insertGetId([
                    'user_id' => $context['neighbour'],
                    'planet_id_from' => $context['neighbour_planet'],
                    'planet_id_to' => $context['planet'],
                    'mission_type' => 1,
                    'time_departure' => CarbonImmutable::now()->subMinutes(5)->timestamp,
                    'time_arrival' => CarbonImmutable::now()->addSeconds($context['arrival'])->timestamp,
                    'light_fighter' => 40,
                    'cruiser' => 15,
                    'small_cargo' => 20,
                    'processed' => 0,
                    'canceled' => 0,
                    'created_at' => CarbonImmutable::now(),
                    'updated_at' => CarbonImmutable::now(),
                ]);

                return ['hostile attack on planet '.$context['planet'].' from account '.$context['neighbour'],
                    [static fn (): int => DB::table('fleet_missions')->where('id', $mission)->delete()]];
            },
            'expect' => static function (array $context): array {
                $saves = work_item_rows($context['players'], $context['before'], AiWorkKind::FleetSave->value);

                return [$saves !== [], count($saves).' fleet-save work item(s) '
                    .describe($saves, 'kind')];
            },
        ],
        'debris-field' => [
            'writes' => true,
            'proves' => 'a debris field at a planet produces a recycle (FLEET-002: no account owns a recycler)',
            'plant' => static function (array $context): array {
                $home = DB::table('planets')->where('id', $context['planet'])
                    ->first(['galaxy', 'system', 'planet']);

                $field = DB::table('debris_fields')->insertGetId([
                    'galaxy' => $home->galaxy,
                    'system' => $home->system,
                    'planet' => $home->planet,
                    'metal' => 400000,
                    'crystal' => 200000,
                    'deuterium' => 0,
                    'created_at' => CarbonImmutable::now(),
                    'updated_at' => CarbonImmutable::now(),
                ]);

                return ['debris field of 400k metal beside planet '.$context['planet'],
                    [static fn (): int => DB::table('debris_fields')->where('id', $field)->delete()]];
            },
            'expect' => static function (array $context): array {
                $recycles = work_item_rows($context['players'], $context['before'], AiWorkKind::Recycle->value);

                return [$recycles !== [], count($recycles).' recycle work item(s) '
                    .describe($recycles, 'kind')];
            },
        ],
        'lootable-neighbour' => [
            'writes' => true,
            'proves' => 'a profitable neighbour produces a raid, or the refusal that stops it (ATK-001)',
            'plant' => static function (array $context): array {
                $before = (array) DB::table('planets')->where('id', $context['neighbour_planet'])
                    ->first(['metal', 'crystal', 'deuterium']);

                DB::table('planets')->where('id', $context['neighbour_planet'])->update([
                    'metal' => 900000,
                    'crystal' => 700000,
                    'deuterium' => 500000,
                    'updated_at' => CarbonImmutable::now(),
                ]);

                return ['2.1M resources left undefended on neighbour planet '.$context['neighbour_planet'],
                    [static fn (): int => DB::table('planets')->where('id', $context['neighbour_planet'])->update($before)]];
            },
            'expect' => static function (array $context): array {
                $raids = work_item_rows($context['players'], $context['before'], AiWorkKind::Raid->value);
                $refusals = refusal_reasons($context['players'], $context['before']);

                if ($raids !== []) {
                    return [true, count($raids).' raid work item(s) '.describe($raids, 'kind')];
                }

                return [false, 'no raid decided; refusals: '.($refusals === [] ? 'none recorded' : implode(', ', $refusals))];
            },
        ],
    ];
}

/**
 * @param  array<string, mixed>  $definition
 * @param  array<string, mixed>  $options
 * @return array<string, mixed>
 */
function run_scenario(string $name, array $definition, array $context, array $options): array
{
    if ($definition['writes'] && !$options['confirm']) {
        echo 'SCENARIO: SKIP '.$name.' — writes to the world; pass --confirm (it proves: '
            .$definition['proves'].")\n";

        return ['name' => $name, 'ok' => true, 'skipped' => 'needs --confirm'];
    }

    if ($definition['writes'] && count($context['players']) < 2) {
        echo 'SCENARIO: SKIP '.$name." — needs two enabled accounts to put a neighbour beside a target\n";

        return ['name' => $name, 'ok' => true, 'skipped' => 'needs two accounts'];
    }

    $context['before'] = CarbonImmutable::now();
    $context['arrival'] = $options['arrival'];
    $undo = [];
    $lines = [];

    if ($definition['plant'] !== null) {
        [$line, $undo] = $definition['plant']($context);
        $lines[] = 'planted: '.$line;
    }

    foreach (drive($context, $options['ticks']) as $line) {
        $lines[] = $line;
    }

    [$ok, $detail] = $definition['expect']($context);

    if (!$ok) {
        $detail .= ' — last decisions: '.newest_decisions($context['players'], $context['before']);
    }

    if ($options['cleanup']) {
        $reverted = 0;
        foreach ($undo as $revert) {
            $reverted += $revert();
        }
        $lines[] = 'cleaned up: '.$reverted.' planted row(s) reverted';
    }

    echo 'SCENARIO: '.($ok ? 'PASS' : 'FAIL').' '.$name."\n";

    foreach ($lines as $line) {
        echo '    '.$line."\n";
    }

    echo '    read-back: '.$detail."\n";

    return ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'steps' => $lines];
}

/**
 * Let the time pass, then let the module run on the situations that are now due.
 *
 * @param  array<string, mixed>  $context
 * @return array<int, string>
 */
function drive(array $context, int $ticks, int $limit = 40): array
{
    $lines = [];
    $since = CarbonImmutable::now();

    for ($tick = 1; $tick <= $ticks; $tick++) {
        $now = CarbonImmutable::now();
        $finished = finish_host_queues($context['planets'], $now);
        $due = make_module_work_due($context['players'], $now);

        // The host's own apply step consumes what just finished; without it the fast-forward only
        // rewrote rows and the account would still be waiting for a scheduler it never runs here.
        Artisan::call('ogamex:scheduler:process-planet-queues');

        // Synchronous on purpose: `ai:run-due-work` leases and dispatches, and a real queue would run
        // the session in a worker some seconds later, which is exactly the wait this tool exists to
        // avoid. Same engine, same paths, this process.
        config(['queue.default' => 'sync']);
        Artisan::call('ai:run-due-work', ['--limit' => $limit]);

        $lines[] = sprintf(
            'tick %d: %d order(s) finished, %d item(s) made due, %d work item(s) run',
            $tick,
            $finished,
            $due,
            engine_ran($context['players'], $since)
        );

        usleep(200_000);
    }

    return $lines;
}

/**
 * Work the engine actually finished since the run started: the number that says whether driving it
 * did anything, which `Artisan::output()` cannot (the command prints nothing when it is happy).
 *
 * @param  array<int, int>  $playerIds
 */
function engine_ran(array $playerIds, CarbonImmutable $since): int
{
    return DB::table('ai_work_items')
        ->whereIn('player_id', $playerIds)
        ->whereIn('state', [AiWorkState::Completed->value, AiWorkState::Failed->value])
        ->where('updated_at', '>=', $since)
        ->count();
}

/**
 * Hand every started host order the completion time "now", the same move
 * `fast-forward-cohort-queues.php` makes, so the apply step has something to consume.
 *
 * @param  array<int, int>  $planetIds
 */
function finish_host_queues(array $planetIds, CarbonImmutable $now): int
{
    // Per-table scope, because the host's queues are not shaped alike: only the building and research
    // queues carry `canceled`, and only they use `building` to say an order has actually started.
    $live = [
        'building_queues' => static fn ($query) => $query->where('canceled', 0)->where('building', 1),
        'research_queues' => static fn ($query) => $query->where('canceled', 0)->where('building', 1),
        'unit_queues' => static fn ($query) => $query,
    ];

    $moved = 0;

    foreach ($live as $table => $scope) {
        $moved += $scope(DB::table($table)->whereIn('planet_id', $planetIds)->where('processed', 0))
            ->where(static function ($query) use ($now): void {
                $query->where('time_end', '>', $now->timestamp)->orWhere('time_start', '>', $now->timestamp);
            })
            ->update(['time_start' => $now->timestamp, 'time_end' => $now->timestamp]);
    }

    return $moved;
}

/**
 * The module's own clock: work that is due later is due now, so a scenario does not wait for a
 * schedule that was written for a real day.
 *
 * @param  array<int, int>  $playerIds
 */
function make_module_work_due(array $playerIds, CarbonImmutable $now): int
{
    $states = [AiWorkState::Pending->value, AiWorkState::Retry->value];

    $work = DB::table('ai_work_items')
        ->whereIn('player_id', $playerIds)
        ->whereIn('state', $states)
        ->where('due_at', '>', $now)
        ->update(['due_at' => $now]);

    return $work + DB::table('ai_schedules')
        ->whereIn('player_id', $playerIds)
        ->where('next_due_at', '>', $now)
        ->update(['next_due_at' => $now]);
}

/**
 * Queue orders the cohort wrote since the situation went in: the account acting on its own.
 *
 * @param  array<int, int>  $planetIds
 */
function orders_since(array $planetIds, CarbonImmutable $since): int
{
    $orders = 0;

    foreach (['building_queues', 'research_queues', 'unit_queues'] as $table) {
        $orders += DB::table($table)->whereIn('planet_id', $planetIds)->where('created_at', '>=', $since)->count();
    }

    return $orders;
}

/**
 * @param  array<int, int>  $playerIds
 * @return array<int, array<string, mixed>>
 */
function work_item_rows(array $playerIds, CarbonImmutable $since, ?int $kind = null): array
{
    $query = DB::table('ai_work_items')
        ->whereIn('player_id', $playerIds)
        ->where('created_at', '>=', $since);

    if ($kind !== null) {
        $query->where('kind', $kind);
    }

    return array_map(static fn (object $row): array => (array) $row, $query->orderBy('id')->get()->all());
}

/**
 * Why the decision engine refused to act, when it refused: the reason a scenario like
 * `lootable-neighbour` fails is usually a gate in the planner, not a missing account.
 *
 * @param  array<int, int>  $playerIds
 * @return array<int, string>
 */
function refusal_reasons(array $playerIds, CarbonImmutable $since): array
{
    $reasons = [];

    foreach (DB::table('ai_decision_traces')->whereIn('player_id', $playerIds)->where('created_at', '>=', $since)->get(['selected_reason', 'score_components']) as $trace) {
        $components = json_decode((string) $trace->score_components, true);
        $refusals = is_array($components) ? ($components['rejections'] ?? []) : [];

        foreach (array_keys((array) $refusals) as $reason) {
            $reasons[(string) $reason] = true;
        }

        if ($trace->selected_reason !== null && str_contains((string) $trace->selected_reason, 'not_permitted')) {
            $reasons[(string) $trace->selected_reason] = true;
        }
    }

    return array_keys($reasons);
}

/**
 * @param  array<int, array<string, mixed>>  $rows
 */
function describe(array $rows, string $column): string
{
    return '('.implode(',', array_slice(array_column($rows, $column), 0, 6)).')';
}

/**
 * What the engine last decided for these accounts, so a FAIL says whether the account reacted and how
 * rather than only that the fact being looked for is missing.
 *
 * @param  array<int, int>  $playerIds
 */
function newest_decisions(array $playerIds, CarbonImmutable $since): string
{
    $decisions = [];

    foreach (DB::table('ai_decision_traces')->whereIn('player_id', $playerIds)
        ->where('created_at', '>=', $since)->orderByDesc('id')->limit(6)
        ->get(['player_id', 'selected_action', 'selected_reason']) as $trace) {
        $decisions[] = sprintf('p%s %s/%s', $trace->player_id, $trace->selected_action, $trace->selected_reason);
    }

    return $decisions === [] ? 'no decision was recorded' : implode(' | ', $decisions);
}

/**
 * @param  array<string, mixed>  $options
 * @return array<string, mixed>
 */
function cohort_context(int $accounts): array
{
    $profiles = AiProfile::query()->where('enabled', true)->orderBy('player_id')->limit($accounts)->get();

    if ($profiles->isEmpty()) {
        fwrite(STDERR, "No enabled AI accounts here. This is the wrong universe, or it was never seeded (ai:seed-grand-test --confirm).\n");

        exit(1);
    }

    $players = $profiles->pluck('player_id')->all();
    $planets = DB::table('planets')->whereIn('user_id', $players)->where('planet_type', 1)
        ->orderBy('user_id')->orderBy('id')->pluck('id')->all();
    $subject = (int) $players[0];
    $subjectPlanets = DB::table('planets')->where('user_id', $subject)->where('planet_type', 1)
        ->orderBy('id')->pluck('id')->all();
    $neighbour = (int) ($players[1] ?? 0);

    return [
        'players' => $players,
        'planets' => $planets,
        'subject' => $subject,
        'planet' => (int) ($subjectPlanets[0] ?? 0),
        'neighbour' => $neighbour,
        'neighbour_planet' => $neighbour === 0 ? 0 : (int) DB::table('planets')
            ->where('user_id', $neighbour)->where('planet_type', 1)->orderBy('id')->value('id'),
        'before' => CarbonImmutable::now(),
        'arrival' => 600,
    ];
}

/**
 * @param  array<int, string>  $arguments
 * @return array<string, mixed>
 */
function read_options(array $arguments): array
{
    $options = ['confirm' => false, 'cleanup' => false, 'json' => false, 'accounts' => 2, 'ticks' => 3, 'arrival' => 120];

    foreach ($arguments as $argument) {
        if ($argument === '--confirm') {
            $options['confirm'] = true;
        }
        if ($argument === '--cleanup') {
            $options['cleanup'] = true;
        }
        if ($argument === '--json') {
            $options['json'] = true;
        }
        if (str_starts_with($argument, '--accounts=')) {
            $options['accounts'] = max(1, (int) substr($argument, 11));
        }
        if (str_starts_with($argument, '--ticks=')) {
            $options['ticks'] = max(1, min(10, (int) substr($argument, 8)));
        }
        if (str_starts_with($argument, '--arrival=')) {
            $options['arrival'] = max(30, (int) substr($argument, 10));
        }
    }

    return $options;
}

function print_catalogue(): void
{
    echo "cohort scenarios — a situation, driven in seconds, read back as a recorded fact\n\n";

    foreach (scenarios() as $name => $definition) {
        printf(
            "  %-20s %-6s proves: %s\n",
            $name,
            $definition['writes'] ? 'writes' : 'safe',
            $definition['proves']
        );
    }

    echo "\n  run <name|all>   drive it (writing scenarios need --confirm)\n";
    echo "  --accounts=N     how many enabled accounts to work with (default 2)\n";
    echo "  --ticks=N        engine passes, each letting the host queues finish (default 3)\n";
    echo "  --arrival=SEC    when the planted hostile fleet arrives (default 600)\n";
    echo "  --cleanup        remove the rows this run planted\n";
    echo "  --json           also print the results as JSON\n";
    echo "\nNo provider call is made: this is the work to run inside a peak window.\n";
    echo "Deferred on purpose: social and alliance situations (a planted exchange or application) need\n";
    echo "the enum values of ai_social_exchanges verified first — add one scenario per situation there.\n";
}
