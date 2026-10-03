<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
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

        $relationship = AiRelationship::query()->firstOrCreate([
            'player_id' => $playerId,
            'other_player_id' => $otherPlayerId,
        ], [
            'trust' => 0,
            'threat' => 0,
            'affinity' => 0,
            'respect' => 0,
            'social_importance' => 0,
            'last_interaction_at' => $observedAt,
            'last_observation_id' => $sourceObservationId,
            'revision' => 0,
        ]);

        if ($relationship->last_interaction_at?->greaterThan($observedAt)) {
            return $relationship;
        }

        $relationship->update([
            'trust' => $this->signedScore((float) $relationship->trust + $trustChange),
            'threat' => $this->boundedScore((float) $relationship->threat + $threatChange),
            'affinity' => $this->signedScore((float) $relationship->affinity + $affinityChange),
            'respect' => $this->boundedScore((float) $relationship->respect + $respectChange),
            'social_importance' => $this->boundedScore((float) $relationship->social_importance + $socialImportanceChange),
            'last_interaction_at' => $observedAt,
            'last_observation_id' => $sourceObservationId,
            'revision' => $relationship->revision + 1,
        ]);

        return $relationship->refresh();
    }

    /**
     * Trust and affinity run from hatred to friendship. Clamping them at zero made every grudge
     * read as indifference, so no account could ever hold a relationship below zero (COVER-hatred).
     */
    private function signedScore(float $score): float
    {
        return min(1, max(-1, $score));
    }

    private function boundedScore(float $score): float
    {
        return min(1, max(0, $score));
    }
}
