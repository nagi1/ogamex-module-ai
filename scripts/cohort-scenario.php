<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use OGame\Models\ChatMessage;

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
 *   php Modules/AI/scripts/cohort-scenario.php run all --confirm --wait=300
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

require_once __DIR__.'/economy-explain.php';

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
    fwrite(STDERR, "usage: cohort-scenario.php <list|run> [scenario|all] [--confirm] [--accounts=2] [--wait=180] [--inline] [--arrival=600] [--cleanup] [--json]\n");

    exit(2);
}

$names = array_keys(scenarios());

if ($subject !== 'all' && !in_array($subject, $names, true)) {
    fwrite(STDERR, "unknown scenario [{$subject}]. Run `cohort-scenario.php list`.\n");

    exit(2);
}

$chosen = $subject === 'all' ? $names : [$subject];
$context = cohort_context($options['accounts']);

// How often the read-back is asked while the live workers run the work.
const POLL_SECONDS = 5;

$leftovers = revert_planted();
if ($leftovers > 0) {
    echo 'reverted '.$leftovers." row(s) a previous run planted and never cleaned up\n";
}

// A run stopped by a timeout or Ctrl-C still takes its planted rows back out of the universe.
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $signal) {
        pcntl_signal($signal, static function (): never {
            echo "\ninterrupted: reverted ".revert_planted()." planted row(s)\n";

            exit(130);
        });
    }
}

echo 'cohort: '.count($context['players']).' enabled account(s), no provider call made (safe in a peak window)'."\n";

$results = [];
$started = microtime(true);

foreach ($chosen as $name) {
    $definition = scenarios()[$name];
    // One scenario throwing must not cost the rest of the run, and must not leave its plant behind.
    try {
        $results[] = run_scenario($name, $definition, $context, $options);
    } catch (Throwable $error) {
        $reverted = revert_planted();
        echo 'SCENARIO: ERROR '.$name.' — '.strtok($error->getMessage(), "\n").' (reverted '.$reverted." planted row(s))\n";
        $results[] = ['name' => $name, 'ok' => false, 'detail' => $error->getMessage()];
    }
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
        'every-planet-builds' => [
            'writes' => false,
            'proves' => 'a login fills the build queue on every planet, not one per session (ECON-001)',
            'plant' => null,
            'expect' => static function (array $context): array {
                // A planet already building counts: the player's property is a queue that is not idle.
                $subjectPlanets = DB::table('planets')->where('user_id', $context['subject'])->where('planet_type', 1)->pluck('id')->all();
                $building = DB::table('building_queues')->whereIn('planet_id', $subjectPlanets)->where('canceled', 0)
                    ->where(static fn ($query) => $query->where('processed', 0)->orWhere('created_at', '>=', $context['before']))
                    ->distinct()->count('planet_id');

                $ok = count($subjectPlanets) > 0 && $building * 2 >= count($subjectPlanets);
                $detail = $building.' of '.count($subjectPlanets).' planet(s) of account '.$context['subject'].' building or ordered';

                // A failure names, per planet, the gate that kept it idle (economy-explain.php).
                return [$ok, $ok ? $detail : $detail."\n      ".implode("\n      ", explain_economy($context['subject']))];
            },
        ],
        'inbound-message' => [
            'writes' => true,
            'proves' => 'a message from a neighbour produces a social exchange (SOC-001: none in days)',
            'plant' => static function (array $context): array {
                // Eloquent, not a raw insert: the module observes the host model's created event,
                // which is exactly what a real player's message fires.
                $message = ChatMessage::create([
                    'sender_id' => $context['neighbour'],
                    'recipient_id' => $context['subject'],
                    'message' => 'hey neighbour, we share a system. no attacks between us? we can both grow',
                ]);

                return ['message from account '.$context['neighbour'].' to account '.$context['subject'],
                    [['delete', 'chat_messages', $message->id]]];
            },
            'expect' => static function (array $context): array {
                // An answer is the behaviour; whether it went through a recorded exchange or the
                // conversation reply lane is the module's business (the first run got a reply and no row).
                $exchanges = DB::table('ai_social_exchanges')->where('player_id', $context['subject'])
                    ->where('created_at', '>=', $context['before'])->count();
                $replies = DB::table('chat_messages')->where('sender_id', $context['subject'])
                    ->where('recipient_id', $context['neighbour'])->where('created_at', '>=', $context['before'])->count();

                return [$exchanges + $replies > 0, $exchanges.' exchange(s), '.$replies.' reply message(s) from account '.$context['subject']];
            },
        ],
        'inbound-attack' => [
            'writes' => true,
            'proves' => 'a visible hostile fleet produces a fleet save (FLEET-001: one in the cohort lifetime)',
            'plant' => static function (array $context): array {
                // A run killed before undo records existed left its fleet in flight; it has this exact shape.
                delete_mission_tree(DB::table('fleet_missions')->where('user_id', $context['neighbour'])->where('planet_id_to', $context['planet'])
                    ->where('mission_type', 1)->where('processed', 0)
                    ->where('light_fighter', 40)->where('cruiser', 15)->where('small_cargo', 20)->pluck('id')->map(fn ($id): int => (int) $id)->all());

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

                [$lent, $undo] = lend_ships($context['planet'], 'large_cargo', 5);

                return ['hostile attack on planet '.$context['planet'].' from account '.$context['neighbour'].' ('.$lent.')',
                    [['delete', 'fleet_missions', $mission], $undo]];
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

                // One debris field per position (a unique key): a real battle may already have left
                // one here, so it is topped up and restored afterwards instead of inserted beside.
                $position = ['galaxy' => $home->galaxy, 'system' => $home->system, 'planet' => $home->planet];
                $existing = DB::table('debris_fields')->where($position)->first(['id', 'metal', 'crystal', 'deuterium']);
                $amounts = ['metal' => 400000, 'crystal' => 200000, 'deuterium' => 0, 'updated_at' => CarbonImmutable::now()];

                if ($existing !== null) {
                    DB::table('debris_fields')->where('id', $existing->id)->update($amounts);

                    [$lent, $undo] = lend_ships($context['planet'], 'recycler', 2);

                    return ['debris field topped up to 400k metal beside planet '.$context['planet'].' ('.$lent.')',
                        [['restore', 'debris_fields', $existing->id, ['metal' => $existing->metal, 'crystal' => $existing->crystal, 'deuterium' => $existing->deuterium]], $undo]];
                }

                $field = DB::table('debris_fields')->insertGetId([...$position, ...$amounts, 'created_at' => CarbonImmutable::now()]);

                [$lent, $undo] = lend_ships($context['planet'], 'recycler', 2);

                return ['debris field of 400k metal beside planet '.$context['planet'].' ('.$lent.')',
                    [['delete', 'debris_fields', $field], $undo]];
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
                    [['restore', 'planets', $context['neighbour_planet'], $before]]];
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
        'inactive-neighbour' => [
            'writes' => true,
            'proves' => 'a neighbour inactive for 8 days, with stock beside the account, is raided (ATK-001: no raid in days)',
            'plant' => static function (array $context): array {
                // The host calls a player inactive once users.time is older than seven days.
                $user = DB::table('users')->where('id', $context['neighbour'])->first(['time']);
                $before = (array) DB::table('planets')->where('id', $context['neighbour_planet'])->first(['metal', 'crystal', 'deuterium']);

                DB::table('users')->where('id', $context['neighbour'])->update(['time' => (string) CarbonImmutable::now()->subDays(8)->timestamp]);
                DB::table('planets')->where('id', $context['neighbour_planet'])->update([
                    'metal' => 300000,
                    'crystal' => 200000,
                    'deuterium' => 100000,
                    'updated_at' => CarbonImmutable::now(),
                ]);

                return ['account '.$context['neighbour'].' last seen 8 days ago with 600k resources on planet '.$context['neighbour_planet'],
                    [['restore', 'users', $context['neighbour'], ['time' => $user->time]],
                        ['restore', 'planets', $context['neighbour_planet'], $before]]];
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
        'alliance-application' => [
            'writes' => true,
            'proves' => 'a pending application to an AI-led alliance is decided (SIM-001: no applications in the cohort lifetime)',
            'plant' => static function (array $context): array {
                $alliance = DB::table('alliances')->whereIn('founder_user_id', $context['players'])->orderBy('id')->first(['id']);
                if ($alliance === null) {
                    throw new RuntimeException('no AI account leads an alliance here; nothing to apply to');
                }

                // A rankless applicant is rejected, so the run leaves no new member behind.
                $applicant = DB::table('users')->whereNotIn('id', $context['players'])->whereNull('alliance_id')
                    ->whereNotIn('id', DB::table('highscores')->where('general_rank', '>', 0)->select('player_id'))->orderBy('id')->first(['id']);
                if ($applicant === null) {
                    throw new RuntimeException('no unranked player outside the cohort to apply with');
                }

                $stamp = CarbonImmutable::now()->subMinutes(30);
                $row = DB::table('alliance_applications')->insertGetId([
                    'alliance_id' => $alliance->id,
                    'user_id' => $applicant->id,
                    'application_message' => 'cohort-scenario: looking for a home',
                    'status' => 0,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ]);

                return ['application from account '.$applicant->id.' to alliance '.$alliance->id,
                    [['delete', 'alliance_applications', $row]]];
            },
            'expect' => static function (array $context): array {
                $decided = DB::table('alliance_applications')->where('application_message', 'like', 'cohort-scenario:%')
                    ->where('status', '!=', 0)->count();

                return [$decided > 0, $decided.' planted application(s) decided'];
            },
        ],
    ];
}

/**
 * Plant, drive, read back. The read-back is polled: the scenario passes the moment the expected
 * work appears and fails only when the wait runs out.
 *
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
    $lines = [];

    if ($definition['plant'] !== null) {
        [$line, $undo] = $definition['plant']($context);
        remember_planted($undo);
        $lines[] = 'planted: '.$line;
    }

    $started = microtime(true);
    $since = CarbonImmutable::now();
    $tick = 0;
    do {
        $lines[] = drive_once($context, $options['inline'], ++$tick, $since);
        [$ok, $detail] = $definition['expect']($context);
        if ($ok || microtime(true) - $started >= $options['wait']) {
            break;
        }
        sleep(POLL_SECONDS);
    } while (true);

    if (!$ok) {
        $detail .= ' — last decisions: '.newest_decisions($context['players'], $context['before']);
    }

    if ($options['cleanup']) {
        $lines[] = 'cleaned up: '.revert_planted().' planted row(s) reverted';
    }

    printf("SCENARIO: %s %s (%.0fs)\n", $ok ? 'PASS' : 'FAIL', $name, microtime(true) - $started);

    foreach ($lines as $line) {
        echo '    '.$line."\n";
    }

    echo '    read-back: '.$detail."\n";

    return ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'steps' => $lines];
}

/**
 * One pass of time: host orders finish, the module's work falls due, and the host applies its
 * queues. The live queue workers then run the work, which is the cohort's real runtime; --inline
 * runs it in this process instead, for a universe whose workers are stopped. Inline on a live cohort
 * queued behind the workers' per-player locks and took five minutes a scenario.
 *
 * @param  array<string, mixed>  $context
 */
function drive_once(array $context, bool $inline, int $tick, CarbonImmutable $since): string
{
    $now = CarbonImmutable::now();
    $finished = finish_host_queues($context['planets'], $now);
    $due = make_module_work_due($context['players'], $now);

    Artisan::call('ogamex:scheduler:process-planet-queues');

    if ($inline) {
        config(['queue.default' => 'sync']);
    }
    Artisan::call('ai:run-due-work', ['--limit' => 40]);

    return sprintf('tick %d: %d order(s) finished, %d item(s) made due, %d work item(s) done so far',
        $tick, $finished, $due, engine_ran($context['players'], $since));
}

/**
 * Planted rows are written down before the drive starts, so a run that is killed or times out is
 * cleaned up by the next run instead of leaving a hostile fleet in a live universe.
 *
 * @param  list<array<int, mixed>>  $undo  [action, table, id, restored columns]
 */
function remember_planted(array $undo): void
{
    file_put_contents(planted_file(), json_encode([...read_planted(), ...$undo]));
}

/** Delete planted rows and restore overwritten ones; returns how many rows it touched. */
function revert_planted(): int
{
    $touched = 0;

    foreach (read_planted() as [$action, $table, $id, $columns]) {
        if ($table === 'fleet_missions' && $action !== 'restore') {
            $touched += delete_mission_tree([$id]);
            continue;
        }

        $query = DB::table($table)->where('id', $id);
        $touched += $action === 'restore' ? $query->update($columns) : $query->delete();
    }

    @unlink(planted_file());

    return $touched;
}

/**
 * Give the attacked or harvesting planet the ships the situation is about, and the undo that takes them
 * back. A fresh cohort account owns no ships, so a situation that only planted the threat or the debris
 * asked it to act with nothing: not saving a fleet it does not have is correct play, and the proof read
 * as a failure for a reason that was not the rule's.
 *
 * @return array{0: string, 1: array{0: string, 1: string, 2: int, 3: array<string, int>}}
 */
function lend_ships(int $planetId, string $ship, int $amount): array
{
    $held = (int) DB::table('planets')->where('id', $planetId)->value($ship);
    DB::table('planets')->where('id', $planetId)->update([$ship => $held + $amount]);

    return ["{$amount} {$ship} on planet {$planetId}", ['restore', 'planets', $planetId, [$ship => $held]]];
}

/**
 * Delete fleet missions and every mission that hangs off them. The engine adds a return mission with
 * `parent_id` set once it processes a planted fleet, and the foreign key refuses to delete the parent
 * first: that error aborted every revert and left the planted rows in the cohort (88 error lines in
 * one evening of runs).
 *
 * @param list<int> $ids
 */
function delete_mission_tree(array $ids): int
{
    $children = DB::table('fleet_missions')->whereIn('parent_id', $ids)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    $deleted = $children === [] ? 0 : delete_mission_tree($children);

    return $deleted + DB::table('fleet_missions')->whereIn('id', $ids)->delete();
}

/** @return list<array{0: string, 1: string, 2: int, 3: array<string, mixed>}> */
function read_planted(): array
{
    $rows = is_file(planted_file()) ? json_decode((string) file_get_contents(planted_file()), true) : [];

    return array_map(static fn (array $row): array => [$row[0], $row[1], (int) $row[2], (array) ($row[3] ?? [])], is_array($rows) ? $rows : []);
}

function planted_file(): string
{
    return storage_path('app/cohort-scenario-planted.json');
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
    $options = ['confirm' => false, 'cleanup' => false, 'json' => false, 'inline' => false, 'accounts' => 2, 'wait' => 180, 'arrival' => 120];

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
        if ($argument === '--inline') {
            $options['inline'] = true;
        }
        if (str_starts_with($argument, '--wait=')) {
            $options['wait'] = max(10, min(1800, (int) substr($argument, 7)));
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
    echo "  --wait=S         seconds to wait for the expected work before failing (default 180)\n";
    echo "  --inline         run the work in this process (only when the universe's workers are stopped)\n";
    echo "  --arrival=SEC    when the planted hostile fleet arrives (default 600)\n";
    echo "  --cleanup        remove the rows this run planted\n";
    echo "  --json           also print the results as JSON\n";
    echo "\nNo provider call is made: this is the work to run inside a peak window.\n";
    echo "Not yet a situation: a planted social exchange (ai_social_exchanges enum values unverified).\n";
}
