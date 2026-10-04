<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Models\AiActionReceipt;

/**
 * What the host or the dispatch gate refused this account a moment ago.
 *
 * A refused dispatch is not a mission, so nothing the planners read recorded it and the same target or
 * the same origin was offered again on the next login (measured 4 Oct 2026: one account tried one
 * target 21 times in six hours). A player who was told "that planet is online" leaves it alone for a
 * while; this is that memory, read from the receipts the gate already writes.
 */
class RecentRefusals
{
    private const WINDOW_MINUTES = 45;

    public function target(int $playerId, int $galaxy, int $system, int $position): bool
    {
        return $this->recent($playerId)->contains(
            fn (AiActionReceipt $receipt): bool => ($receipt->result['decision']['target_galaxy'] ?? null) === $galaxy
                && ($receipt->result['decision']['target_system'] ?? null) === $system
                && ($receipt->result['decision']['target_position'] ?? null) === $position,
        );
    }

    public function origin(int $playerId, int $planetId, string $reason): bool
    {
        return $this->recent($playerId)->contains(
            fn (AiActionReceipt $receipt): bool => ($receipt->result['planet_id'] ?? null) === $planetId
                && ($receipt->result['reason'] ?? null) === $reason,
        );
    }

    /** @return \Illuminate\Support\Collection<int, AiActionReceipt> */
    private function recent(int $playerId)
    {
        return AiActionReceipt::query()
            ->where('player_id', $playerId)
            ->where('action_type', AiActionType::DispatchFleet->value)
            ->where('state', AiReceiptState::Rejected->value)
            ->where('created_at', '>', now()->subMinutes(self::WINDOW_MINUTES))
            ->get();
    }
}
