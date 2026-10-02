<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Collection;
use Modules\AI\Enums\AiToMStance;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\PsychSimTheoryOfMind;
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
    /** An application must sit this long before the leader decides, so the leader reads it. */
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

    /** The behaviour file's recruitment ceiling, read once per run. */
    private array|null $recruitmentCeiling = null;

    private bool $recruitmentCeilingRead = false;

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

            if ($this->readsAsExploitative($alliance, (int) $application->user_id)) {
                $decided += $this->reject($alliance, $application);

                continue;
            }

            if ($this->atRecruitmentCeiling($alliance, (int) $application->user_id)) {
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
        $stamp = $application->created_at ?? $application->updated_at;

        if ($stamp === null) {
            return 0;
        }

        return max(0, (int) $stamp->diffInMinutes($now));
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

    /**
     * ALLY-001: no single alliance may hold the AI cohort. An ai-managed applicant that would
     * take its alliance to the invariant share or past it is refused however well it ranks, so
     * membership spreads instead of the whole cohort ending up in the one alliance seeded first.
     * Only ai-managed accounts count: a human applicant is not part of the cohort's share.
     */
    private function atRecruitmentCeiling(Alliance $alliance, int $applicantId): bool
    {
        $policy = $this->recruitmentPolicy();

        if ($policy === null || !$this->isAiManaged($applicantId)) {
            return false;
        }

        $cohort = $this->aiCohort();

        if ($cohort < $policy['minimum_cohort']) {
            return false;
        }

        return ($this->aiMemberCount($alliance) + 1) / $cohort >= $policy['share_ceiling'];
    }

    /**
     * Read by name from resources/behavior so the share the cohort invariant states is a number
     * a tuning pass moves without touching this class. A missing or unreadable file leaves the
     * alliance's own rules in force rather than a default the source does not state.
     *
     * @return array{share_ceiling: float, minimum_cohort: int}|null
     */
    private function recruitmentPolicy(): array|null
    {
        if ($this->recruitmentCeilingRead) {
            return $this->recruitmentCeiling;
        }

        $this->recruitmentCeilingRead = true;
        $path = dirname(__DIR__, 2) . '/resources/behavior/alliance-recruitment.yaml';
        $raw = is_file($path) ? (string) file_get_contents($path) : '';

        if (!preg_match('/^share_ceiling:\s*([0-9.]+)/m', $raw, $share) || !preg_match('/^minimum_cohort:\s*([0-9]+)/m', $raw, $cohort)) {
            return $this->recruitmentCeiling = null;
        }

        return $this->recruitmentCeiling = [
            'share_ceiling' => (float) $share[1],
            'minimum_cohort' => (int) $cohort[1],
        ];
    }

    private function isAiManaged(int $playerId): bool
    {
        return AiProfile::query()->where('player_id', $playerId)->exists();
    }

    private function aiCohort(): int
    {
        return AiProfile::query()->where('enabled', true)->count();
    }

    private function aiMemberCount(Alliance $alliance): int
    {
        $managers = AiProfile::query()->where('enabled', true)->pluck('player_id');

        return AllianceMember::query()->where('alliance_id', $alliance->id)->whereIn('user_id', $managers)->count();
    }

    /**
     * A wary leader will not admit a player it models as likely to exploit the alliance, even
     * when the rank rule would take them. Only a recorded relationship can carry that read; a
     * stranger is decided on rank alone.
     */
    private function readsAsExploitative(Alliance $alliance, int $applicantId): bool
    {
        return app(PsychSimTheoryOfMind::class)->stanceToward((int) $alliance->founder_user_id, $applicantId) === AiToMStance::Defect;
    }
}
