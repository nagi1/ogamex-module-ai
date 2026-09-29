<?php

declare(strict_types=1);

namespace Modules\AI\Support;

/**
 * Read-only aggregation of the per-planet resource rows into the single empire-level
 * total row that the empire view renders.
 *
 * The rows are handed in instead of being loaded here, so that AI reasoning can total
 * an empire without the aggregator holding, caching or duplicating per-planet state.
 * No column set is assumed: whatever columns the caller's planet rows carry are the
 * columns that get summed.
 */
final class EmpireSummary
{
    /**
     * @param  list<array<string, int|float>>  $planets
     * @return array<string, int|float>
     */
    public function totals(array $planets): array
    {
        $totals = [];

        foreach ($planets as $planet) {
            foreach ($planet as $column => $amount) {
                $totals[$column] = ($totals[$column] ?? 0) + $amount;
            }
        }

        return $totals;
    }
}
