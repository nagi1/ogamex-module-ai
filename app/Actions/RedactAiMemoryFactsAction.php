<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Models\AiMemoryFact;

class RedactAiMemoryFactsAction
{
    public function handle(int $playerId, int $sourceObservationId, CarbonImmutable $redactedAt): int
    {
        return AiMemoryFact::query()
            ->where('player_id', $playerId)
            ->where('source_observation_id', $sourceObservationId)
            ->whereNull('redacted_at')
            ->update(['redacted_at' => $redactedAt]);
    }
}
