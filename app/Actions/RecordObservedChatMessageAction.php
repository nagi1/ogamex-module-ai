<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use OGame\Models\AllianceMember;
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
        return AiObservation::firstOrCreateAtomically([
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

    /**
     * An alliance-channel message reaches every enabled AI co-member except its sender, so it
     * becomes one observation each. This reopens the S1 exclusion for the read side only: the
     * conversation cycle still decides whether any member answers it.
     *
     * @return int the number of observations newly created
     */
    public function handleAlliance(int $chatMessageId): int
    {
        /** @var ChatMessage|null $chatMessage */
        $chatMessage = ChatMessage::query()->find($chatMessageId);

        if ($chatMessage === null || $chatMessage->alliance_id === null) {
            return 0;
        }

        $senderId = (int) $chatMessage->sender_id;
        $memberIds = AiProfile::query()
            ->where('enabled', true)
            ->where('player_id', '!=', $senderId)
            ->whereIn('player_id', AllianceMember::query()->where('alliance_id', $chatMessage->alliance_id)->select('user_id'))
            ->pluck('player_id');

        $recorded = 0;

        foreach ($memberIds as $memberId) {
            $observation = AiObservation::firstOrCreateAtomically([
                'player_id' => $memberId,
                'source_type' => AiObservationSource::ChatMessage,
                'source_id' => $chatMessage->id,
            ], [
                'kind' => AiObservationKind::AllianceChatMessageReceived,
                'subject_player_id' => $senderId,
                'source_time' => $chatMessage->created_at ?? $this->clock->now(),
                'observed_at' => $this->clock->now(),
            ]);

            if ($observation->wasRecentlyCreated) {
                $recorded++;
            }
        }

        return $recorded;
    }
}
