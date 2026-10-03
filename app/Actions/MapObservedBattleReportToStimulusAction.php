<?php

namespace Modules\AI\Actions;

use Modules\AI\Domain\Cognition\ObservedStimulus;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Models\AiRelationship;
use OGame\Models\BattleReport;

/**
 * Turns one committed battle report into a bounded harm stimulus for its observer.
 *
 * The report is the only input. It stores each side's aggregated resource loss and both
 * player ids, so harm is the observer's share of the damage in that exchange rather than
 * an absolute figure the module would have to price from unit costs itself.
 *
 * Three limits are deliberate and version one. Only loss is read, so a battle can
 * produce harm but never aid. Threat is derived only from repetition: an attacker's earlier
 * reports against the same defender in the last day (DEF-34). An observer that did not come off worse is declined instead of
 * appraised, because Anger, Fear and Gratitude cannot express having won, and a
 * fabricated value would be worse than recording nothing.
 */
class MapObservedBattleReportToStimulusAction
{
    public function handle(int $playerId, AiArchetype $archetype, BattleReport $battleReport): ObservedStimulus|null
    {
        $defenderPlayerId = $this->playerId($battleReport->planet_user_id);
        $attackerPlayerId = $this->playerId($this->side($battleReport->attacker)['player_id'] ?? null);

        if ($defenderPlayerId === null || $attackerPlayerId === null) {
            return null;
        }

        if ($playerId !== $defenderPlayerId && $playerId !== $attackerPlayerId) {
            return null;
        }

        $defenderLoss = $this->loss($this->side($battleReport->defender));
        $attackerLoss = $this->loss($this->side($battleReport->attacker));

        if ($defenderLoss === null || $attackerLoss === null) {
            return null;
        }

        $isDefender = $playerId === $defenderPlayerId;
        $ownLoss = $isDefender ? $defenderLoss : $attackerLoss;
        $opponentLoss = $isDefender ? $attackerLoss : $defenderLoss;
        $totalLoss = $ownLoss + $opponentLoss;

        if ($totalLoss <= 0) {
            return null;
        }

        $ownShare = $ownLoss / $totalLoss;

        if ($ownShare < 0.5) {
            return null;
        }

        $counterpartyPlayerId = $isDefender ? $attackerPlayerId : $defenderPlayerId;

        return app()->makeWith(ObservedStimulus::class, [
            'archetype' => $archetype,
            'harm' => $ownShare,
            'aid' => 0.0,
            'threat' => $isDefender ? $this->threat($ownShare, $playerId, $attackerPlayerId, $battleReport) : 0.0,
            'relationshipTrust' => $this->trust($playerId, $counterpartyPlayerId),
        ]);
    }

    /**
     * An attacker who keeps coming back is a danger beyond this one exchange (DEF-34): each earlier
     * report in the day where the same attacker hit the same defender adds a tenth to the harm,
     * so a repeat raider is the one Fear can overtake Anger for. A first attack carries no threat
     * beyond its harm, which is what version one declared.
     */
    private function threat(float $harm, int $defenderId, int $attackerId, BattleReport $battleReport): float
    {
        $earlier = BattleReport::query()
            ->where('planet_user_id', $defenderId)
            ->where('attacker->player_id', $attackerId)
            ->where('id', '<', $battleReport->id)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return min(1.0, $harm + 0.1 * $earlier);
    }

    /**
     * The report stores each side as an array column, so an unfamiliar shape is declined
     * rather than read through an assumption about which mission wrote it.
     *
     * @return array<string, mixed>
     */
    private function side(mixed $side): array
    {
        return is_array($side) ? $side : [];
    }

    private function playerId(mixed $value): int|null
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $side */
    private function loss(array $side): float|null
    {
        $loss = $side['resource_loss'] ?? null;

        return is_numeric($loss) ? max(0.0, (float) $loss) : null;
    }

    private function trust(int $playerId, int $counterpartyPlayerId): float
    {
        $relationship = AiRelationship::query()
            ->where('player_id', $playerId)
            ->where('other_player_id', $counterpartyPlayerId)
            ->first();

        if ($relationship === null) {
            return 0.0;
        }

        return min(1.0, max(0.0, (float) $relationship->trust));
    }
}
