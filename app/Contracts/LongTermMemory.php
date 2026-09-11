<?php

namespace Modules\AI\Contracts;

use Modules\AI\Domain\Conversation\MemoryRecallQuery;

interface LongTermMemory
{
    /** @return list<array{id:int,source_observation_id:int,source_type:string|null,source_id:int|null,subject_player_id:int,predicate:string,evidence_kind:string,speaker_player_id:int|null,value:array<string,mixed>}> */
    public function recallRelevantMemories(MemoryRecallQuery $query): array;
}
