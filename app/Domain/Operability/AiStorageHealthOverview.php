<?php

namespace Modules\AI\Domain\Operability;

/**
 * Retention health read off each windowed table's oldest row, so a prune that has stopped
 * running announces itself as a row older than the window it is supposed to be inside.
 *
 * @property list<array{model:string, count:int, retentionDays:int, oldestAgeDays:int|null, behind:bool}> $tables
 */
readonly class AiStorageHealthOverview
{
    /**
     * @param list<array{model:string, count:int, retentionDays:int, oldestAgeDays:int|null, behind:bool}> $tables
     */
    public function __construct(public array $tables = [])
    {
    }
}
