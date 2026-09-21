<?php

namespace Modules\AI\Domain\Operability;

/**
 * One row per enabled account, ordered so a failure is the first thing on screen.
 *
 * @property int $days
 * @property list<array<string, mixed>> $rows
 */
readonly class AiProgressBoard
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        public int $days = 1,
        public array $rows = [],
    ) {
    }
}
