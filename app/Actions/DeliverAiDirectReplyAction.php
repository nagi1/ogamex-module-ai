<?php

namespace Modules\AI\Actions;

use Modules\AI\Models\AiProfile;
use OGame\Models\ChatMessage;
use OGame\Models\User;
use OGame\Services\ChatService;

class DeliverAiDirectReplyAction
{
    public function __construct(private ChatService $chatService)
    {
    }

    public function handle(int $senderId, int $recipientId, string $message, int|null $replyToId = null): ChatMessage|null
    {
        $message = trim($message);

        if ($message === '' || mb_strlen($message) > 2000 || $senderId === $recipientId) {
            return null;
        }

        if (!AiProfile::query()->where('player_id', $senderId)->where('enabled', true)->exists()) {
            return null;
        }

        if (!User::query()->whereKey($recipientId)->exists() || !$this->chatService->canMessagePlayer($senderId, $recipientId)) {
            return null;
        }

        if ($replyToId !== null && !$this->replyBelongsToConversation($replyToId, $senderId, $recipientId)) {
            return null;
        }

        return $this->chatService->sendDirectMessage($senderId, $recipientId, $message, $replyToId);
    }

    private function replyBelongsToConversation(int $replyToId, int $senderId, int $recipientId): bool
    {
        return ChatMessage::query()
            ->whereKey($replyToId)
            ->whereNull('alliance_id')
            ->where(function ($query) use ($senderId, $recipientId): void {
                $query->where(fn ($direct): mixed => $direct->where('sender_id', $senderId)->where('recipient_id', $recipientId))
                    ->orWhere(fn ($direct): mixed => $direct->where('sender_id', $recipientId)->where('recipient_id', $senderId));
            })
            ->exists();
    }
}
