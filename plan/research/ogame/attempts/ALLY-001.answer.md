### EDIT: app/Actions/ReviewAiAllianceApplicationsAction.php
<<<<<<< SEARCH
use OGame\Models\Highscore;
use OGame\Services\AllianceService;
=======
use OGame\Models\Highscore;
use OGame\Services\AllianceService;
use Symfony\Component\Yaml\Yaml;
>>>>>>> REPLACE

<<<<<<< SEARCH
 * deciding, not a script.
 */
class ReviewAiAllianceApplicationsAction
=======
 * deciding, not a script.
 *
 * A leader also watches how much of the AI population its tag already holds: at the cohort
 * ceiling it leaves qualified applicants pending rather than concentrating the cohort further.
 */
class ReviewAiAllianceApplicationsAction
>>>>>>> REPLACE

<<<<<<< SEARCH
            if ($this->readsAsExploitative($alliance, (int) $application->user_id)) {
                $decided += $this->reject($alliance, $application);

                continue;
            }

            if ($this->accept($alliance, $application)) {
=======
            if ($this->readsAsExploitative($alliance, (int) $application->user_id)) {
                $decided += $this->reject($alliance, $application);

                continue;
            }

            // A tag already at the cohort ceiling defers rather than absorbing another account;
            // the application stays pending so a later pass can still take it.
            if ($this->wouldReachCohortCeiling($alliance)) {
                continue;
            }

            if ($this->accept($alliance, $application)) {
>>>>>>> REPLACE

<<<<<<< SEARCH
    private function pointsPerMember(Alliance $alliance): float
    {
        $members = max(1, (int) AllianceMember::query()->where('alliance_id', $alliance->id)->count());
        $points = (int) (AllianceHighscore::query()->where('alliance_id', $alliance->id)->value('general') ?? 0);

        return $points / $members;
    }
=======
    private function pointsPerMember(Alliance $alliance): float
    {
        $members = max(1, (int) AllianceMember::query()->where('alliance_id', $alliance->id)->count());
        $points = (int) (AllianceHighscore::query()->where('alliance_id', $alliance->id)->value('general') ?? 0);

        return $points / $members;
    }

    /** Would admitting one more account put this tag at or past the cohort ceiling? */
    private function wouldReachCohortCeiling(Alliance $alliance): bool
    {
        $population = AiProfile::query()->where('enabled', true)->count();

        if ($population <= 0) {
            return false;
        }

        $members = (int) AllianceMember::query()->where('alliance_id', $alliance->id)->count();

        return (($members + 1) / $population) >= $this->cohortShareCeiling();
    }

    private function cohortShareCeiling(): float
    {
        $rules = Yaml::parseFile(module_path('AI', 'resources/behavior/alliance-lane.yaml'));

        return (float) ($rules['cohort_share_ceiling'] ?? 0.0);
    }
>>>>>>> REPLACE

### FILE: resources/behavior/alliance-lane.yaml
```yaml
# The alliance lane: how the AI cohort is spread over alliances.
#
# cohort_share_ceiling: the share of the enabled AI accounts one alliance may hold. A leader
# whose tag would reach this share with one more member leaves the application pending instead
# of admitting it, so no single tag absorbs the cohort (invariant ALLIANCE_SHARE).
cohort_share_ceiling: 0.75
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
use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\AllianceMember;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

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

/** Adds AI accounts to the cohort beyond the account under test. */
function laneCohort(int $extraAccounts): void
{
    for ($i = 0; $i < $extraAccounts; $i++) {
        laneProfile(User::factory()->create()->id);
    }
}

function laneRank(int $playerId, int $rank): void
{
    Highscore::unguarded(fn () => Highscore::updateOrCreate(
        ['player_id' => $playerId],
        ['general' => 1000, 'general_rank' => $rank, 'economy' => 1000, 'research' => 1000],
    ));
}

function laneAlliance(int $founderId, string $tag = 'LANE'): Alliance
{
    return app(AllianceService::class)->createAlliance($founderId, $tag, 'Alliance ' . $tag);
}

function laneApplication(int $applicantId, int $allianceId): AllianceApplication
{
    return app(AllianceService::class)->applyToAlliance($applicantId, $allianceId, 'Active player looking for a home.');
}

function laneAgeApplication(int $applicationId, int $minutes): void
{
    AllianceApplication::unguarded(fn () => AllianceApplication::whereKey($applicationId)->update([
        'created_at' => now()->subMinutes($minutes),
    ]));
}

function laneTakeMember(int $allianceId, int $founderId, int $playerId): void
{
    $application = laneApplication($playerId, $allianceId);
    app(AllianceService::class)->acceptApplication($application->id, $founderId);
}

test('a leader below the cohort ceiling takes a qualified applicant', function (): void {
    laneProfile($this->currentUserId);
    laneCohort(3);

    $alliance = laneAlliance($this->currentUserId);
    $applicant = User::factory()->create();
    laneRank($applicant->id, 1);

    $application = laneApplication($applicant->id, $alliance->id);
    laneAgeApplication($application->id, 30);

    $decided = app(ReviewAiAllianceApplicationsAction::class)->handle();

    expect($decided)->toBe(1)
        ->and(AllianceMember::query()
            ->where('alliance_id', $alliance->id)
            ->where('user_id', $applicant->id)
            ->exists())->toBeTrue();
});

test('a leader at the cohort ceiling leaves a qualified applicant pending', function (): void {
    laneProfile($this->currentUserId);
    laneCohort(3);

    $alliance = laneAlliance($this->currentUserId);
    $member = User::factory()->create();
    laneTakeMember($alliance->id, $this->currentUserId, $member->id);

    $applicant = User::factory()->create();
    laneRank($applicant->id, 1);

    $application = laneApplication($applicant->id, $alliance->id);
    laneAgeApplication($application->id, 30);

    $decided = app(ReviewAiAllianceApplicationsAction::class)->handle();

    expect($decided)->toBe(0)
        ->and(AllianceMember::query()
            ->where('alliance_id', $alliance->id)
            ->where('user_id', $applicant->id)
            ->exists())->toBeFalse()
        ->and(app(AllianceService::class)->getPendingApplications($alliance->id)->count())->toBe(1);
});

test('a leader past the cohort ceiling leaves the strongest applicant pending', function (): void {
    laneProfile($this->currentUserId);
    laneCohort(1);

    $alliance = laneAlliance($this->currentUserId);
    $applicant = User::factory()->create();
    laneRank($applicant->id, 1);

    $application = laneApplication($applicant->id, $alliance->id);
    laneAgeApplication($application->id, 30);

    $decided = app(ReviewAiAllianceApplicationsAction::class)->handle();

    expect($decided)->toBe(0)
        ->and(AllianceMember::query()
            ->where('alliance_id', $alliance->id)
            ->where('user_id', $applicant->id)
            ->exists())->toBeFalse();
});

test('a leader with nothing waiting decides nothing', function (): void {
    laneProfile($this->currentUserId);
    laneAlliance($this->currentUserId);

    expect(app(ReviewAiAllianceApplicationsAction::class)->handle())->toBe(0);
});
```

### FILE: resources/scenarios/alliance-lane-below-ceiling.json
```json
{
    "name": "alliance-lane-below-ceiling",
    "persona": "an AI veteran leading a tag that holds one of the four cohort accounts and is still recruiting",
    "situation": "Four enabled AI accounts share the cohort, the tag holds one of them, and a rank-1 applicant has waited thirty minutes.",
    "status": "unverified",
    "input": {
        "ai_cohort_accounts": 4,
        "tag_members": 1,
        "applicant_general_rank": 1,
        "application_age_minutes": 30
    },
    "decision_key": "alliance.application.review",
    "expect": {
        "action": "Modules\\AI\\Actions\\ReviewAiAllianceApplicationsAction",
        "decision": "accept",
        "decisions": 1
    },
    "checklist": [
        {
            "topic": "cohort-size",
            "question": "How many enabled AI accounts the cohort holds when the leader decides.",
            "status": "unverified"
        },
        {
            "topic": "tag-share",
            "question": "How much of the cohort the tag already holds and whether one more member stays under the ceiling.",
            "status": "unverified"
        },
        {
            "topic": "applicant-rank",
            "question": "Where the applicant sits in the general highscore at decision time.",
            "status": "unverified"
        },
        {
            "topic": "application-age",
            "question": "Whether the application has waited the leader's minimum age.",
            "status": "unverified"
        },
        {
            "topic": "welcome",
            "question": "Whether an accepted applicant receives the leader's welcome message.",
            "status": "unverified"
        }
    ]
}
```

### FILE: resources/scenarios/alliance-lane-at-ceiling.json
```json
{
    "name": "alliance-lane-at-ceiling",
    "persona": "an AI veteran leading a tag that already holds half of the cohort and would reach the ceiling by admitting one more",
    "situation": "Four enabled AI accounts share the cohort, the tag holds two of them, and a rank-1 applicant has waited thirty minutes.",
    "status": "unverified",
    "input": {
        "ai_cohort_accounts": 4,
        "tag_members": 2,
        "applicant_general_rank": 1,
        "application_age_minutes": 30
    },
    "decision_key": "alliance.application.review",
    "expect": {
        "action": "Modules\\AI\\Actions\\ReviewAiAllianceApplicationsAction",
        "decision": "defer",
        "decisions": 0
    },
    "checklist": [
        {
            "topic": "cohort-size",
            "question": "How many enabled AI accounts the cohort holds when the leader decides.",
            "status": "unverified"
        },
        {
            "topic": "ceiling-value",
            "question": "The cohort share ceiling the leader reads from resources/behavior/alliance-lane.yaml.",
            "status": "unverified"
        },
        {
            "topic": "pending-fate",
            "question": "Whether a deferred application stays pending or is declined.",
            "status": "unverified"
        },
        {
            "topic": "share-recovery",
            "question": "What lets the leader recruit again once the cohort grows or another tag takes members.",
            "status": "unverified"
        }
    ]
}
```