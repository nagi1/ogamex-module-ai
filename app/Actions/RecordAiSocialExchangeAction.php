<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiSocialExchangeState;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Models\AiSocialExchange;

class RecordAiSocialExchangeAction
{
    private const MAX_PROTOCOL_DEPTH = 2;

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
        int $protocolDepth = 1,
    ): AiSocialExchange|null {
        if ($playerId === $counterpartyPlayerId) {
            return null;
        }

        if ($protocolDepth < 1 || $protocolDepth > self::MAX_PROTOCOL_DEPTH) {
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
            'protocol_depth' => $protocolDepth,
            'revision' => 1,
        ]);
    }
}
