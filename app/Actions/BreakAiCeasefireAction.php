<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiCommitmentState;
use Modules\AI\Enums\AiMemoryEvidenceKind;
use Modules\AI\Enums\AiMemoryPredicate;
use Modules\AI\Models\AiCommitment;

/**
 * A hit inside an agreed ceasefire ends it: the truce is marked broken, so neither side spares the other any more and
 * the battle's own threat stands as the grudge.
 */
class BreakAiCeasefireAction
{
    public function handle(int $attackerPlayerId, int $defenderPlayerId): int
    {
        $broken = 0;
        $truces = AiCommitment::query()->heldCeasefire($attackerPlayerId)
            ->where(static fn ($side) => $side->where('player_id', $defenderPlayerId)->orWhere('counterparty_player_id', $defenderPlayerId))
            ->get();

        foreach ($truces as $truce) {
            // The terms stay as agreed; who broke it is added beside them so the wronged side can say so once.
            $truce->update(['state' => AiCommitmentState::Broken, 'terms' => $truce->terms + ['broken_by' => $attackerPlayerId], 'revision' => $truce->revision + 1]);
            $broken++;

            // The wronged side remembers it, in words a later conversation can be reminded of.
            $wrongedId = (int) $truce->player_id === $attackerPlayerId ? (int) $truce->counterparty_player_id : (int) $truce->player_id;
            app(RecordAiMemoryFactAction::class)->handle(
                $wrongedId,
                $attackerPlayerId,
                AiMemoryPredicate::TruceBroken,
                AiMemoryEvidenceKind::Verified,
                ['summary' => 'they hit us during an agreed ceasefire'],
                (int) $truce->source_observation_id,
                CarbonImmutable::now(),
            );
        }

        return $broken;
    }
}
