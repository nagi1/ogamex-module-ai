<?php

namespace Modules\AI\Domain\Conversation;

use Carbon\CarbonImmutable;

readonly class MemoryRecallQuery
{
    public function __construct(
        public int $playerId,
        public int $subjectPlayerId,
        public CarbonImmutable $now,
        public int $limit = 5,
        // What the recall is about, when the caller knows. A ranking driver needs something to
        // rank against, and the module's own recall does not; with no text the module's
        // recency order is the answer rather than a driver's guess.
        public string|null $queryText = null,
    ) {
    }
}
