<?php

/**
 * Fast-forward queue work that was scheduled at a slower universe speed.
 *
 * DEV TOOLING — read/write, never shipped, run once per cohort when the universe speed changes.
 *
 * Work already in flight keeps the completion time it was given at the speed of the day: raising the
 * speed does not shorten it, so an order placed at 1000x still waits its original hours under a 90000x
 * universe. This does not reimplement any timing maths — it hands each started order the completion
 * time "now", which is a state the host already understands:
 *
 *   - `BuildingQueueService::retrieveFinished()` returns rows with `building = 1` and `time_end <= now`,
 *     so the next scheduler tick applies the level.
 *   - `PlanetService::updateUnitQueue()` hands over the whole remaining batch when `now >= time_end`,
 *     the same path Dark Matter "complete now" uses.
 *
 * Orders that were never started are deliberately left alone: `BuildingQueueService::start()` launches
 * them at the current speed by itself.
 *
 *   docker compose -f local-docker-dev/docker-compose.grand.yml exec -T ogamex-app \
 *     sh -lc "cd /var/www && php artisan tinker --execute=\"require '/var/www/Modules/AI/scripts/fast-forward-cohort-queues.php';\""
 */

use Modules\AI\Models\AiProfile;
use OGame\Models\Planet;

$playerIds = AiProfile::query()->where('enabled', true)->pluck('player_id')
    ->map(fn ($id): int => (int) $id)->all();

$planetIds = Planet::query()->whereIn('user_id', $playerIds)->pluck('id')
    ->map(fn ($id): int => (int) $id)->all();

$now = now()->timestamp;

echo count($planetIds)." planet(s), now = {$now}\n";

$live = [
    'building_queues' => fn ($query) => $query->where('canceled', 0)->where('building', 1),
    'research_queues' => fn ($query) => $query->where('canceled', 0)->where('building', 1),
    'unit_queues' => fn ($query) => $query,
];

foreach ($live as $table => $scope) {
    // Both ends, not just the finish: a chained order inherits its predecessor's finish time as its own
    // start, so an order waiting behind slow-speed work starts in the future. `retrieveBuilding()` (unit
    // queues) and the queue listings skip anything whose start has not arrived, so moving only the end
    // leaves the row exactly as invisible as it was -- measured on grand: 4,746 unit orders moved to
    // "finished now" and still untouched, because their start was 4746 rows deep in the future.
    $pending = $scope(DB::table($table)->whereIn('planet_id', $planetIds)->where('processed', 0))
        ->where(function ($query) use ($now): void {
            $query->where('time_end', '>', $now)->orWhere('time_start', '>', $now);
        });
    $waiting = (clone $pending)->count();
    $changed = $pending->update(['time_start' => $now, 'time_end' => $now]);

    printf("%s: %d in flight, %d moved to complete now\n", $table, $waiting, $changed);
}

foreach (['building_queues', 'research_queues'] as $table) {
    printf(
        "%s: %d order(s) never started, left for the scheduler to start at the new speed\n",
        $table,
        DB::table($table)->whereIn('planet_id', $planetIds)
            ->where('processed', 0)->where('canceled', 0)->where('building', 0)->where('time_start', 0)->count()
    );
}

echo "next: php artisan ogamex:scheduler:process-planet-queues\n";
