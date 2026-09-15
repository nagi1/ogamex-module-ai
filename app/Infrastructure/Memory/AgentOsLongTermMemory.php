<?php

namespace Modules\AI\Infrastructure\Memory;

use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Domain\Conversation\MemoryRecallQuery;

/**
 * Recalls older memories with the AgentOS memory driver.
 *
 * The module owns the facts, the scope and the current-validity rules, and the driver only
 * ranks the candidates it is handed, so a swap changes which memory is surfaced first and never
 * what is true. The candidate set is deliberately wider than the caller's limit: ranking a set
 * that is already the answer would only reorder it, which measures nothing.
 *
 * A driver answer is used only when it ranks something. An absent sidecar, a refused request,
 * an unreadable body, a leaky or duplicated id, or an empty ranking all return the module's own
 * recency-ordered recall, because an AI losing its memory of a player is a worse outcome than a
 * memory arriving in the wrong order.
 */
class AgentOsLongTermMemory implements LongTermMemory
{
    public function __construct(private readonly LongTermMemory $fallback, private readonly AgentOsClient $client)
    {
    }

    /**
     * @return list<array{id:int,source_observation_id:int,source_type:string|null,source_id:int|null,subject_player_id:int,predicate:string,evidence_kind:string,speaker_player_id:int|null,value:array<string,mixed>}>
     */
    public function recallRelevantMemories(MemoryRecallQuery $query): array
    {
        $candidates = $this->fallback->recallRelevantMemories($this->candidateQuery($query));
        $queryText = trim((string) $query->queryText);

        if ($candidates === [] || $queryText === '') {
            return $this->bounded($candidates, $query->limit);
        }

        $ranking = $this->client->recall($query->playerId, $queryText, $query->limit, $this->projection($candidates));

        if ($ranking === null || $ranking === []) {
            return $this->bounded($candidates, $query->limit);
        }

        return $this->ranked($candidates, $ranking, $query->limit);
    }

    /**
     * Only a text query can be ranked, so the module asks for a wider candidate set than the
     * caller wants and keeps its own recency order for the answer it may have to fall back to.
     */
    private function candidateQuery(MemoryRecallQuery $query): MemoryRecallQuery
    {
        return app()->makeWith(MemoryRecallQuery::class, [
            'playerId' => $query->playerId,
            'subjectPlayerId' => $query->subjectPlayerId,
            'now' => $query->now,
            'limit' => max($query->limit, (int) config('ai.cognition.memory.agentos.maximum_memories', 50)),
        ]);
    }

    /**
     * @param  list<array{id:int,source_observation_id:int,source_type:string|null,source_id:int|null,subject_player_id:int,predicate:string,evidence_kind:string,speaker_player_id:int|null,value:array<string,mixed>}>  $candidates
     * @return list<array{id:int,text:string,tags:list<string>}>
     */
    private function projection(array $candidates): array
    {
        $projected = [];

        foreach ($candidates as $candidate) {
            $projected[] = [
                'id' => $candidate['id'],
                'text' => $this->describe($candidate),
                'tags' => array_values(array_filter([
                    $candidate['predicate'],
                    $candidate['evidence_kind'],
                    $candidate['source_type'],
                ], static fn (string|null $tag): bool => $tag !== null && $tag !== '')),
            ];
        }

        return $projected;
    }

    /**
     * A driver ranks text and the module stores structured facts, so the projection is a
     * mechanical rendering rather than a paraphrase: inventing prose here would put words into
     * a memory the module never recorded.
     *
     * @param  array{id:int,source_observation_id:int,source_type:string|null,source_id:int|null,subject_player_id:int,predicate:string,evidence_kind:string,speaker_player_id:int|null,value:array<string,mixed>}  $candidate
     */
    private function describe(array $candidate): string
    {
        // A value the encoder cannot render degrades to the predicate alone rather than to an
        // empty string, so a caller still gets a faithful tag to rank against.
        $value = (string) json_encode($candidate['value'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return trim($candidate['predicate'] . ' ' . $value);
    }

    /**
     * The driver's order decides which memories surface first, and a memory it did not rank
     * is not evidence the driver rejects — it simply had nothing to say. Native recency keeps
     * it in the answer rather than dropping it.
     *
     * @param  list<array{id:int,source_observation_id:int,source_type:string|null,source_id:int|null,subject_player_id:int,predicate:string,evidence_kind:string,speaker_player_id:int|null,value:array<string,mixed>}>  $candidates
     * @param  list<int>  $ranking
     * @return list<array{id:int,source_observation_id:int,source_type:string|null,source_id:int|null,subject_player_id:int,predicate:string,evidence_kind:string,speaker_player_id:int|null,value:array<string,mixed>}>
     */
    private function ranked(array $candidates, array $ranking, int $limit): array
    {
        $byId = [];

        foreach ($candidates as $candidate) {
            $byId[$candidate['id']] = $candidate;
        }

        $ranked = [];
        $seen = [];

        foreach ($ranking as $id) {
            if (isset($byId[$id]) && !isset($seen[$id])) {
                $ranked[] = $byId[$id];
                $seen[$id] = true;
            }
        }

        foreach ($candidates as $candidate) {
            if (!isset($seen[$candidate['id']])) {
                $ranked[] = $candidate;
            }
        }

        return array_slice($ranked, 0, max(0, $limit));
    }

    /**
     * @param  list<array{id:int,source_observation_id:int,source_type:string|null,source_id:int|null,subject_player_id:int,predicate:string,evidence_kind:string,speaker_player_id:int|null,value:array<string,mixed>}>  $candidates
     * @return list<array{id:int,source_observation_id:int,source_type:string|null,source_id:int|null,subject_player_id:int,predicate:string,evidence_kind:string,speaker_player_id:int|null,value:array<string,mixed>}>
     */
    private function bounded(array $candidates, int $limit): array
    {
        return array_slice($candidates, 0, max(0, $limit));
    }
}
