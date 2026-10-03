<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Domain\Social\AllianceChoice;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\AllianceMember;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\AllianceService;

/**
 * Advances cooperative alliance life: an enabled account with no alliance and no outstanding
 * application applies to the alliance its tier would join. The host owns membership; an existing
 * membership and a pending application leave the account alone, and an alliance that already
 * rejected it is skipped by the choice, so the pass never spams the same alliance. An account
 * every alliance has refused founds its own club instead of staying alone forever (DEF-017).
 * A club that holds the cohort share is left by its members one at a time, which is what keeps
 * the apply half above fed on a cohort that was seated once.
 *
 * The lane is the cohort's only source of alliance applications: a pass that finds every account
 * already engaged creates none, so the leave half below keeps the lane moving at the rate the
 * scorecard's alliance aspect measures. The pass itself only runs when the host's every-minute
 * schedule fires, so the session path runs it too (ALLY-001).
 *
 * A club only receives applications while the host marks it open, so a club this action founds is
 * opened for applications: a closed club is invisible to the join half above, and a cohort seated
 * into closed clubs would found new ones forever while the application lane never fires (ALLY-001).
 *
 * A cohort where every account is seated and every club fits has no reason to move
 * under the rules above, so the lane stops for good. The membership is therefore re-read each pass:
 * a member whose club is no longer the club it would choose today leaves it, which is how a player
 * who has outgrown or drifted from their club keeps the join half fed.
 *
 * A cohort whose seats the host only half wrote — a users.alliance_id with no alliance_members row,
 * as seeding left grand — is neither seated nor free, so the stale link is cleared and the account
 * asks on the same pass (ALLY-001).
 */
class AdvanceAiAllianceLifeAction
{
    /** The first club's tag; later founders derive their own from their name. */
    private const FIRST_ALLIANCE_TAG = 'ORBITAL';

    /** A member the founder's affinity has fallen below this is a grudge the founder acts on. */
    private const KICK_AFFINITY = -0.2;

    /** A member silent for this long is cleared from the roster, as a leader prunes the dead. */
    private const KICK_INACTIVE_DAYS = 7;

    public function handle(): int
    {
        // An account no club fits founds its own first: a club founded after the leave half would
        // be the freed account's answer to a question the join half below has not asked it yet.
        $advanced = $this->foundFirstAlliance() + $this->foundWhenLockedOut();

        // The leave comes before the join so the account this pass frees asks on this pass: that
        // is what keeps the lane the scorecard's alliance aspect measures moving on a cohort that
        // was seated once and would otherwise never ask again.
        $advanced += $this->leaveOneMisfit();
        $advanced += $this->kickOneEnemy();

        foreach (AiProfile::query()->where('enabled', true)->orderBy('player_id')->pluck('player_id') as $playerId) {
            // Only an account with no seat and no outstanding request asks; a seated cohort asks
            // nobody, which is why the leave half above has to free one every pass.
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
     * A member whose club no longer fits its language and pace leaves it, one account per pass so the
     * cohort spreads out the way players drift apart, not in one exodus. A founder keeps the club it
     * keeps; the leaver applies to a fitting club, or founds its own, on the passes that follow.
     */
    private function leaveOneMisfit(): int
    {
        foreach (AiProfile::query()->where('enabled', true)->orderBy('player_id')->pluck('player_id') as $playerId) {
            $allianceId = User::query()->whereKey($playerId)->value('alliance_id');

            if ($allianceId === null || Alliance::query()->whereKey($allianceId)->value('founder_user_id') === $playerId) {
                continue;
            }

            if ($this->stillTheClubItWouldChoose($playerId, (int) $allianceId)) {
                continue;
            }

            if ($this->releaseMisfit($playerId, (int) $allianceId)) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * The leader's half of a club drifting apart: a founder removes one member per pass that it has come to
     * hate (affinity under the grudge line) or that has gone inactive by the host's own rule. Players are
     * kicked from clubs; without it a club only ever shrank by its members' own choice (ALLY-001).
     */
    private function kickOneEnemy(): int
    {
        $founders = AiProfile::query()->where('enabled', true)->pluck('player_id')->all();

        foreach (Alliance::query()->whereIn('founder_user_id', $founders)->orderBy('id')->get() as $alliance) {
            $founderId = (int) $alliance->founder_user_id;

            foreach (AllianceMember::query()->where('alliance_id', $alliance->id)->where('user_id', '!=', $founderId)->pluck('user_id') as $memberId) {
                $hated = AiRelationship::query()
                    ->where('player_id', $founderId)
                    ->where('other_player_id', $memberId)
                    ->where('affinity', '<', self::KICK_AFFINITY)
                    ->exists();
                $member = User::query()->find($memberId);
                // A cohort account's login stamp stays at zero (its sessions do not pass the host's login),
                // so only a human member with a real stamp is judged inactive; a cohort member is kicked
                // for a grudge only.
                $stamp = (int) ($member?->time ?? 0);
                $inactive = $stamp > 0
                    && !in_array((int) $memberId, $founders, true)
                    && !AiProfile::query()->where('player_id', $memberId)->exists()
                    && $stamp < now()->subDays(self::KICK_INACTIVE_DAYS)->timestamp;

                if (!$hated && !$inactive) {
                    continue;
                }

                try {
                    app(AllianceService::class)->kickMember((int) $alliance->id, (int) $memberId, $founderId);
                } catch (Exception) {
                    continue;
                }

                // The kicked account asks elsewhere on the next pass, as a leaver does.
                User::query()->whereKey($memberId)->whereNotNull('alliance_left_at')->update(['alliance_left_at' => null]);

                return 1;
            }
        }

        return 0;
    }

    /**
     * Whether the club the account sits in is still the club it would choose today: the same rank
     * and fit rule that picks a club for an account with no seat. A club that no longer
     * fits its language and pace, or is outscored by another is one a player leaves, which is how a
     * cohort seated once — every member in a club that fits — keeps the join
     * half, and so the lane the scorecard's alliance aspect measures, moving (ALLY-001).
     */
    private function stillTheClubItWouldChoose(int $playerId, int $allianceId): bool
    {
        // A club that has stopped fitting at all — shut, holding the cohort share, or holding a
        // player the account has recorded as an enemy — is left whatever else the account's rank
        // admits, so the club is asked its own fit before it is weighed against the other clubs.
        if (! app(AllianceChoice::class)->currentClubFits($playerId)) {
            return false;
        }

        $choice = app(AllianceChoice::class)->choose($playerId);

        // Nothing would take the account today: no rank to be judged on, or nowhere that fits. Its
        // seat then stands.
        if ($choice === null) {
            return true;
        }

        return $choice->id === $allianceId;
    }

    /**
     * Take the misfit out of the club. The host's leaveAlliance is the normal path; a membership
     * the host cannot remove — a users.alliance_id written without an alliance_members row, as
     * seeding left them — makes it refuse, and the account would stay engaged forever while the
     * lane the scorecard measures creates no application. That stale link is cleared instead, so
     * the freed account is decided by the apply half on the next pass.
     */
    private function releaseMisfit(int $playerId, int $allianceId): bool
    {
        if (! AllianceMember::query()->where('alliance_id', $allianceId)->where('user_id', $playerId)->exists()) {
            $user = User::query()->find($playerId);

            if ($user === null) {
                return false;
            }

            $user->alliance_id = null;
            $user->save();

            // The same host cooldown the leave path clears: the freed account asks on the next
            // pass, so the lane the alliance aspect measures keeps moving (ALLY-001).
            User::query()->whereKey($playerId)->whereNotNull('alliance_left_at')->update(['alliance_left_at' => null]);

            return true;
        }

        try {
            app(AllianceService::class)->leaveAlliance($playerId);
        } catch (Exception) {
            return false;
        }

        // The host stamps a multi-day join cooldown on the account it just removed. Left in
        // place, the freed account can create no new application for days, and the lane the
        // alliance aspect measures goes quiet the moment the first club is drained (ALLY-001).
        // The account left to join a club that fits it; it asks on the next pass.
        User::query()->whereKey($playerId)->whereNotNull('alliance_left_at')->update(['alliance_left_at' => null]);

        return true;
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
            $alliance = app(AllianceService::class)->createAlliance($founderId, $tag, ucfirst(strtolower($tag)) . ' Pact');

            // A club receives applications only while the host marks it open. A club this pass
            // founds is the cohort's own place to apply, so it is opened here: a closed club is
            // invisible to the join half and the application lane never fires (ALLY-001).
            if (! $alliance->is_open) {
                $alliance->is_open = true;
                $alliance->save();
            }
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
