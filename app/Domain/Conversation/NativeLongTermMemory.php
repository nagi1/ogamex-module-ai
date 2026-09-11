<?php

namespace Modules\AI\Domain\Conversation;

use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Models\AiMemoryFact;

class NativeLongTermMemory implements LongTermMemory
{
    public function recallRelevantMemories(MemoryRecallQuery $query): array
    {
        return AiMemoryFact::query()->where('player_id', $query->playerId)->where('subject_player_id', $query->subjectPlayerId)->where('valid_from', '<=', $query->now)->where(fn ($builder): mixed => $builder->whereNull('expires_at')->orWhere('expires_at', '>', $query->now))->orderByDesc('valid_from')->limit(max(0, $query->limit))->get()->map(fn (AiMemoryFact $fact): array => ['id' => $fact->id, 'predicate' => $fact->predicate->name, 'value' => $fact->value])->all();
    }
}
