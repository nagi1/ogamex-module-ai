<?php

namespace Modules\AI\Actions;

use Modules\AI\Models\AiProfile;
use OGame\Models\ChatMessage;
use OGame\Models\User;
use OGame\Services\ChatService;

class DeliverAiDirectReplyAction
{
    private const ONE_LINE_SECONDS = 60;

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

        // A player types one line, not two in the same second: a notice and a reply fired by two events landed together
        // ("saw that :P" then "ok, heard", 8 Nov 2026). The first line stands for both until the other side answers.
        if ($this->justSpoke($senderId, $recipientId)) {
            return null;
        }

        if ($replyToId !== null && !$this->replyBelongsToConversation($replyToId, $senderId, $recipientId)) {
            return null;
        }

        if (!app(GuardAiOutboundTextAction::class)->passes($message, [$recipientId])) {
            return null;
        }

        return $this->chatService->sendDirectMessage($senderId, $recipientId, $message, $replyToId);
    }

    /** A line to this player in the last minute that they have not answered yet. */
    private function justSpoke(int $senderId, int $recipientId): bool
    {
        $last = ChatMessage::query()
            ->whereNull('alliance_id')
            ->where('sender_id', $senderId)
            ->where('recipient_id', $recipientId)
            ->where('created_at', '>=', now()->subSeconds(self::ONE_LINE_SECONDS))
            ->max('id');

        return $last !== null && !ChatMessage::query()
            ->whereNull('alliance_id')
            ->where('sender_id', $recipientId)
            ->where('recipient_id', $senderId)
            ->where('id', '>', $last)
            ->exists();
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
