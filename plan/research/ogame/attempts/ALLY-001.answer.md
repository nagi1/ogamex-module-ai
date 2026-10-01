### EDIT: app/Actions/ReviewAiAllianceApplicationsAction.php
<<<<<<< SEARCH
    public function __construct(private readonly AiClock $clock)
    {
    }
=======
    /** The behaviour file's recruitment ceiling, read once per run. */
    private array|null $recruitmentCeiling = null;

    private bool $recruitmentCeilingRead = false;

    public function __construct(private readonly AiClock $clock)
    {
    }
>>>>>>> REPLACE

<<<<<<< SEARCH
            if ($this->readsAsExploitative($alliance, (int) $application->user_id)) {
                $decided += $this->reject($alliance, $application);

                continue;
            }
=======
            if ($this->readsAsExploitative($alliance, (int) $application->user_id)) {
                $decided += $this->reject($alliance, $application);

                continue;
            }

            if ($this->atRecruitmentCeiling($alliance, (int) $application->user_id)) {
                $decided += $this->reject($alliance, $application);

                continue;
            }
>>>>>>> REPLACE

<<<<<<< SEARCH
    /**
     * A wary leader will not admit a player it models as likely to exploit the alliance, even
     * when the rank rule would take them. Only a recorded relationship can carry that read; a
     * stranger is decided on rank alone.
     */
    private function readsAsExploitative(Alliance $alliance, int $applicantId): bool
=======
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
>>>>>>> REPLACE

### FILE: resources/behavior/alliance-recruitment.yaml
```yaml
# The alliance lane's recruitment ceiling: how much of the AI cohort one alliance may hold.
#
# ALLY-001: one alliance holding most of the cohort is the state the invariant forbids. The
# leader's review refuses an ai-managed applicant whose acceptance would take the alliance to
# the share or above it. Below minimum_cohort a share means nothing, so no ceiling applies.
share_ceiling: 0.75
minimum_cohort: 2
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

// ALLY-001's ceiling: the review decides every application, but an ai-managed account is refused
// once its alliance would hold the cohort's share. Below that share the same applicant is taken.

beforeEach(function (): void {
    app()->bind(AiClock::class, SystemAiClock::class);
});

function ceilingAiProfile(int $playerId): void
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Casual,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'enabled' => true,
    ]);
}

function ceilingRank(int $playerId): void
{
    Highscore::unguarded(fn () => Highscore::updateOrCreate(
        ['player_id' => $playerId],
        ['general' => 1000, 'general_rank' => 1, 'economy' => 1000, 'research' => 1000],
    ));
}

function ceilingApplication(Alliance $alliance, int $applicantId): AllianceApplication
{
    $application = app(AllianceService::class)->applyToAlliance($applicantId, $alliance->id, 'Active player looking for a home.');
    AllianceApplication::unguarded(fn () => AllianceApplication::whereKey($application->id)->update([
        'created_at' => now()->subMinutes(30),
    ]));

    return $application;
}

test('an alliance below the cohort ceiling still takes an applicant', function (): void {
    ceilingAiProfile($this->currentUserId);
    $applicant = User::factory()->create();
    ceilingAiProfile($applicant->id);
    ceilingAiProfile(User::factory()->create()->id);
    ceilingAiProfile(User::factory()->create()->id);
    $alliance = app(AllianceService::class)->createAlliance($this->currentUserId, 'BELOW', 'Below the share');
    ceilingRank($applicant->id);
    $application = ceilingApplication($alliance, $applicant->id);

    expect(app(ReviewAiAllianceApplicationsAction::class)->handle())->toBe(1)
        ->and(AllianceApplication::query()->whereKey($application->id)->value('status'))->not->toBe(AllianceApplication::STATUS_PENDING)
        ->and(AllianceMember::query()->where('alliance_id', $alliance->id)->where('user_id', $applicant->id)->exists())->toBeTrue();
});

test('an alliance that would hold the whole cohort refuses at the ceiling', function (): void {
    ceilingAiProfile($this->currentUserId);
    $applicant = User::factory()->create();
    ceilingAiProfile($applicant->id);
    $alliance = app(AllianceService::class)->createAlliance($this->currentUserId, 'ATCAP', 'At the share');
    ceilingRank($applicant->id);
    $application = ceilingApplication($alliance, $applicant->id);

    expect(app(ReviewAiAllianceApplicationsAction::class)->handle())->toBe(1)
        ->and(AllianceApplication::query()->whereKey($application->id)->value('status'))->not->toBe(AllianceApplication::STATUS_PENDING)
        ->and(AllianceMember::query()->where('alliance_id', $alliance->id)->where('user_id', $applicant->id)->exists())->toBeFalse();
});

test('a human applicant is not part of the cohort share and is still taken', function (): void {
    ceilingAiProfile($this->currentUserId);
    $applicant = User::factory()->create();
    $alliance = app(AllianceService::class)->createAlliance($this->currentUserId, 'HUMAN', 'Human applicant');
    ceilingRank($applicant->id);
    $application = ceilingApplication($alliance, $applicant->id);

    expect(app(ReviewAiAllianceApplicationsAction::class)->handle())->toBe(1)
        ->and(AllianceMember::query()->where('alliance_id', $alliance->id)->where('user_id', $applicant->id)->exists())->toBeTrue();
});
```