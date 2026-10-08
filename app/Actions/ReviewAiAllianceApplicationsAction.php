<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Collection;
use Laravel\Ai\Classification\Choice;
use Modules\AI\Enums\AiToMStance;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\PsychSimTheoryOfMind;
use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\AllianceHighscore;
use OGame\Models\AllianceMember;
use OGame\Models\Highscore;
use OGame\Models\User;
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
    /** An application must sit this long before the leader decides it, so the leader reads it. */
    private const MINIMUM_APPLICATION_AGE_MINUTES = 15;

    /** The leader accepts at most this many per pass. */
    private const MAXIMUM_ACCEPTS_PER_PASS = 1;

    /** Top this share of the population is always accepted. */
    private const STRONG_RANK_RATIO = 0.15;

    /** Bottom this share is accepted only by a mass or just-founded alliance. */
    private const NEWBIE_RANK_RATIO = 0.5;

    /** Points per member at or above which an alliance reads as an elite core. */
    private const ELITE_POINTS_PER_MEMBER = 1_000_000;

    /** An applicant holding this share of the alliance's points per member is a peer, whatever the alliance's tier. */
    private const PEER_SHARE = 0.5;

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

        return Alliance::query()->whereIn('founder_user_id', $founderIds)->orderBy('id')->get()->all();
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

            if (!$this->shouldAccept($ratio, $alliance, (int) $application->user_id)) {
                $decided += $this->reject($alliance, $application);

                continue;
            }

            if ($this->readsAsExploitative($alliance, (int) $application->user_id) || $this->applicationReadsAsSpy($application)) {
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
        // The seat goes to the strongest applicant waiting, so applicants race for it rather than queue (alliance-politics.md).
        return app(AllianceService::class)->getPendingApplications($alliance->id)
            ->sortByDesc(fn (AllianceApplication $application): float => (float) (Highscore::query()->where('player_id', $application->user_id)->value('general') ?? 0))
            ->values();
    }

    private function rankRatio(int $playerId): float|null
    {
        $rank = (int) Highscore::query()->where('player_id', $playerId)->value('general_rank');
        $population = (int) Highscore::query()->where('general_rank', '>', 0)->count();

        if ($rank > 0 && $population > 0) {
            return $rank / $population;
        }

        // The rank columns are written by the host's ranking pass; a universe where it has not run
        // (the cohort's) holds points with every rank at zero, and every applicant read as a farm and
        // was declined, so no club ever accepted or welcomed anyone (ALLY-001). The points order the
        // ranking pass would write is the same answer; only an account with no points is a farm.
        $points = (float) (Highscore::query()->where('player_id', $playerId)->value('general') ?? 0);
        if ($points <= 0) {
            return null;
        }

        $scored = (int) Highscore::query()->where('general', '>', 0)->count();
        $ahead = (int) Highscore::query()->where('general', '>', $points)->count();

        return ($ahead + 1) / max(1, $scored);
    }

    private function shouldAccept(float $ratio, Alliance $alliance, int $applicantId): bool
    {
        if ($ratio <= self::STRONG_RANK_RATIO) {
            return true;
        }

        // An elite club takes a player as strong as its own members: past a million points per member every alliance read
        // as elite and a top-15% cut in a six-empire universe admitted no one, so each empire founded its own (9372).
        $ppm = $this->pointsPerMember($alliance);
        $points = (float) (Highscore::query()->where('player_id', $applicantId)->value('general') ?? 0);
        if ($points >= self::PEER_SHARE * $ppm && $points > 0) {
            return true;
        }

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

        // Both halves of a seat count, as the applicant side reads them: a cohort seated through the
        // host's pointer alone would read as an empty club here, and the leader would keep accepting
        // into the club the invariant already flags (ALLY-001).
        return User::query()->where('alliance_id', $alliance->id)->whereIn('id', $managers)->pluck('id')
            ->merge(AllianceMember::query()->where('alliance_id', $alliance->id)->whereIn('user_id', $managers)->pluck('user_id'))
            ->unique()
            ->count();
    }

    /**
     * A wary leader will not admit a player it models as likely to exploit the alliance, even
     * when the rank rule would take them. Only a recorded relationship can carry that read; a
     * stranger is decided on rank alone.
     */
    /**
     * A human's application text, read by the classifier: an enemy's alt fishing for the alliance's intel, a farm or
     * spam is refused as an exploitative stance is (llm-classifier-everywhere.md, step 3). An AI applicant's text is
     * authored and never read; with the classifier off nothing is.
     */
    private function applicationReadsAsSpy(AllianceApplication $application): bool
    {
        if (AiProfile::query()->where('player_id', $application->user_id)->exists() || trim((string) $application->application_message) === '') {
            return false;
        }

        $reading = app(ReadAiTextAction::class)->handle('application', (string) $application->application_message, [
            'kind' => new Choice('What is this OGame alliance application?', [
                'serious' => 'a player who wants to join and play with the alliance',
                'spy' => 'someone fishing for the alliance\'s members, their online times, fleets or plans, such as an enemy\'s second account',
                'farm' => 'an account that only wants protection or resources and will not play',
                'spam' => 'advertising or nonsense',
            ]),
        ]);

        return in_array($reading?->choice('kind', 0.6), ['spy', 'farm', 'spam'], true);
    }

    private function readsAsExploitative(Alliance $alliance, int $applicantId): bool
    {
        return app(PsychSimTheoryOfMind::class)->stanceToward((int) $alliance->founder_user_id, $applicantId) === AiToMStance::Defect;
    }
}
