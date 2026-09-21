<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Models\AiProfile;
use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\User;
use OGame\Services\AllianceService;

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
        $advanced = $this->foundFirstAlliance();

        foreach (AiProfile::query()->where('enabled', true)->pluck('player_id') as $playerId) {
            if ($this->alreadyEngaged($playerId)) {
                continue;
            }

            if (app(ApplyAiAllianceAction::class)->handle($playerId)->successful) {
                $advanced++;
            }
        }

        // The leader half of the same social pass: decide pending applications (DEF-006/007)
        // and answer buddy requests from known contacts (DEF-009).
        $advanced += app(ReviewAiAllianceApplicationsAction::class)->handle();
        $advanced += app(ReviewAiBuddyRequestsAction::class)->handle();

        return $advanced;
    }

    /**
     * A universe with no alliance has nothing to apply to, so the first eligible
     * account founds one — the same first-mover move a player makes — and the
     * rest apply to it on later passes (DISC-012).
     */
    private function foundFirstAlliance(): int
    {
        if (Alliance::query()->exists()) {
            return 0;
        }

        $founderId = AiProfile::query()->where('enabled', true)->orderBy('player_id')->value('player_id');

        if ($founderId === null || $this->alreadyEngaged($founderId)) {
            return 0;
        }

        try {
            app(AllianceService::class)->createAlliance($founderId, 'ORBITAL', 'Orbital Pact');
        } catch (Exception $exception) {
            // A cooldown or tag collision leaves the universe unchanged; the next
            // pass retries with the next eligible account.
            return 0;
        }

        return 1;
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
