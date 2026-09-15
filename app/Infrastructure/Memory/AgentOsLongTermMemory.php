<?php

namespace Modules\AI\Infrastructure\Memory;

use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Domain\Conversation\MemoryRecallQuery;
use Modules\AI\Enums\AiCognitionMode;

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
 *
 * The merge depends on the configured cognition mode:
 * - `external` lets the driver's ranking decide which facts survive the caller's limit, with the
 *   native recency order as the floor for everything the driver did not rank (the driver-swap
 *   comparison).
 * - `hybrid` keeps the native recency set authoritative and uses the driver's ranking only to
 *   float the relevant facts within it, so no fact the native recall would have returned is ever
 *   evicted: relevance decides order, recency decides membership.
 */
class AgentOsLongTermMemory implements LongTermMemory
{
    public function __construct(
        private readonly LongTermMemory $fallback,
        private readonly AgentOsClient $client,
        private readonly AiCognitionMode $mode,
    ) {
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

        return $this->mode === AiCognitionMode::Hybrid
            ? $this->hybridRanked($candidates, $ranking, $query->limit)
            : $this->ranked($candidates, $ranking, $query->limit);
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
     * `hybrid`: recency owns membership, relevance owns order. The native recall's own cut is
     * kept verbatim, then reordered by the driver's rank positions, so no fact the native path
     * would have returned is evicted and the relevant facts surface first. Facts the driver did
     * not rank keep their native recency order at the tail.
     *
     * @param  list<array{id:int,source_observation_id:int,source_type:string|null,source_id:int|null,subject_player_id:int,predicate:string,evidence_kind:string,speaker_player_id:int|null,value:array<string,mixed>}>  $candidates
     * @param  list<int>  $ranking
     * @return list<array{id:int,source_observation_id:int,source_type:string|null,source_id:int|null,subject_player_id:int,predicate:string,evidence_kind:string,speaker_player_id:int|null,value:array<string,mixed>}>
     */
    private function hybridRanked(array $candidates, array $ranking, int $limit): array
    {
        $cut = $this->bounded($candidates, $limit);
        $rank = array_flip($ranking);

        usort($cut, static function (array $left, array $right) use ($rank): int {
            $leftRank = $rank[$left['id']] ?? PHP_INT_MAX;
            $rightRank = $rank[$right['id']] ?? PHP_INT_MAX;

            return $leftRank <=> $rightRank ?: $left['id'] <=> $right['id'];
        });

        return $cut;
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
