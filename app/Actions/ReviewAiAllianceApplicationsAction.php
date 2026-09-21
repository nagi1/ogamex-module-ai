<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Collection;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\AllianceHighscore;
use OGame\Models\AllianceMember;
use OGame\Models\Highscore;
use OGame\Services\AllianceService;

/**
 * The leader half of alliance life: an AI founder reviews its alliance's pending applications.
 *
 * The join half (DEF-003) applies accounts; this decides them, through the host's own
 * accept/reject path. The rule mirrors the alliance FAQ's taxonomy: the alliance's
 * points-per-member is the selectivity signal — an elite core takes only the top accounts, a
 * mass or just-founded alliance takes anyone active, and a normal alliance takes the top half.
 * A zero-rank applicant is declined immediately (a farm or multi); everything else waits out a
 * minimum age and is decided a bounded number per pass, so membership grows like a leader
 * deciding, not a script.
 */
class ReviewAiAllianceApplicationsAction
{
    /** An application must sit this long before the leader decides. */
    private const MINIMUM_APPLICATION_AGE_MINUTES = 15;

    /** The leader accepts at most this many per pass. */
    private const MAXIMUM_ACCEPTS_PER_PASS = 1;

    /** Top this share of the population is always accepted. */
    private const STRONG_RANK_RATIO = 0.15;

    /** Bottom this share is accepted only by a mass or just-founded alliance. */
    private const NEWBIE_RANK_RATIO = 0.5;

    /** Points per member at or above which an alliance reads as an elite core. */
    private const ELITE_POINTS_PER_MEMBER = 1_000_000;

    /** Points per member at or below which an alliance reads as mass / still recruiting. */
    private const MASS_POINTS_PER_MEMBER = 10_000;

    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(): int
    {
        $decided = 0;

        foreach ($this->aiLedAlliances() as $alliance) {
            $decided += $this->review($alliance);
        }

        return $decided;
    }

    /** @return array<int, Alliance> */
    private function aiLedAlliances(): array
    {
        $founderIds = AiProfile::query()->where('enabled', true)->pluck('player_id');

        return Alliance::query()->whereIn('founder_user_id', $founderIds)->get()->all();
    }

    private function review(Alliance $alliance): int
    {
        $now = $this->clock->now();
        $decided = 0;
        $accepted = 0;

        foreach ($this->pendingApplications($alliance) as $application) {
            $ratio = $this->rankRatio((int) $application->user_id);

            // A farm or multi has no rank: declined without waiting for the age floor.
            if ($ratio === null) {
                $decided += $this->reject($alliance, $application);

                continue;
            }

            if ($this->ageMinutes($application, $now) < self::MINIMUM_APPLICATION_AGE_MINUTES) {
                continue;
            }

            if ($accepted >= self::MAXIMUM_ACCEPTS_PER_PASS) {
                break;
            }

            if (!$this->shouldAccept($ratio, $alliance)) {
                $decided += $this->reject($alliance, $application);

                continue;
            }

            if ($this->accept($alliance, $application)) {
                $accepted++;
                $decided++;
            }
        }

        return $decided;
    }

    /**
     * @return Collection<int, AllianceApplication>
     */
    private function pendingApplications(Alliance $alliance): Collection
    {
        return app(AllianceService::class)->getPendingApplications($alliance->id);
    }

    private function rankRatio(int $playerId): float|null
    {
        $rank = (int) Highscore::query()->where('player_id', $playerId)->value('general_rank');
        $population = (int) Highscore::query()->where('general_rank', '>', 0)->count();

        if ($rank <= 0 || $population <= 0) {
            return null;
        }

        return $rank / $population;
    }

    private function shouldAccept(float $ratio, Alliance $alliance): bool
    {
        if ($ratio <= self::STRONG_RANK_RATIO) {
            return true;
        }

        $ppm = $this->pointsPerMember($alliance);

        if ($ppm >= self::ELITE_POINTS_PER_MEMBER) {
            return false;
        }

        if ($ppm <= self::MASS_POINTS_PER_MEMBER) {
            return true;
        }

        return $ratio < self::NEWBIE_RANK_RATIO;
    }

    private function pointsPerMember(Alliance $alliance): float
    {
        $members = max(1, (int) AllianceMember::query()->where('alliance_id', $alliance->id)->count());
        $points = (int) (AllianceHighscore::query()->where('alliance_id', $alliance->id)->value('general') ?? 0);

        return $points / $members;
    }

    private function ageMinutes(AllianceApplication $application, CarbonImmutable $now): int
    {
        if ($application->created_at === null) {
            return 0;
        }

        return max(0, (int) $application->created_at->diffInMinutes($now));
    }

    private function accept(Alliance $alliance, AllianceApplication $application): bool
    {
        try {
            app(AllianceService::class)->acceptApplication($application->id, $alliance->founder_user_id);
        } catch (Exception) {
            return false;
        }

        // DEF-007: a leader communicates with a new member. Delivery is best-effort.
        app(DeliverAiDirectReplyAction::class)->handle(
            $alliance->founder_user_id,
            (int) $application->user_id,
            'Welcome to ' . $alliance->alliance_tag . '. Settle in and check the alliance page.',
        );

        return true;
    }

    private function reject(Alliance $alliance, AllianceApplication $application): bool
    {
        try {
            app(AllianceService::class)->rejectApplication($application->id, $alliance->founder_user_id);

            return true;
        } catch (Exception) {
            return false;
        }
    }
}
