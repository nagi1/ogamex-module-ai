<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use OGame\Factories\GameMissionFactory;

/**
 * What the cohort did in the last N minutes, and whether the universe is healthy enough to judge it.
 *
 * DEV TOOLING, read-only. The scorecard needs an hour of play before it judges an aspect; this answers
 * "did the delivery move anything" within minutes: work by kind and outcome, why executors refused,
 * the missions and orders that reached the host, and the worker backlog. A backlog means a live
 * verdict reads the workers' lag, not the code, so the harness checks it before trusting one.
 *
 *   php Modules/AI/scripts/live-pulse.php            the last 15 minutes
 *   php Modules/AI/scripts/live-pulse.php 60         the last hour
 *   php Modules/AI/scripts/live-pulse.php 15 --json  machine-read by the harness
 */

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$minutes = max(1, (int) ($argv[1] ?? 15));
$json = in_array('--json', $argv, true);
$now = CarbonImmutable::now();
$since = $now->subMinutes($minutes);

$players = AiProfile::query()->where('enabled', true)->pluck('player_id')->map(fn ($id): int => (int) $id)->all();
$planets = DB::table('planets')->whereIn('user_id', $players)->pluck('id')->all();

$work = [];
foreach (DB::table('ai_work_items')->whereIn('player_id', $players)->where('updated_at', '>=', $since)
    ->selectRaw('kind, state, count(*) as n')->groupBy('kind', 'state')->get() as $row) {
    $work[AiWorkKind::from((int) $row->kind)->name][AiWorkState::from((int) $row->state)->name] = (int) $row->n;
}

$refusals = [];
foreach (DB::table('ai_action_receipts')->whereIn('player_id', $players)->where('created_at', '>=', $since)->where('state', 3)
    ->get(['action_type', 'result']) as $row) {
    $reason = json_decode((string) $row->result, true)['reason'] ?? 'unknown';
    $key = AiActionType::from((int) $row->action_type)->name.': '.(is_scalar($reason) ? $reason : json_encode($reason));
    $refusals[$key] = ($refusals[$key] ?? 0) + 1;
}
arsort($refusals);

$chosen = [];
foreach (DB::table('ai_decision_traces')->whereIn('player_id', $players)->where('created_at', '>=', $since)
    ->selectRaw('selected_action, count(*) as n')->groupBy('selected_action')->orderByDesc('n')->get() as $row) {
    $chosen[AiCandidateActionType::tryFrom((int) $row->selected_action)?->name ?? (string) $row->selected_action] = (int) $row->n;
}

$classes = GameMissionFactory::getMissionClasses();
$missions = [];
foreach (DB::table('fleet_missions')->whereIn('user_id', $players)->whereNull('parent_id')->where('time_departure', '>=', $since->timestamp)
    ->selectRaw('mission_type, count(*) as n')->groupBy('mission_type')->get() as $row) {
    $missions[preg_replace('/Mission$/', '', class_basename($classes[(int) $row->mission_type] ?? 'Unknown'))] = (int) $row->n;
}

$orders = [];
foreach (['building_queues' => 'buildings', 'research_queues' => 'research', 'unit_queues' => 'units'] as $table => $label) {
    $orders[$label] = DB::table($table)->whereIn('planet_id', $planets)->where('time_start', '>=', $since->timestamp)->count();
}

$sessions = DB::table('ai_work_items')->whereIn('player_id', $players)->where('kind', AiWorkKind::RunSession->value)
    ->where('state', AiWorkState::Completed->value)->where('updated_at', '>=', $since);
$late = DB::table('ai_work_items')->whereIn('player_id', $players)
    ->whereIn('state', [AiWorkState::Pending->value, AiWorkState::Retry->value])->where('due_at', '<', $now->subMinute());
$oldest = (clone $late)->min('due_at');

$pulse = [
    'at' => $now->toIso8601String(),
    'minutes' => $minutes,
    'accounts' => count($players),
    'sessions' => (clone $sessions)->count(),
    'accounts_with_session' => (clone $sessions)->distinct()->count('player_id'),
    'chosen' => $chosen,
    'work' => $work,
    'refusals' => array_slice($refusals, 0, 12, true),
    'missions' => $missions,
    'orders' => $orders,
    'backlog' => ['late' => (clone $late)->count(), 'oldest_late_seconds' => $oldest === null ? 0 : $now->getTimestamp() - CarbonImmutable::parse($oldest)->getTimestamp()],
];

if ($json) {
    echo json_encode($pulse, JSON_PRETTY_PRINT)."\n";

    exit(0);
}

printf("PULSE last %d min, %d accounts: %d sessions on %d accounts\n", $minutes, $pulse['accounts'], $pulse['sessions'], $pulse['accounts_with_session']);
printf("  backlog: %d work item(s) more than a minute late%s\n", $pulse['backlog']['late'],
    $pulse['backlog']['oldest_late_seconds'] > 300 ? ', oldest '.round($pulse['backlog']['oldest_late_seconds'] / 60).' min — live verdicts read worker lag' : '');
$decisions = max(1, array_sum($chosen));
echo '  sessions chose: '.implode(', ', array_map(fn ($k, $v) => $k.' '.round($v * 100 / $decisions).'%', array_keys($chosen), $chosen))."\n";
echo '  host orders: '.implode(', ', array_map(fn ($k, $v) => "$k $v", array_keys($orders), $orders))."\n";
echo '  missions launched: '.($missions === [] ? 'none' : implode(', ', array_map(fn ($k, $v) => "$k $v", array_keys($missions), $missions)))."\n";
echo "  work (kind: state count):\n";
foreach ($work as $kind => $states) {
    echo "    $kind: ".implode(', ', array_map(fn ($k, $v) => "$k $v", array_keys($states), $states))."\n";
}
echo '  refused: '.($refusals === [] ? 'nothing' : '')."\n";
foreach (array_slice($refusals, 0, 12, true) as $reason => $count) {
    echo "    $count × $reason\n";
}
