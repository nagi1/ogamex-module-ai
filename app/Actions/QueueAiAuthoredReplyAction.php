<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiConversationReplyState;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use OGame\Models\ChatMessage;

class QueueAiAuthoredReplyAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(int $playerId, int $counterpartyPlayerId, int $sourceMessageId, string $message, CarbonImmutable $expiresAt): AiConversationReply|null
    {
        $message = trim($message);

        if ($message === '' || mb_strlen($message) > 2_000 || $expiresAt->lessThanOrEqualTo($this->clock->now())) {
            return null;
        }

        if (!AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->exists()) {
            return null;
        }

        if (!$this->sourceBelongsToConversation($sourceMessageId, $playerId, $counterpartyPlayerId)) {
            return null;
        }

        return DB::transaction(function () use ($playerId, $counterpartyPlayerId, $sourceMessageId, $message, $expiresAt): AiConversationReply {
            $pendingReply = AiConversationReply::query()
                ->lockForUpdate()
                ->where('player_id', $playerId)
                ->where('counterparty_player_id', $counterpartyPlayerId)
                ->where('state', AiConversationReplyState::Pending)
                ->first();

            if ($pendingReply === null) {
                return AiConversationReply::query()->create([
                    'player_id' => $playerId,
                    'counterparty_player_id' => $counterpartyPlayerId,
                    'source_first_message_id' => $sourceMessageId,
                    'source_last_message_id' => $sourceMessageId,
                    'message' => $message,
                    'state' => AiConversationReplyState::Pending,
                    'expires_at' => $expiresAt,
                    'revision' => 1,
                ]);
            }

            $pendingReply->update([
                'source_last_message_id' => $sourceMessageId,
                'message' => $message,
                'expires_at' => $expiresAt,
                'revision' => $pendingReply->revision + 1,
            ]);

            return $pendingReply->refresh();
        });
    }

    private function sourceBelongsToConversation(int $sourceMessageId, int $playerId, int $counterpartyPlayerId): bool
    {
        return ChatMessage::query()
            ->whereKey($sourceMessageId)
            ->where('sender_id', $counterpartyPlayerId)
            ->where('recipient_id', $playerId)
            ->whereNull('alliance_id')
            ->exists();
    }
}
