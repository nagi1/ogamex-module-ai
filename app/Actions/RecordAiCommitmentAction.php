<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiCommitmentDirection;
use Modules\AI\Enums\AiCommitmentState;
use Modules\AI\Models\AiCommitment;

class RecordAiCommitmentAction
{
    /**
     * @param array<string, mixed> $terms
     */
    public function handle(
        int $playerId,
        int $counterpartyPlayerId,
        array $terms,
        int $sourceObservationId,
        CarbonImmutable|null $dueAt = null,
        AiCommitmentDirection $direction = AiCommitmentDirection::PromisedByPlayer,
    ): AiCommitment {
        return AiCommitment::query()->firstOrCreate([
            'player_id' => $playerId,
            'source_observation_id' => $sourceObservationId,
        ], [
            'counterparty_player_id' => $counterpartyPlayerId,
            'terms' => $terms,
            'state' => AiCommitmentState::Proposed,
            'direction' => $direction,
            'due_at' => $dueAt,
            'revision' => 1,
        ]);
    }
}
