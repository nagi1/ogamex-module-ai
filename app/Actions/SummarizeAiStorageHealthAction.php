<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
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
        $tables = [];

        foreach (PruneAiRecordsAction::RETENTION_DAYS as $model => $days) {
            $oldest = $model::query()->min('created_at');
            $ageDays = $oldest === null ? null : (int) CarbonImmutable::parse($oldest)->diffInDays($now);

            $tables[] = [
                'model' => class_basename($model),
                'count' => $model::query()->count(),
                'retentionDays' => $days,
                'oldestAgeDays' => $ageDays,
                'behind' => $ageDays !== null && $ageDays > $days,
            ];
        }

        return app()->makeWith(AiStorageHealthOverview::class, ['tables' => $tables]);
    }
}
