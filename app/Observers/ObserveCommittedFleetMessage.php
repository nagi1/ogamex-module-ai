<?php

namespace Modules\AI\Observers;

use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\RecordObservedExpeditionResultAction;
use Modules\AI\Actions\RecordObservedTransferAction;
use OGame\Models\Message;

/**
 * Runs the module's committed transport side effects.
 *
 * The committed transport_received message is the trigger, never the fleet arrival itself:
 * the message is the same durable row every player can already read in game, and it is only
 * written when a cross-player transport actually credited the target planet. Cognition
 * therefore never observes a delivery the host later rolls back.
 */
class ObserveCommittedFleetMessage
{
    public function created(Message $message): void
    {
        $messageId = $message->id;

        // The expedition's own result message is the inbound half of the mission: what came back
        // (IMPL-67). Same durable row the player reads, so the same after-commit boundary.
        if (str_starts_with((string) $message->key, 'expedition_')) {
            DB::afterCommit(static function () use ($messageId): void {
                app(RecordObservedExpeditionResultAction::class)->handle($messageId);
            });

            return;
        }

        if ($message->key !== 'transport_received') {
            return;
        }

        // A transport can arrive before its transaction commits; cognition must not.
        DB::afterCommit(static function () use ($messageId): void {
            app(RecordObservedTransferAction::class)->handle($messageId);
        });
    }
}
