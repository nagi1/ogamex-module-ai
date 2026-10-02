<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiActionType;

/**
 * The accounts that keep failing the same way: one line per (action, reason) with how many accounts
 * and attempts it holds and the worst offenders. A refusal that repeats is a planner offering
 * something the host will never accept, which is how a cohort sits idle while looking busy.
 *
 * DEV TOOLING, read-only.
 *
 *   php Modules/AI/scripts/live-stuck.php [MINUTES=120] [MIN_REPEATS=3]
 */

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$minutes = (int) ($argv[1] ?? 120);
$repeats = (int) ($argv[2] ?? 3);
$since = CarbonImmutable::now()->subMinutes($minutes);

$groups = [];
foreach (DB::table('ai_action_receipts')->where('state', 3)->where('created_at', '>=', $since)->get(['player_id', 'action_type', 'result']) as $receipt) {
    $reason = (string) (json_decode((string) $receipt->result, true)['reason'] ?? 'unknown');
    $key = AiActionType::from((int) $receipt->action_type)->name.': '.$reason;
    $groups[$key][(int) $receipt->player_id] = ($groups[$key][(int) $receipt->player_id] ?? 0) + 1;
}

uasort($groups, static fn (array $a, array $b): int => array_sum($b) <=> array_sum($a));

printf("STUCK in the last %d min (an account counts once it fails the same way %d times)\n", $minutes, $repeats);
$stuck = 0;
foreach ($groups as $key => $players) {
    $repeating = array_filter($players, static fn (int $count): bool => $count >= $repeats);
    if ($repeating === []) {
        continue;
    }
    $stuck++;
    arsort($repeating);
    $worst = array_slice($repeating, 0, 6, true);
    $names = array_map(static fn (int $id, int $count): string => "{$id}x{$count}", array_keys($worst), $worst);
    printf("  %4d attempts, %3d stuck accounts  %s\n        e.g. %s\n", array_sum($players), count($repeating), $key, implode(', ', $names));
}
echo $stuck === 0 ? "  none: no account repeats a refusal\n" : '';
exit($stuck === 0 ? 0 : 1);
