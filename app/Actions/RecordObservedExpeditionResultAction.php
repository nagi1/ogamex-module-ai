<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use OGame\Models\Message;

/**
 * Reduces one committed expedition result message into an observation for the account that sent it.
 *
 * The result message is the legal boundary: the same row the player reads in game. A lost fleet is
 * its own kind so the expedition planner can stop after repeated losses without parsing keys; every
 * other outcome (loot, dark matter, ships, nothing, a battle) is a plain result the account has seen.
 */
class RecordObservedExpeditionResultAction
{
    private const LOSS_KEY = 'expedition_loss_of_fleet';

    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(int $messageId): AiObservation|null
    {
        /** @var Message|null $message */
        $message = Message::query()->find($messageId);

        if ($message === null || !str_starts_with((string) $message->key, 'expedition_')) {
            return null;
        }

        $playerId = (int) $message->user_id;
        if (!AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->exists()) {
            return null;
        }

        // The unique source identity makes retrying an after-commit callback safe.
        return AiObservation::firstOrCreateAtomically([
            'player_id' => $playerId,
            'source_type' => AiObservationSource::FleetMessage,
            'source_id' => $message->id,
        ], [
            'kind' => str_starts_with((string) $message->key, self::LOSS_KEY)
                ? AiObservationKind::ExpeditionFleetLost
                : AiObservationKind::ExpeditionResult,
            'subject_player_id' => null,
            'source_time' => $message->created_at ?? $this->clock->now(),
            'observed_at' => $this->clock->now(),
        ]);
    }
}
