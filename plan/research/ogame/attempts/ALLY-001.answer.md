### EDIT: app/Actions/ReviewAiAllianceApplicationsAction.php
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

            if ($this->atCohortCeiling($alliance)) {
                // The alliance already holds its share of the cohort: a leader takes nobody
                // else on, leaving a qualified applicant waiting rather than turning it down.
                continue;
            }

            if ($this->accept($alliance, $application)) {
>>>>>>> REPLACE
<<<<<<< SEARCH
    private function readsAsExploitative(Alliance $alliance, int $applicantId): bool
    {
        return app(PsychSimTheoryOfMind::class)->stanceToward((int) $alliance->founder_user_id, $applicantId) === AiToMStance::Defect;
    }
}
=======
    private function readsAsExploitative(Alliance $alliance, int $applicantId): bool
    {
        return app(PsychSimTheoryOfMind::class)->stanceToward((int) $alliance->founder_user_id, $applicantId) === AiToMStance::Defect;
    }

    /**
     * The share of the module's accounts one alliance may recruit before its leader closes the
     * doors: a cohort concentrated in one alliance starves every other lane of the game.
     */
    private function atCohortCeiling(Alliance $alliance): bool
    {
        return $this->cohortShare($alliance) >= $this->cohortShareCeiling();
    }

    /**
     * The share of the enabled cohort the leader has recruited. It counts the accounts the
     * leader took in, not the seat it sits in.
     */
    private function cohortShare(Alliance $alliance): float
    {
        $cohort = (int) AiProfile::query()->where('enabled', true)->count();

        if ($cohort === 0) {
            return 0.0;
        }

        return $this->recruitedAccounts($alliance) / $cohort;
    }

    private function recruitedAccounts(Alliance $alliance): int
    {
        $members = AllianceMember::query()->where('alliance_id', $alliance->id)->select('user_id');

        return (int) AiProfile::query()
            ->where('enabled', true)
            ->where('player_id', '!=', (int) $alliance->founder_user_id)
            ->whereIn('player_id', $members)
            ->count();
    }

    private function cohortShareCeiling(): float
    {
        // A host that ships no ceiling keeps the lane open: a share can never exceed one.
        return $this->rules()['cohort_share_ceiling'] ?? 1.0;
    }

    /**
     * The recruiting tunables, loaded by name so a host can retune the lane without a deploy.
     *
     * @return array<string, float>
     */
    private function rules(): array
    {
        $path = dirname(__DIR__, 2) . '/resources/behavior/alliance-recruitment.yaml';

        if (!is_file($path)) {
            return [];
        }

        $contents = (string) file_get_contents($path);
        $rules = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_pad(explode(':', $line, 2), 2, '');

            if (is_numeric(trim($value))) {
                $rules[trim($key)] = (float) trim($value);
            }
        }

        return $rules;
    }
}
>>>>>>> REPLACE

### FILE: resources/behavior/alliance-recruitment.yaml
```yaml
# Recruiting tunables an AI-led alliance reads by name when its leader decides applications.
# Keys are flat: one name, one number.

# The share of the module's accounts one alliance may recruit before its leader stops taking
# members on. Once an alliance holds this much of the cohort it grows no further, so no single
# alliance can absorb the accounts every other lane of the game needs.
cohort_share_ceiling: 0.6
```

### FILE: tests/Feature/AllianceRecruitmentCeilingTest.php
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

function laneRank(int $playerId): void
{
    Highscore::unguarded(fn () => Highscore::updateOrCreate(
        ['player_id' => $playerId],
        ['general' => 1000, 'general_rank' => 1, 'economy' => 1000, 'research' => 1000],
    ));
}

/**
 * The module's cohort: $size playing accounts, the first of which leads the alliance under test.
 *
 * @return array<int, int>
 */
function laneCohort(int $founderId, int $size): array
{
    laneProfile($founderId);
    laneRank($founderId);

    $recruits = [];

    while (count($recruits) < $size - 1) {
        $recruit = User::factory()->create();
        laneProfile($recruit->id);
        laneRank($recruit->id);
        $recruits[] = $recruit->id;
    }

    return $recruits;
}

function laneAlliance(int $founderId): Alliance
{
    return app(AllianceService::class)->createAlliance($founderId, 'LANE', 'Alliance LANE');
}

/** Takes an account in through the host's own apply/accept path. */
function laneJoin(Alliance $alliance, int $founderId, int $playerId): void
{
    $application = app(AllianceService::class)->applyToAlliance($playerId, $alliance->id, 'Looking for a home.');
    app(AllianceService::class)->acceptApplication($application->id, $founderId);
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

/** A player ranked at the very top of the population, so only the ceiling can hold it back. */
function laneApplicant(): User
{
    $applicant = User::factory()->create();
    laneRank($applicant->id);

    return $applicant;
}

test('a leader below the cohort ceiling takes a qualified applicant', function (): void {
    $cohort = laneCohort($this->currentUserId, 20);
    $alliance = laneAlliance($this->currentUserId);

    foreach (array_slice($cohort, 0, 11) as $recruitId) {
        laneJoin($alliance, $this->currentUserId, $recruitId);
    }

    $applicant = laneApplicant();
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
    $cohort = laneCohort($this->currentUserId, 20);
    $alliance = laneAlliance($this->currentUserId);

    foreach (array_slice($cohort, 0, 12) as $recruitId) {
        laneJoin($alliance, $this->currentUserId, $recruitId);
    }

    $applicant = laneApplicant();
    $application = laneApplication($applicant->id, $alliance->id);
    laneAgeApplication($application->id, 30);

    $decided = app(ReviewAiAllianceApplicationsAction::class)->handle();

    expect($decided)->toBe(0)
        ->and(AllianceMember::query()
            ->where('alliance_id', $alliance->id)
            ->where('user_id', $applicant->id)
            ->exists())->toBeFalse()
        ->and(app(AllianceService::class)->getPendingApplications($alliance->id)->pluck('id')->all())
        ->toContain($application->id);
});

test('a leader past the cohort ceiling leaves the strongest applicant pending', function (): void {
    $cohort = laneCohort($this->currentUserId, 20);
    $alliance = laneAlliance($this->currentUserId);

    foreach (array_slice($cohort, 0, 13) as $recruitId) {
        laneJoin($alliance, $this->currentUserId, $recruitId);
    }

    $applicant = laneApplicant();
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
    laneCohort($this->currentUserId, 20);
    laneAlliance($this->currentUserId);

    expect(app(ReviewAiAllianceApplicationsAction::class)->handle())->toBe(0);
});
```

### FILE: resources/scenarios/alliance-recruitment-at-ceiling.json
```json
{
    "name": "alliance-recruitment-at-ceiling",
    "persona": "an AI founder whose alliance already holds its allowed share of the module's accounts",
    "situation": "A qualified applicant waits on an AI-led alliance. The leader has recruited up to the ceiling, so the alliance grows no further even though the applicant would be taken on a quiet day.",
    "status": "unverified",
    "input": {
        "cohort_ai_accounts": 20,
        "alliance_recruited_ai_accounts": 12,
        "cohort_share_ceiling": 0.6,
        "applicant_rank": 1,
        "applicant_population": 21,
        "application_age_minutes": 30
    },
    "decision_key": "alliance.application.review",
    "expect": {
        "action": "Modules\\AI\\Actions\\ReviewAiAllianceApplicationsAction",
        "decision": "leave_pending",
        "applications_decided": 0,
        "members_added": 0
    }
}
```

### FILE: resources/scenarios/alliance-recruitment-below-ceiling.json
```json
{
    "name": "alliance-recruitment-below-ceiling",
    "persona": "an AI founder whose alliance holds less than its allowed share of the module's accounts",
    "situation": "A qualified applicant waits on an AI-led alliance that has room left before the ceiling, so the leader takes the account in.",
    "status": "unverified",
    "input": {
        "cohort_ai_accounts": 20,
        "alliance_recruited_ai_accounts": 11,
        "cohort_share_ceiling": 0.6,
        "applicant_rank": 1,
        "applicant_population": 21,
        "application_age_minutes": 30
    },
    "decision_key": "alliance.application.review",
    "expect": {
        "action": "Modules\\AI\\Actions\\ReviewAiAllianceApplicationsAction",
        "decision": "accept",
        "applications_decided": 1,
        "members_added": 1
    }
}
```