<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiConversationReplyState;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Support\AiClock;
use OGame\Models\ChatMessage;

class DeliverAiSealedReplyAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(int $replyId): ChatMessage|null
    {
        return DB::transaction(function () use ($replyId): ChatMessage|null {
            $reply = AiConversationReply::query()->lockForUpdate()->find($replyId);

            if ($reply === null) {
                return null;
            }

            if ($reply->state === AiConversationReplyState::Delivered) {
                return ChatMessage::query()->find($reply->delivered_chat_message_id);
            }

            if ($reply->state !== AiConversationReplyState::Sealed) {
                return null;
            }

            assert($reply->expires_at !== null);

            if ($reply->expires_at->lessThanOrEqualTo($this->clock->now())) {
                $reply->update([
                    'state' => AiConversationReplyState::Expired,
                    'revision' => $reply->revision + 1,
                ]);

                return null;
            }

            if (!$this->sourceRemainsDeliverable($reply)) {
                $reply->update([
                    'state' => AiConversationReplyState::Rejected,
                    'revision' => $reply->revision + 1,
                ]);

                return null;
            }

            $message = app(DeliverAiDirectReplyAction::class)->handle(
                $reply->player_id,
                $reply->counterparty_player_id,
                $reply->message,
                $reply->source_last_message_id,
            );

            if ($message === null) {
                $reply->update([
                    'state' => AiConversationReplyState::Rejected,
                    'revision' => $reply->revision + 1,
                ]);

                return null;
            }

            $reply->update([
                'state' => AiConversationReplyState::Delivered,
                'delivered_chat_message_id' => $message->id,
                'delivered_at' => $this->clock->now(),
                'revision' => $reply->revision + 1,
            ]);

            return $message;
        });
    }

    private function sourceRemainsDeliverable(AiConversationReply $reply): bool
    {
        return ChatMessage::query()
            ->whereKey($reply->source_last_message_id)
            ->where('sender_id', $reply->counterparty_player_id)
            ->where('recipient_id', $reply->player_id)
            ->whereNull('alliance_id')
            ->exists();
    }
}
