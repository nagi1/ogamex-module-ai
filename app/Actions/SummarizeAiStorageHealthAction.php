<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AI\Domain\Operability\AiStorageHealthOverview;
use Modules\AI\Support\AiClock;

/**
 * Reads retention health straight off each table's oldest row rather than trusting a persisted
 * prune count: a table whose oldest row is older than its declared window has stopped being
 * pruned, and that is exactly the disk outage this section exists to warn about. No new write.
 */
class SummarizeAiStorageHealthAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(): AiStorageHealthOverview
    {
        $now = $this->clock->now();
        $counts = $this->approximateCounts(array_keys(PruneAiRecordsAction::RETENTION_DAYS));
        $tables = [];

        foreach (PruneAiRecordsAction::RETENTION_DAYS as $model => $days) {
            $oldest = $model::query()->min('created_at');
            $ageDays = $oldest === null ? null : (int) CarbonImmutable::parse($oldest)->diffInDays($now);

            $tables[] = [
                'model' => class_basename($model),
                'count' => $counts[$model] ?? 0,
                'retentionDays' => $days,
                'oldestAgeDays' => $ageDays,
                'behind' => $ageDays !== null && $ageDays > $days,
            ];
        }

        return app()->makeWith(AiStorageHealthOverview::class, ['tables' => $tables]);
    }

    /**
     * ponytail: information_schema.table_rows is an InnoDB estimate, not an exact count, but the
     * storage panel only needs "about how many rows" while the retention flag depends on the
     * oldest row, never the count. One metadata read replaces one full table scan per table.
     * Upgrade path: a persisted prune counter if an exact count ever becomes a requirement.
     *
     * @param list<class-string> $models
     * @return array<class-string, int>
     */
    private function approximateCounts(array $models): array
    {
        $byTable = [];
        foreach ($models as $model) {
            $byTable[app($model)->getTable()] = $model;
        }

        return DB::table('information_schema.tables')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->whereIn('table_name', array_keys($byTable))
            ->get(['TABLE_NAME as table_name', 'TABLE_ROWS as table_rows'])
            ->mapWithKeys(static fn (object $row): array => [$byTable[$row->table_name] => (int) $row->table_rows])
            ->all();
    }
}
