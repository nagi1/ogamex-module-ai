<?php

namespace Modules\AI\Actions;

use Modules\AI\Models\AiProfile;
use OGame\Models\AllianceApplication;
use OGame\Models\User;

/**
 * Advances cooperative alliance life: an enabled account with no alliance and no outstanding
 * application applies to the alliance its tier would join. The host owns membership; an existing
 * membership and a pending application leave the account alone, and an alliance that already
 * rejected it is skipped by the choice, so the pass never spams the same alliance.
 */
class AdvanceAiAllianceLifeAction
{
    public function handle(): int
    {
        $applied = 0;

        foreach (AiProfile::query()->where('enabled', true)->pluck('player_id') as $playerId) {
            if ($this->alreadyEngaged($playerId)) {
                continue;
            }

            if (app(ApplyAiAllianceAction::class)->handle($playerId)->successful) {
                $applied++;
            }
        }

        return $applied;
    }

    private function alreadyEngaged(int $playerId): bool
    {
        $user = User::query()->find($playerId);

        if ($user === null || $user->alliance_id !== null) {
            return true;
        }

        return AllianceApplication::query()
            ->where('user_id', $playerId)
            ->where('status', AllianceApplication::STATUS_PENDING)
            ->exists();
    }
}
