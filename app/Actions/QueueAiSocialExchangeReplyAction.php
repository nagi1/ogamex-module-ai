<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSocialExchange;

class QueueAiSocialExchangeReplyAction
{
    public function handle(int $exchangeId, CarbonImmutable $expiresAt): AiConversationReply|null
    {
        $exchange = AiSocialExchange::query()->find($exchangeId);

        if ($exchange === null || $exchange->response === null) {
            return null;
        }

        $profile = AiProfile::query()
            ->where('player_id', $exchange->player_id)
            ->where('enabled', true)
            ->first();

        if ($profile === null) {
            return null;
        }

        $source = AiObservation::query()
            ->whereKey($exchange->source_observation_id)
            ->where('player_id', $exchange->player_id)
            ->where('subject_player_id', $exchange->counterparty_player_id)
            ->where('source_type', AiObservationSource::ChatMessage)
            ->first();

        if ($source === null) {
            return null;
        }

        $message = app(BuildAuthoredSocialReplyAction::class)->handle($exchange, $profile->random_seed);

        return app(QueueAiAuthoredReplyAction::class)->handle(
            $exchange->player_id,
            $exchange->counterparty_player_id,
            $source->source_id,
            $message ?? '',
            $expiresAt,
        );
    }
}
