<?php

/**
 * Age the whole cohort without waiting: each tick finishes the host's started orders, lands the
 * accounts' own fleets, makes their work due and runs it here through the real job.
 *
 * DEV TOOLING, grand/pve only. Resources still accrue on the real clock, so this moves the
 * orders and sessions along, not the stock; use it to let new accounts act as often as they could.
 *
 *   docker compose -f local-docker-dev/docker-compose.grand.yml exec -T ogamex-app \
 *     php artisan tinker --execute="\$ticks = 20; require '/var/www/Modules/AI/scripts/cohort-age.php';"
 */

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiProfile;

$ticks = $ticks ?? 20;
$players = AiProfile::query()->where('enabled', true)->pluck('player_id')->map(fn ($id): int => (int) $id)->all();
$planets = DB::table('planets')->whereIn('user_id', $players)->pluck('id')->all();
$states = [AiWorkState::Pending->value, AiWorkState::Retry->value];
$ran = 0;

for ($tick = 1; $tick <= $ticks; $tick++) {
    $now = CarbonImmutable::now();

    foreach (['building_queues' => true, 'research_queues' => true, 'unit_queues' => false] as $table => $started) {
        $query = DB::table($table)->whereIn('planet_id', $planets)->where('processed', 0);
        $started && $query->where('canceled', 0)->where('building', 1);
        $query->where(fn ($q) => $q->where('time_end', '>', $now->timestamp)->orWhere('time_start', '>', $now->timestamp))
            ->update(['time_start' => $now->timestamp, 'time_end' => $now->timestamp]);
    }

    DB::table('fleet_missions')->whereIn('user_id', $players)->where('processed', 0)->where('canceled', 0)
        ->where('time_arrival', '>', $now->timestamp)->update(['time_arrival' => $now->timestamp]);
    DB::table('ai_work_items')->whereIn('player_id', $players)->whereIn('state', $states)->where('due_at', '>', $now)->update(['due_at' => $now]);
    DB::table('ai_schedules')->whereIn('player_id', $players)->where('next_due_at', '>', $now)->update(['next_due_at' => $now]);

    Artisan::call('ogamex:scheduler:process-planet-queues');
    Artisan::call('ogamex:scheduler:process-fleet-arrivals', ['--limit' => 400]);

    $due = DB::table('ai_work_items')->whereIn('player_id', $players)->whereIn('state', $states)
        ->where('due_at', '<=', CarbonImmutable::now())->orderBy('due_at')->limit(200)->pluck('id');

    foreach ($due as $id) {
        try {
            app()->makeWith(ProcessAiWork::class, ['workItemId' => (int) $id])->handle();
            $ran++;
        } catch (Throwable $exception) {
            echo 'work item '.$id.' threw: '.$exception->getMessage()."\n";
        }
    }
}

echo "aged the cohort $ticks tick(s): $ran work item(s) run\n";
