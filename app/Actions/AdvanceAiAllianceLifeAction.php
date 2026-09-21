<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Domain\Social\AllianceChoice;
use Modules\AI\Models\AiProfile;
use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\AllianceService;

/**
 * Advances cooperative alliance life: an enabled account with no alliance and no outstanding
 * application applies to the alliance its tier would join. The host owns membership; an existing
 * membership and a pending application leave the account alone, and an alliance that already
 * rejected it is skipped by the choice, so the pass never spams the same alliance. An account
 * every alliance has refused founds its own club instead of staying alone forever (DEF-017).
 */
class AdvanceAiAllianceLifeAction
{
    /** The first club's tag; later founders derive their own from their name. */
    private const FIRST_ALLIANCE_TAG = 'ORBITAL';

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

        // A real player every alliance has refused founds their own club (DEF-017):
        // the same first-mover move the first founder made, one per pass so the
        // rest apply to the new club next pass instead of founding one club each.
        $advanced += $this->foundWhenLockedOut();

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

        return $this->foundAlliance($founderId, self::FIRST_ALLIANCE_TAG);
    }

    /**
     * The second and later alliances come from the same move as the first: an account
     * that cannot join anywhere founds its own. A farm (no rank) is not a founder.
     */
    private function foundWhenLockedOut(): int
    {
        if (!Alliance::query()->exists()) {
            return 0;
        }

        foreach (AiProfile::query()->where('enabled', true)->orderBy('player_id')->pluck('player_id') as $playerId) {
            if ($this->alreadyEngaged($playerId)) {
                continue;
            }

            if ((int) Highscore::query()->where('player_id', $playerId)->value('general_rank') <= 0) {
                continue;
            }

            if (app(AllianceChoice::class)->hasOpenCandidate($playerId)) {
                continue;
            }

            return $this->foundAlliance($playerId, $this->tagFor($playerId));
        }

        return 0;
    }

    /**
     * The tag is the founder's own name, not a module list: a club takes its founder's
     * identity, and the host enforces 3-8 chars and uniqueness.
     */
    private function tagFor(int $founderId): string
    {
        $username = (string) User::query()->whereKey($founderId)->value('username');
        $base = substr(strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $username)) . 'CLUB', 0, 6);

        foreach (['', '2', '3', '4', '5'] as $suffix) {
            $tag = $base . $suffix;

            if (!Alliance::query()->where('alliance_tag', $tag)->exists()) {
                return $tag;
            }
        }

        // ponytail: six candidates from one name cover a handful of founders; a
        // denser universe falls through to a tag createAlliance will refuse, and
        // the next pass retries.
        return $base;
    }

    private function foundAlliance(int $founderId, string $tag): int
    {
        try {
            app(AllianceService::class)->createAlliance($founderId, $tag, ucfirst(strtolower($tag)) . ' Pact');
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
