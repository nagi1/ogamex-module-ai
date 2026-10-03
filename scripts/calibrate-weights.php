<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;

/**
 * Whether a hand-picked decision weight is demonstrably wrong, read from what the cohort recorded.
 *
 * DEV TOOLING, read-only (DISC-13). For every action kind it prints how often the account chose it,
 * how often the order then completed or failed, and the share of the cohort's finished work it took.
 * A kind chosen far above its peers that fails more than a fifth of the time is over-weighted for
 * what it can deliver; a kind that never completes is mis-priced upstream, not in the scorer. It
 * only reports: a weight changes through a reviewed proposal, never from this script.
 *
 *   php Modules/AI/scripts/calibrate-weights.php        the last 24 hours
 *   php Modules/AI/scripts/calibrate-weights.php 72     the last 72 hours
 */

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$hours = max(1, (int) ($argv[1] ?? 24));
$since = CarbonImmutable::now()->subHours($hours);
$players = AiProfile::query()->where('enabled', true)->pluck('player_id')->all();

$rows = DB::table('ai_work_items')->whereIn('player_id', $players)->where('kind', '!=', AiWorkKind::RunSession->value)
    ->where('created_at', '>=', $since)->selectRaw('kind, state, count(*) as total')->groupBy('kind', 'state')->get();

$byKind = [];
foreach ($rows as $row) {
    $byKind[(int) $row->kind][(int) $row->state] = (int) $row->total;
}

$finished = array_sum(array_map(
    static fn (array $states): int => ($states[AiWorkState::Completed->value] ?? 0) + ($states[AiWorkState::Failed->value] ?? 0),
    $byKind,
));

printf("CALIBRATION last %dh, %d accounts, %d finished orders\n", $hours, count($players), $finished);
printf("  %-14s %8s %9s %7s %6s\n", 'kind', 'finished', 'completed', 'failed', 'share');

$flags = [];
foreach ($byKind as $kind => $states) {
    $completed = $states[AiWorkState::Completed->value] ?? 0;
    $failed = $states[AiWorkState::Failed->value] ?? 0;
    $done = $completed + $failed;
    $share = $finished > 0 ? $done / $finished : 0.0;
    $failure = $done > 0 ? $failed / $done : 0.0;
    $name = AiWorkKind::tryFrom($kind)?->name ?? (string) $kind;

    printf("  %-14s %8d %9d %7d %5.0f%%\n", $name, $done, $completed, $failed, $share * 100);

    if ($done >= 20 && $failure > 0.2) {
        $flags[] = sprintf('%s fails %.0f%% of %d finished orders: fix what it is offered before touching its weight', $name, $failure * 100, $done);
    }
    if ($done >= 20 && $completed === 0) {
        $flags[] = sprintf('%s never completes: the planner offers what the host refuses', $name);
    }
    if ($share > 0.5) {
        $flags[] = sprintf('%s is %.0f%% of all finished work: read its score components before trusting its weight', $name, $share * 100);
    }
}

foreach ($flags as $flag) {
    echo '  ! '.$flag."\n";
}

echo $flags === [] ? "CALIBRATION: no weight is demonstrably wrong on this read\n" : "CALIBRATION: ".count($flags)." finding(s) for review\n";
