<?php

namespace Modules\AI\Contracts;

use Modules\AI\Domain\Conversation\MemoryRecallQuery;

interface LongTermMemory
{
    /** @return list<array{id:int,predicate:string,value:array<string,mixed>}> */
    public function recallRelevantMemories(MemoryRecallQuery $query): array;
}
