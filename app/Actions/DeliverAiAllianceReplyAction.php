<?php

namespace Modules\AI\Actions;

use Modules\AI\Models\AiProfile;
use OGame\Models\ChatMessage;
use OGame\Services\ChatService;

/**
 * Sends a module-authored line to the alliance channel through the host's own send path.
 *
 * The host still owns membership and delivery rules; this action only enforces the module's
 * own bounds (an enabled AI sender and a non-empty, bounded message).
 */
class DeliverAiAllianceReplyAction
{
    public function __construct(private ChatService $chatService)
    {
    }

    public function handle(int $senderId, int $allianceId, string $message, int|null $replyToId = null): ChatMessage|null
    {
        $message = trim($message);

        if ($message === '' || mb_strlen($message) > 2000) {
            return null;
        }

        if (!AiProfile::query()->where('player_id', $senderId)->where('enabled', true)->exists()) {
            return null;
        }

        return $this->chatService->sendAllianceMessage($senderId, $allianceId, $message, $replyToId);
    }
}
