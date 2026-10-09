<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiCommitmentState;
use Modules\AI\Models\AiCommitment;

/**
 * A hit inside an agreed ceasefire ends it: the truce is marked broken, so neither side spares the other any more and
 * the battle's own threat stands as the grudge.
 */
class BreakAiCeasefireAction
{
    public function handle(int $attackerPlayerId, int $defenderPlayerId): int
    {
        return AiCommitment::query()->heldCeasefire($attackerPlayerId)
            ->where(static fn ($side) => $side->where('player_id', $defenderPlayerId)->orWhere('counterparty_player_id', $defenderPlayerId))
            ->update(['state' => AiCommitmentState::Broken]);
    }
}
