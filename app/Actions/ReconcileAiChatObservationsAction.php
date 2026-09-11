<?php

namespace Modules\AI\Actions;

use Modules\AI\Models\AiProfile;
use OGame\Models\ChatMessage;

class ReconcileAiChatObservationsAction
{
    private const MaximumReconciledMessages = 100;

    public function handle(int $playerId, int $limit = self::MaximumReconciledMessages): int
    {
        if ($limit < 1) {
            return 0;
        }

        if (!AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->exists()) {
            return 0;
        }

        $chatMessageIds = ChatMessage::query()
            ->where('recipient_id', $playerId)
            ->whereNull('alliance_id')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $createdObservations = 0;

        foreach ($chatMessageIds as $chatMessageId) {
            $observation = app(RecordObservedChatMessageAction::class)->handle($chatMessageId);

            if ($observation?->wasRecentlyCreated) {
                $createdObservations++;
            }
        }

        return $createdObservations;
    }
}
