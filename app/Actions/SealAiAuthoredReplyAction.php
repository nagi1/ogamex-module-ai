<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiConversationReplyState;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Support\AiClock;

class SealAiAuthoredReplyAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(int $replyId): AiConversationReply|null
    {
        return DB::transaction(function () use ($replyId): AiConversationReply|null {
            $reply = AiConversationReply::query()->lockForUpdate()->find($replyId);

            if ($reply === null) {
                return null;
            }

            if ($reply->state !== AiConversationReplyState::Pending) {
                return $reply;
            }

            $now = $this->clock->now();
            assert($reply->expires_at !== null);

            if ($reply->expires_at->lessThanOrEqualTo($now)) {
                $reply->update([
                    'state' => AiConversationReplyState::Expired,
                    'revision' => $reply->revision + 1,
                ]);

                return $reply->refresh();
            }

            $reply->update([
                'state' => AiConversationReplyState::Sealed,
                'delivery_key' => 'authored-reply:' . $reply->id . ':' . $reply->revision,
                'sealed_at' => $now,
                'revision' => $reply->revision + 1,
            ]);

            return $reply->refresh();
        });
    }
}
