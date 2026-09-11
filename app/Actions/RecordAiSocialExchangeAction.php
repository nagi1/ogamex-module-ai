<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiSocialExchangeState;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Models\AiSocialExchange;

class RecordAiSocialExchangeAction
{
    /**
     * @param array<string, mixed> $terms
     */
    public function handle(
        int $playerId,
        int $counterpartyPlayerId,
        int $sourceObservationId,
        AiSocialExchangeType $type,
        array $terms,
        CarbonImmutable|null $dueAt = null,
    ): AiSocialExchange|null {
        if ($playerId === $counterpartyPlayerId) {
            return null;
        }

        return AiSocialExchange::query()->firstOrCreate([
            'player_id' => $playerId,
            'source_observation_id' => $sourceObservationId,
            'type' => $type,
        ], [
            'counterparty_player_id' => $counterpartyPlayerId,
            'terms' => $terms,
            'state' => AiSocialExchangeState::Proposed,
            'due_at' => $dueAt,
            'revision' => 1,
        ]);
    }
}
