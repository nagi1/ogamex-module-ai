<?php

namespace Modules\AI\Observers;

use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\RecordObservedChatMessageAction;
use Modules\AI\Jobs\ReactAiToHumanMessage;
use Modules\AI\Models\AiProfile;
use OGame\Models\ChatMessage;

class ObserveCommittedChatMessage
{
    public function created(ChatMessage $chatMessage): void
    {
        $chatMessageId = $chatMessage->id;
        $senderId = $chatMessage->sender_id;

        // A broadcast can occur before a transaction commits; cognition must not.
        DB::afterCommit(static function () use ($chatMessageId, $senderId): void {
            $direct = app(RecordObservedChatMessageAction::class)->handle($chatMessageId);
            $alliance = app(RecordObservedChatMessageAction::class)->handleAlliance($chatMessageId);

            // A human's message an AI account received is read on the language lane, never on the request that sent it.
            if (($direct !== null || $alliance > 0) && config('ai.classification.enabled') && !AiProfile::query()->where('player_id', $senderId)->exists()) {
                ReactAiToHumanMessage::dispatch($chatMessageId);
            }
        });
    }
}
