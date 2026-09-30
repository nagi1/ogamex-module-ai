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
 *
 * The account's storage state arrives as a snapshot instead: production rates, per-resource store
 * amounts and capacities, and the resource deltas the observer saw. A delta the Trader caused is
 * an exchange with the outside world, so it is carried as an external, unpriced observation and
 * never folded into the verdicts below -- otherwise a trade would read as output the account never
 * produced, or as a store that filled itself.
 */
class SummarizeAiStorageHealthAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    /**
     * @param array{
     *     production?: array<string, int|float>,
     *     storage?: array<string, array{amount:int|float, capacity:int|float}>,
     *     deltas?: list<array{kind:string, metal?:int, crystal?:int, deuterium?:int}>
     * } $snapshot
     */
    public function handle(array $snapshot = []): AiStorageHealthOverview
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

        return app()->makeWith(AiStorageHealthOverview::class, [
            'tables' => $tables,
            'deltas' => $this->classifyDeltas($snapshot['deltas'] ?? []),
            'productionVerdict' => $this->productionVerdict($snapshot['production'] ?? []),
            'overflowVerdict' => $this->overflowVerdict($snapshot['storage'] ?? []),
        ]);
    }

    /**
     * No delta is priced here: the observation only says what moved. The Trader page states no fee
     * and no ratio, so both stay null rather than being filled with a guess, and a delta that the
     * host labels as a trader exchange is marked external instead of being read as production.
     *
     * @param list<array{kind:string, metal?:int, crystal?:int, deuterium?:int}> $deltas
     * @return list<array{kind:string, external:bool, priced:bool, ratio:null, fee:null, metal:int, crystal:int, deuterium:int}>
     */
    private function classifyDeltas(array $deltas): array
    {
        return array_values(array_map(static fn (array $delta): array => [
            'kind' => (string) ($delta['kind'] ?? ''),
            'external' => in_array($delta['kind'] ?? '', AiStorageHealthOverview::EXTERNAL_DELTA_KINDS, true),
            'priced' => false,
            'ratio' => null,
            'fee' => null,
            'metal' => (int) ($delta['metal'] ?? 0),
            'crystal' => (int) ($delta['crystal'] ?? 0),
            'deuterium' => (int) ($delta['deuterium'] ?? 0),
        ], $deltas));
    }

    /**
     * Zero is the structural "nothing is being produced" sentinel, not a tuned threshold: the
     * snapshot declares the hourly rate, so no ratio decides this.
     *
     * @param array<string, int|float> $production
     */
    private function productionVerdict(array $production): string
    {
        return array_sum($production) > 0
            ? AiStorageHealthOverview::PRODUCTION_SURPLUS
            : AiStorageHealthOverview::PRODUCTION_IDLE;
    }

    /**
     * A store is full once it holds its capacity; the comparison is against the capacity the
     * snapshot reports, so again no ratio is tuned here.
     *
     * @param array<string, array{amount:int|float, capacity:int|float}> $storage
     */
    private function overflowVerdict(array $storage): string
    {
        foreach ($storage as $store) {
            if ((float) $store['amount'] >= (float) $store['capacity']) {
                return AiStorageHealthOverview::OVERFLOW_FULL;
            }
        }

        return AiStorageHealthOverview::OVERFLOW_ROOM;
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
