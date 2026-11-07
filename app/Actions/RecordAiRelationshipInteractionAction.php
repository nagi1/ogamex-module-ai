<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Modules\AI\Models\AiRelationship;

class RecordAiRelationshipInteractionAction
{
    public function handle(
        int $playerId,
        int $otherPlayerId,
        int $sourceObservationId,
        CarbonImmutable $observedAt,
        float $trustChange = 0,
        float $threatChange = 0,
        float $affinityChange = 0,
        float $respectChange = 0,
        float $socialImportanceChange = 0,
    ): AiRelationship|null {
        if ($playerId === $otherPlayerId) {
            return null;
        }

        // Race-safe without a lock: two processes recording the same pair at once (fleet arrivals on page
        // loads) both insert-ignore the empty row, then each applies its change in one atomic UPDATE, so
        // neither a duplicate key nor a lost update can happen. An interaction older than the last one
        // recorded is ignored, as before.
        $now = now();
        AiRelationship::query()->insertOrIgnore([
            'player_id' => $playerId,
            'other_player_id' => $otherPlayerId,
            'trust' => 0,
            'threat' => 0,
            'affinity' => 0,
            'respect' => 0,
            'social_importance' => 0,
            'last_interaction_at' => $observedAt,
            'last_observation_id' => $sourceObservationId,
            'revision' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        AiRelationship::query()
            ->where('player_id', $playerId)
            ->where('other_player_id', $otherPlayerId)
            ->where(static fn ($query) => $query->whereNull('last_interaction_at')->orWhere('last_interaction_at', '<=', $observedAt))
            ->update([
                'trust' => $this->clamped('trust', $trustChange, -1),
                'threat' => $this->clamped('threat', $threatChange, 0),
                'affinity' => $this->clamped('affinity', $affinityChange, -1),
                'respect' => $this->clamped('respect', $respectChange, 0),
                'social_importance' => $this->clamped('social_importance', $socialImportanceChange, 0),
                'last_interaction_at' => $observedAt,
                'last_observation_id' => $sourceObservationId,
                'revision' => DB::raw('revision + 1'),
                'updated_at' => $now,
            ]);

        return AiRelationship::query()->where('player_id', $playerId)->where('other_player_id', $otherPlayerId)->first();
    }

    /**
     * The column moved by the change and clamped in the same statement. Trust and affinity run from hatred to
     * friendship (floor -1); clamping them at zero made every grudge read as indifference (COVER-hatred). The
     * rest run from 0 to 1.
     */
    private function clamped(string $column, float $change, int $floor): Expression
    {
        return DB::raw(sprintf('LEAST(1, GREATEST(%d, %s + %F))', $floor, $column, $change));
    }
}
