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
