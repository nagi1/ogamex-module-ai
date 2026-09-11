<?php

namespace Modules\AI\Observers;

use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\RecordObservedChatMessageAction;
use OGame\Models\ChatMessage;

class ObserveCommittedChatMessage
{
    public function created(ChatMessage $chatMessage): void
    {
        $chatMessageId = $chatMessage->id;

        // A broadcast can occur before a transaction commits; cognition must not.
        DB::afterCommit(static function () use ($chatMessageId): void {
            app(RecordObservedChatMessageAction::class)->handle($chatMessageId);
        });
    }
}
