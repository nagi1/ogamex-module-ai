<?php

use Modules\AI\Actions\AdvanceAiAllianceLifeAction;
use Modules\AI\Actions\ReviewAiAllianceApplicationsAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\SystemAiClock;
use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\AllianceHighscore;
use OGame\Models\ChatMessage;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(AiClock::class, SystemAiClock::class);
});

function reviewProfile(int $playerId): void
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Casual,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'enabled' => true,
    ]);
}

function reviewRank(int $playerId, int $rank): void
{
    Highscore::unguarded(fn () => Highscore::updateOrCreate(
        ['player_id' => $playerId],
        ['general' => 1000, 'general_rank' => $rank, 'economy' => 1000, 'research' => 1000],
    ));
}

function reviewAlliance(int $founderId, string $tag = 'TEST'): Alliance
{
    return app(AllianceService::class)->createAlliance($founderId, $tag, 'Alliance ' . $tag);
}

function reviewApplication(int $applicantId, int $allianceId): AllianceApplication
{
    return app(AllianceService::class)->applyToAlliance($applicantId, $allianceId, 'Active player looking for a home.');
}

function ageReviewApplication(int $applicationId, int $minutes): void
{
    AllianceApplication::unguarded(fn () => AllianceApplication::whereKey($applicationId)->update([
        'created_at' => now()->subMinutes($minutes),
    ]));
}

test('a fit applicant is accepted and welcomed after the age floor', function (): void {
    reviewProfile($this->currentUserId);
    $alliance = reviewAlliance($this->currentUserId);
    $applicant = User::factory()->create();
    reviewRank($applicant->id, 1);

    $application = reviewApplication($applicant->id, $alliance->id);
    ageReviewApplication($application->id, 20);

    expect(app(ReviewAiAllianceApplicationsAction::class)->handle())->toBe(1)
        ->and($application->fresh()->status)->toBe(AllianceApplication::STATUS_ACCEPTED)
        ->and(User::find($applicant->id)->alliance_id)->toBe($alliance->id)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->where('recipient_id', $applicant->id)->exists())->toBeTrue();
});

test('a zero-rank applicant is rejected without waiting for the age floor', function (): void {
    reviewProfile($this->currentUserId);
    $alliance = reviewAlliance($this->currentUserId);
    $applicant = User::factory()->create();

    $application = reviewApplication($applicant->id, $alliance->id);

    expect(app(ReviewAiAllianceApplicationsAction::class)->handle())->toBe(1)
        ->and($application->fresh()->status)->toBe(AllianceApplication::STATUS_REJECTED);
});

test('the leader accepts at most one applicant per pass', function (): void {
    reviewProfile($this->currentUserId);
    $alliance = reviewAlliance($this->currentUserId);

    $first = User::factory()->create();
    reviewRank($first->id, 1);
    $second = User::factory()->create();
    reviewRank($second->id, 2);

    $firstApplication = reviewApplication($first->id, $alliance->id);
    ageReviewApplication($firstApplication->id, 30);
    $secondApplication = reviewApplication($second->id, $alliance->id);
    ageReviewApplication($secondApplication->id, 20);

    expect(app(ReviewAiAllianceApplicationsAction::class)->handle())->toBe(1)
        ->and($firstApplication->fresh()->status)->toBe(AllianceApplication::STATUS_ACCEPTED)
        ->and($secondApplication->fresh()->status)->toBe(AllianceApplication::STATUS_PENDING);
});

test('an application younger than the floor stays pending', function (): void {
    reviewProfile($this->currentUserId);
    $alliance = reviewAlliance($this->currentUserId);
    $applicant = User::factory()->create();
    reviewRank($applicant->id, 1);

    $application = reviewApplication($applicant->id, $alliance->id);

    expect(app(ReviewAiAllianceApplicationsAction::class)->handle())->toBe(0)
        ->and($application->fresh()->status)->toBe(AllianceApplication::STATUS_PENDING);
});

test('an elite alliance rejects a bottom-half applicant', function (): void {
    reviewProfile($this->currentUserId);
    $alliance = reviewAlliance($this->currentUserId);
    AllianceHighscore::unguarded(fn () => AllianceHighscore::create([
        'alliance_id' => $alliance->id,
        'general' => 2_000_000,
        'economy' => 0,
        'research' => 0,
        'military' => 0,
    ]));

    $applicant = User::factory()->create();
    reviewRank($applicant->id, 1);

    $application = reviewApplication($applicant->id, $alliance->id);
    ageReviewApplication($application->id, 20);

    expect(app(ReviewAiAllianceApplicationsAction::class)->handle())->toBe(1)
        ->and($application->fresh()->status)->toBe(AllianceApplication::STATUS_REJECTED);
});

test('a ranked account every alliance rejected founds its own alliance', function (): void {
    reviewProfile($this->currentUserId);
    $alliance = reviewAlliance($this->currentUserId, 'ORBITAL');

    $outcast = User::factory()->create();
    reviewProfile($outcast->id);
    reviewRank($outcast->id, 15);

    $application = reviewApplication($outcast->id, $alliance->id);
    AllianceApplication::unguarded(fn () => $application->update(['status' => AllianceApplication::STATUS_REJECTED]));

    expect(app(AdvanceAiAllianceLifeAction::class)->handle())->toBeGreaterThan(0)
        ->and(Alliance::query()->where('founder_user_id', $outcast->id)->exists())->toBeTrue()
        ->and(User::find($outcast->id)->alliance_id)->not->toBeNull();
});

test('a rankless account does not found its own alliance', function (): void {
    reviewProfile($this->currentUserId);
    $alliance = reviewAlliance($this->currentUserId, 'ORBITAL');

    $farm = User::factory()->create();
    reviewProfile($farm->id);

    $application = reviewApplication($farm->id, $alliance->id);
    AllianceApplication::unguarded(fn () => $application->update(['status' => AllianceApplication::STATUS_REJECTED]));

    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(Alliance::query()->where('founder_user_id', $farm->id)->exists())->toBeFalse();
});

test('a locked-out account applies to the new club on the next pass instead of founding a third', function (): void {
    reviewProfile($this->currentUserId);
    $alliance = reviewAlliance($this->currentUserId, 'ORBITAL');

    $first = User::factory()->create();
    reviewProfile($first->id);
    reviewRank($first->id, 15);
    $second = User::factory()->create();
    reviewProfile($second->id);
    reviewRank($second->id, 16);

    foreach ([$first, $second] as $outcast) {
        $application = reviewApplication($outcast->id, $alliance->id);
        AllianceApplication::unguarded(fn () => $application->update(['status' => AllianceApplication::STATUS_REJECTED]));
    }

    // First pass: exactly one of the two founds (one club per pass).
    app(AdvanceAiAllianceLifeAction::class)->handle();
    $firstClub = Alliance::query()->where('founder_user_id', $first->id)->first();
    expect($firstClub)->not->toBeNull()
        ->and(Alliance::query()->where('founder_user_id', $second->id)->exists())->toBeFalse();

    // Second pass: the other applies to the new club, never founding a third.
    app(AdvanceAiAllianceLifeAction::class)->handle();
    expect(Alliance::query()->where('founder_user_id', $second->id)->exists())->toBeFalse()
        ->and(AllianceApplication::query()->where('user_id', $second->id)->where('alliance_id', $firstClub->id)->where('status', AllianceApplication::STATUS_PENDING)->exists())->toBeTrue();
});
