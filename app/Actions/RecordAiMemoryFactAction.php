<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiMemoryEvidenceKind;
use Modules\AI\Enums\AiMemoryPredicate;
use Modules\AI\Models\AiMemoryFact;

class RecordAiMemoryFactAction
{
    /**
     * @param array<string, mixed> $value
     */
    public function handle(
        int $playerId,
        int $subjectPlayerId,
        AiMemoryPredicate $predicate,
        AiMemoryEvidenceKind $evidenceKind,
        array $value,
        int $sourceObservationId,
        CarbonImmutable $validFrom,
        CarbonImmutable|null $expiresAt = null,
        int|null $speakerPlayerId = null,
        CarbonImmutable|null $validTo = null,
    ): AiMemoryFact {
        return AiMemoryFact::query()->firstOrCreate([
            'player_id' => $playerId,
            'source_observation_id' => $sourceObservationId,
            'predicate' => $predicate,
        ], [
            'subject_player_id' => $subjectPlayerId,
            'evidence_kind' => $evidenceKind,
            'speaker_player_id' => $speakerPlayerId,
            'value' => $value,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'expires_at' => $expiresAt,
            'revision' => 1,
        ]);
    }
}
