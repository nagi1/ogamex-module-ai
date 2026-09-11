<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Models\AiEmotionalEpisode;

class RecordAiEmotionalEpisodeAction
{
    public function handle(int $playerId, int $sourceObservationId, AiAffectEmotion $emotion, float $intensity, CarbonImmutable $occurredAt): AiEmotionalEpisode
    {
        return AiEmotionalEpisode::query()->firstOrCreate([
            'player_id' => $playerId,
            'source_observation_id' => $sourceObservationId,
            'emotion' => $emotion,
        ], [
            'intensity' => min(1, max(0, $intensity)),
            'occurred_at' => $occurredAt,
        ]);
    }
}
