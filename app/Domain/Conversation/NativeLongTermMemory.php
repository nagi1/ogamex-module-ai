<?php

namespace Modules\AI\Domain\Conversation;

use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Models\AiMemoryFact;

class NativeLongTermMemory implements LongTermMemory
{
    public function recallRelevantMemories(MemoryRecallQuery $query): array
    {
        $memories = AiMemoryFact::query()
            ->with('sourceObservation')
            ->where('player_id', $query->playerId)
            ->where('subject_player_id', $query->subjectPlayerId)
            ->where('valid_from', '<=', $query->now)
            ->whereNull('redacted_at')
            ->where(function ($builder) use ($query): void {
                $builder->whereNull('valid_to')->orWhere('valid_to', '>', $query->now);
            })
            ->where(function ($builder) use ($query): void {
                $builder->whereNull('expires_at')->orWhere('expires_at', '>', $query->now);
            })
            ->orderByDesc('valid_from')
            ->limit(max(0, $query->limit))
            ->get()
            ->map(fn (AiMemoryFact $fact): array => [
                'id' => $fact->id,
                'source_observation_id' => $fact->source_observation_id,
                'source_type' => $fact->sourceObservation?->source_type?->name,
                'source_id' => $fact->sourceObservation?->source_id,
                'subject_player_id' => $fact->subject_player_id,
                'predicate' => $fact->predicate->name,
                'evidence_kind' => $fact->evidence_kind->name,
                'speaker_player_id' => $fact->speaker_player_id,
                'value' => $fact->value,
            ])
            ->values()
            ->all();

        /** @var list<array{id:int,source_observation_id:int,source_type:string|null,source_id:int|null,subject_player_id:int,predicate:string,evidence_kind:string,speaker_player_id:int|null,value:array<string,mixed>}> $memories */
        return $memories;
    }
}
