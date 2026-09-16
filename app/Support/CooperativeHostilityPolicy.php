<?php

namespace Modules\AI\Support;

use Modules\AI\Models\AiProfile;
use OGame\Contracts\HostilityPolicy;

/**
 * Blocks human-on-human hostility in a cooperative universe.
 *
 * The policy is read-only: it answers "are both sides coalition?" from the module's own
 * account table and never writes host state; the host enforces the answer. A player with
 * an enabled AI profile is the faction, everyone else is the coalition, so a hostile action
 * between two coalition members is forbidden while one against the faction is allowed.
 */
class CooperativeHostilityPolicy implements HostilityPolicy
{
    public function forbids(int $attackerPlayerId, int $defenderPlayerId): bool
    {
        return !$this->isFaction($attackerPlayerId) && !$this->isFaction($defenderPlayerId);
    }

    private function isFaction(int $playerId): bool
    {
        return AiProfile::query()
            ->where('player_id', $playerId)
            ->where('enabled', true)
            ->exists();
    }
}
