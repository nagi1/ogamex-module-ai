### EDIT: app/Actions/ReviewAiAllianceApplicationsAction.php
<<<<<<< SEARCH
use OGame\Services\AllianceService;

/**
 * The leader half of alliance life: an AI founder reviews its alliance's pending applications.
=======
use OGame\Services\AllianceService;
use Symfony\Component\Yaml\Yaml;

/**
 * The leader half of alliance life: an AI founder reviews its alliance's pending applications.
>>>>>>> REPLACE

<<<<<<< SEARCH
 * mass or just-founded alliance takes anyone active, and a normal alliance takes the top half.
 * A zero-rank applicant is declined immediately (a farm or multi); everything else waits out a
 * minimum age and is decided a bounded number per pass, so membership grows like a leader
 * deciding, not a script.
 */
=======
 * mass or just-founded alliance takes anyone active, and a normal alliance takes the top half.
 * A zero-rank applicant is declined immediately (a farm or multi); everything else waits out a
 * minimum age and is decided a bounded number per pass, so membership grows like a leader
 * deciding, not a script.
 *
 * Every number the leader uses — the age floor, the per-pass throttle, the selectivity ratios and
 * the share of the AI cohort one alliance may hold — comes from
 * resources/behavior/alliance_lane.yaml, so retuning recruitment is a data edit.
 */
>>>>>>> REPLACE

<<<<<<< SEARCH
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
=======
    /** The lane's numbers live here: retuning recruitment is a data edit, not a code edit. */
    private const LANE_FILE = 'resources/behavior/alliance_lane.yaml';
>>>>>>> REPLACE

<<<<<<< SEARCH
    public function handle(): int
    {
        $decided = 0;

        foreach ($this->aiLedAlliances() as $alliance) {
            $decided += $this->review($alliance);
        }

        return $decided;
    }
=======
    public function handle(): int
    {
        $lane = $this->lane();

        $decided = 0;

        foreach ($this->aiLedAlliances() as $alliance) {
            $decided += $this->review($alliance, $lane);
        }

        return $decided;
    }

    /**
     * The leader's policy — the age floor, the per-pass throttle, the selectivity by rank and
     * points-per-member, and the share of the cohort one alliance may hold — is read from the
     * lane file, so a modder retunes recruitment without touching this class.
     *
     * @return array<string, mixed>
     */
    private function lane(): array
    {
        return Yaml::parseFile(dirname(__DIR__, 2) . '/' . self::LANE_FILE);
    }
>>>>>>> REPLACE

<<<<<<< SEARCH
    private function review(Alliance $alliance): int
    {
        $now = $this->clock->now();
=======
    private function review(Alliance $alliance, array $lane): int
    {
        // ALLIANCE_SHARE: seeding left one alliance holding three quarters of the cohort. The
        // review pass does not add to that — at the lane's share limit the leader stops
        // recruiting and its pending applications wait for another alliance to take them.
        if ($this->atCohortShareLimit($alliance, (float) $lane['cohort']['alliance_share_limit'])) {
            return 0;
        }

        $now = $this->clock->now();
>>>>>>> REPLACE

<<<<<<< SEARCH
            if ($this->ageMinutes($application, $now) < self::MINIMUM_APPLICATION_AGE_MINUTES) {
                continue;
            }

            if ($accepted >= self::MAXIMUM_ACCEPTS_PER_PASS) {
                break;
            }

            if (!$this->shouldAccept($ratio, $alliance)) {
=======
            if ($this->ageMinutes($application, $now) < (int) $lane['review']['minimum_application_age_minutes']) {
                continue;
            }

            if ($accepted >= (int) $lane['review']['maximum_accepts_per_pass']) {
                break;
            }

            if (!$this->shouldAccept($ratio, $alliance, $lane['review'])) {
>>>>>>> REPLACE

<<<<<<< SEARCH
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
=======
    /** @param array<string, mixed> $review */
    private function shouldAccept(float $ratio, Alliance $alliance, array $review): bool
    {
        if ($ratio <= (float) $review['strong_rank_ratio']) {
            return true;
        }

        $ppm = $this->pointsPerMember($alliance);

        if ($ppm >= (float) $review['elite_points_per_member']) {
            return false;
        }

        if ($ppm <= (float) $review['mass_points_per_member']) {
            return true;
        }

        return $ratio < (float) $review['newbie_rank_ratio'];
    }

    /**
     * ALLIANCE_SHARE: the share of the AI cohort one alliance may hold. The number that counts is
     * the alliance's AI members against every AI profile on the server, not its raw membership —
     * a human member does not count against the limit. Seeding decided membership once and left
     * one alliance holding most of the cohort; at the lane's limit its leader stops recruiting, so
     * the review pass cannot rebuild that shape.
     */
    private function atCohortShareLimit(Alliance $alliance, float $limit): bool
    {
        $cohort = AiProfile::query()->count();

        if ($cohort === 0) {
            return false;
        }

        $held = AllianceMember::query()
            ->where('alliance_id', $alliance->id)
            ->whereIn('user_id', AiProfile::query()->select('player_id'))
            ->count();

        return $held / $cohort >= $limit;
    }
>>>>>>> REPLACE

### FILE: resources/behavior/alliance_lane.yaml
```yaml
# The alliance lane: how an AI-led alliance recruits, and how far one alliance may grow.
#
# Every number the leader's review pass uses lives here, so retuning recruitment — how long an
# application waits, how many the leader takes in one pass, how picky the alliance is by rank and
# by points-per-member, and how much of the AI cohort one alliance may hold — is a data edit.

review:
    # An application sits this long before the leader decides, so membership grows like a leader
    # thinking it over rather than a script draining a queue.
    minimum_application_age_minutes: 15

    # The leader takes at most this many per pass; the rest wait for the next one.
    maximum_accepts_per_pass: 1

    # The top share of the population a leader takes without another thought.
    strong_rank_ratio: 0.15

    # The bottom share that only a mass or still-founded alliance will take.
    newbie_rank_ratio: 0.5

    # Points per member at or above which the alliance reads as an elite core.
    elite_points_per_member: 1000000

    # Points per member at or below which the alliance reads as mass / still recruiting.
    mass_points_per_member: 10000

cohort:
    # ALLIANCE_SHARE: the most of the AI cohort one alliance may hold. Seeding left one alliance
    # with three quarters of the accounts; at this limit its leader stops recruiting and the rest
    # of the cohort is left for other alliances, so the review pass cannot rebuild that shape.
    alliance_share_limit: 0.75
```

### FILE: resources/scenarios/alliance-application-decided.json
```json
{
    "name": "alliance-application-decided",
    "situation": "No alliance application has been decided for a week. A qualified account has been waiting on an AI-led alliance that is nowhere near the cohort share limit, so the leader must decide it rather than leave the lane idle.",
    "persona": "An alliance leader running a normal, still-recruiting alliance.",
    "input": {
        "alliance": {
            "ai_members": 1,
            "ai_cohort": 8,
            "points_per_member": 5000
        },
        "application": {
            "age_minutes": 30,
            "general_rank_ratio": 0.1,
            "general_rank": 1
        }
    },
    "decision_key": "alliance_application_review",
    "status": "unverified",
    "expect": {
        "action": "Alliance",
        "reason_contains": "alliance"
    }
}
```

### FILE: resources/scenarios/alliance-recruitment-at-cohort-share-limit.json
```json
{
    "name": "alliance-recruitment-at-cohort-share-limit",
    "situation": "One alliance already holds three quarters of the AI cohort and a top-ranked account applies to it. The same situation as the idle lane, one number different: the alliance is at the ALLIANCE_SHARE limit, so the leader must not take the applicant and the application must be left for another alliance.",
    "persona": "A leader whose alliance already holds most of the cohort.",
    "input": {
        "alliance": {
            "ai_members": 15,
            "ai_cohort": 20,
            "points_per_member": 5000
        },
        "application": {
            "age_minutes": 30,
            "general_rank_ratio": 0.05,
            "general_rank": 1
        }
    },
    "decision_key": "alliance_application_review",
    "status": "unverified",
    "expect": {
        "action": "Alliance",
        "reason_contains": "cohort share"
    }
}
```

### FILE: tests/Feature/AllianceLaneRecruitmentTest.php
```php
<?php

use Modules\AI\Actions\ReviewAiAllianceApplicationsAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\SystemAiClock;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;
use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\AllianceMember;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\AllianceService;

uses(AiQueueModuleTestCase::class);

beforeEach(function (): void {
    app()->bind(AiClock::class, SystemAiClock::class);
});

function laneProfile(int $playerId): void
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Casual,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'enabled' => true,
    ]);
}

function laneRank(int $playerId, int $rank): void
{
    Highscore::unguarded(fn () => Highscore::updateOrCreate(
        ['player_id' => $playerId],
        ['general' => 1000, 'general_rank' => $rank, 'economy' => 1000, 'research' => 1000],
    ));
}

function laneAiAccount(int $rank): User
{
    $user = User::factory()->create();
    laneProfile($user->id);
    laneRank($user->id, $rank);

    return $user;
}

function laneAlliance(int $founderId, string $tag): Alliance
{
    return app(AllianceService::class)->createAlliance($founderId, $tag, 'Alliance ' . $tag);
}

function laneApply(User $applicant, Alliance $alliance): AllianceApplication
{
    return app(AllianceService::class)->applyToAlliance(
        $applicant->id,
        $alliance->id,
        'Active player looking for a home.',
    );
}

function laneAgeApplication(AllianceApplication $application, int $minutes): void
{
    AllianceApplication::unguarded(fn () => AllianceApplication::whereKey($application->id)->update([
        'created_at' => now()->subMinutes($minutes),
    ]));
}

/** An account the alliance admitted earlier, so the review pass sees a real cohort share. */
function laneAdmit(Alliance $alliance, int $rank): void
{
    $member = laneAiAccount($rank);
    $application = laneApply($member, $alliance);

    app(AllianceService::class)->acceptApplication($application->id, $alliance->founder_user_id);
}

test('a leader takes a qualified applicant while its alliance is below the cohort share limit', function (): void {
    laneProfile($this->currentUserId);
    $alliance = laneAlliance($this->currentUserId, 'LANE');

    $applicant = laneAiAccount(1);
    $application = laneApply($applicant, $alliance);
    laneAgeApplication($application, 30);

    $decided = app(ReviewAiAllianceApplicationsAction::class)->handle();

    expect($decided)->toBe(1)
        ->and(AllianceMember::query()
            ->where('alliance_id', $alliance->id)
            ->where('user_id', $applicant->id)
            ->exists())->toBeTrue()
        ->and(app(AllianceService::class)->getPendingApplications($alliance->id)->pluck('id')->all())
        ->not->toContain($application->id);
});

test('a leader at the cohort share limit does not take even the strongest applicant', function (): void {
    laneProfile($this->currentUserId);
    $alliance = laneAlliance($this->currentUserId, 'LANE');

    // Six admitted accounts put the alliance at three quarters of the eight AI profiles the
    // applicant brings the cohort to — the ALLIANCE_SHARE invariant — so the leader stops.
    foreach (range(1, 6) as $rank) {
        laneAdmit($alliance, $rank);
    }

    // Rank one against a population of seven is the strongest applicant a leader ever sees, so
    // the share limit has to beat the rank rule for this to stay declined.
    $applicant = laneAiAccount(1);
    $application = laneApply($applicant, $alliance);
    laneAgeApplication($application, 30);

    $decided = app(ReviewAiAllianceApplicationsAction::class)->handle();

    expect($decided)->toBe(0)
        ->and(AllianceMember::query()
            ->where('alliance_id', $alliance->id)
            ->where('user_id', $applicant->id)
            ->exists())->toBeFalse()
        ->and(app(AllianceService::class)->getPendingApplications($alliance->id)->pluck('id')->all())
        ->toContain($application->id);
});
```