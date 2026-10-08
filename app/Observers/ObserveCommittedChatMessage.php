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

        // A broadcast can occur before a transaction commits; cognition must not.
        DB::afterCommit(static function () use ($chatMessageId): void {
            $observation = app(RecordObservedChatMessageAction::class)->handle($chatMessageId);
            app(RecordObservedChatMessageAction::class)->handleAlliance($chatMessageId);

            // A human's message to an AI account is read on the language lane, never on the request that sent it.
            if ($observation !== null && config('ai.classification.enabled') && !AiProfile::query()->where('player_id', $observation->subject_player_id)->exists()) {
                ReactAiToHumanMessage::dispatch($chatMessageId);
            }
        });
    }
}
