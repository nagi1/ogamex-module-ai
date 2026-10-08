<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Domain\Social\AllianceAmbition;
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
 * Advances alliance life (alliance-politics.md): an account that wants an alliance (persona taste,
 * AllianceAmbition) and has no seat or pending application applies to the club its tier would join;
 * a solo account asks nobody. Only a leader founds a club, when none of its tier will take it, so a
 * universe ends with a few clubs, some solo players and selective elites, not one club per account.
 * A member leaves only when its club stops fitting (shut, the cohort share, an enemy, language and
 * pace), and the host's join cooldown stands for it. A founder kicks a member it has come to hate or
 * a human gone inactive. The host owns membership throughout.
 *
 * A club only receives applications while the host marks it open, so a club this action founds is
 * opened for applications.
 *
 * A seat the host only half wrote (a users.alliance_id with no alliance_members row, as seeding left
 * grand) is neither seated nor free, so the stale link is cleared and the account asks on the same pass.
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
            // A solo player asks nobody (alliance-politics.md).
            if ($this->alreadyEngaged($playerId) || !app(AllianceAmbition::class)->wantsAlliance($playerId)) {
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
        // Alliances that have been hitting each other go to war; those with a common enemy sign pacts (alliance-politics.md).
        $advanced += app(AdvanceAiAlliancePoliticsAction::class)->handle();

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

            // A seat is left when the club stops fitting (shut, the cohort share, an enemy, language and pace), never because
            // another club scored higher: that was churn kept up for the application lane (ALLY-001).
            if (app(AllianceChoice::class)->currentClubFits($playerId)) {
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

                return 1;
            }
        }

        return 0;
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

            return true;
        }

        try {
            app(AllianceService::class)->leaveAlliance($playerId);
        } catch (Exception) {
            return false;
        }

        // The host's join cooldown stands: a player who walks out waits like everyone else.
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

        $ambition = app(AllianceAmbition::class);
        $free = AiProfile::query()->where('enabled', true)->orderBy('player_id')->pluck('player_id')
            ->reject(fn (int $playerId): bool => $this->alreadyEngaged($playerId));
        // A universe where nobody drew the leader's trait still gets its first club: the joiner whose kind most readily
        // leads founds it, as someone always does on a real server (9402, 9405: no alliance in twelve hours).
        $founderId = $free->first(fn (int $playerId): bool => $ambition->leads($playerId))
            ?? $free->filter(fn (int $playerId): bool => $ambition->wantsAlliance($playerId) && $ambition->leadShare($playerId) > 0.0)
                ->sortByDesc(fn (int $playerId): float => $ambition->leadShare($playerId))->first();

        if ($founderId === null) {
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

        $ambition = app(AllianceAmbition::class);
        $lockedOut = AiProfile::query()->where('enabled', true)->orderBy('player_id')->pluck('player_id')
            ->reject(fn (int $playerId): bool => $this->alreadyEngaged($playerId) || !$ambition->wantsAlliance($playerId)
                || (int) Highscore::query()->where('player_id', $playerId)->value('general_rank') <= 0
                || app(AllianceChoice::class)->hasOpenCandidate($playerId))
            ->values();
        // Only a leader founds: every locked-out account founding its own left six empires in six clubs (9372). With no
        // drawn leader among them, a joiner no club fits founds one when another such joiner would follow it, the kind
        // that most readily leads first (9414-9417: a raider and a miner stayed solo all day beside a two-seat club;
        // 9425: without the follower, three one-seat clubs nobody could ask).
        $founderId = $lockedOut->first(fn (int $playerId): bool => $ambition->leads($playerId))
            ?? $lockedOut->filter(fn (int $playerId): bool => $ambition->leadShare($playerId) > 0.0
                    && $lockedOut->contains(fn (int $other): bool => $other !== $playerId && app(AllianceChoice::class)->wouldFollow($playerId, $other)))
                ->sortByDesc(fn (int $playerId): float => $ambition->leadShare($playerId))->first();

        return $founderId === null ? 0 : $this->foundAlliance($founderId, $this->tagFor($founderId));
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
