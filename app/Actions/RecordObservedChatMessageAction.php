<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use OGame\Models\ChatMessage;

class RecordObservedChatMessageAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(int $chatMessageId): AiObservation|null
    {
        /** @var ChatMessage|null $chatMessage */
        $chatMessage = ChatMessage::query()->find($chatMessageId);

        if ($chatMessage === null) {
            return null;
        }

        if ($chatMessage->recipient_id === null || $chatMessage->alliance_id !== null) {
            return null;
        }

        if ($chatMessage->sender_id === $chatMessage->recipient_id) {
            return null;
        }

        if (!AiProfile::query()->where('player_id', $chatMessage->recipient_id)->where('enabled', true)->exists()) {
            return null;
        }

        // The unique source identity makes retrying an after-commit callback safe.
        return AiObservation::query()->firstOrCreate([
            'player_id' => $chatMessage->recipient_id,
            'source_type' => AiObservationSource::ChatMessage,
            'source_id' => $chatMessage->id,
        ], [
            'kind' => AiObservationKind::DirectChatMessageReceived,
            'subject_player_id' => $chatMessage->sender_id,
            'source_time' => $chatMessage->created_at ?? $this->clock->now(),
            'observed_at' => $this->clock->now(),
        ]);
    }
}
