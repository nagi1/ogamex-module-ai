<?php

namespace Modules\AI\Domain\Conversation;

use Carbon\CarbonImmutable;

readonly class MemoryRecallQuery
{
    public function __construct(public int $playerId, public int $subjectPlayerId, public CarbonImmutable $now, public int $limit = 5)
    {
    }
}
