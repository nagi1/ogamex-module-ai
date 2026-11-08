<?php

use Illuminate\Support\Facades\Http;
use Modules\AI\Actions\AdvanceAiAllianceLifeAction;
use Modules\AI\Actions\ReviewAiAllianceApplicationsAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Infrastructure\Cognition\PsychSimClient;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\DriverCircuitBreaker;
use Modules\AI\Support\SystemAiClock;
use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\AllianceHighscore;
use OGame\Models\ChatMessage;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/VoicedLines.php';

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(AiClock::class, SystemAiClock::class);
});

/** Seed 7 draws a casual who wants a club, 13 one who leads (alliance-ambition.yaml). */
function reviewProfile(int $playerId, int $seed = 7): void
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Casual,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => $seed,
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

function enablePsychSim(): void
{
    config(['ai.cognition.mode' => 'external']);
    config(['ai.cognition.driver' => AiCognitionDriver::PsychSim->value]);
    app()->when(PsychSimClient::class)
        ->needs(DriverCircuitBreaker::class)
        ->give(fn (): DriverCircuitBreaker => app()->makeWith(DriverCircuitBreaker::class, [
            'driver' => AiCognitionDriver::PsychSim->value,
        ]));
}

test('a leader refuses an applicant the theory-of-mind read models as exploitative', function (): void {
    reviewProfile($this->currentUserId);
    $alliance = reviewAlliance($this->currentUserId);
    $applicant = User::factory()->create();
    reviewRank($applicant->id, 1);

    AiRelationship::unguarded(fn () => AiRelationship::create([
        'player_id' => $this->currentUserId,
        'other_player_id' => $applicant->id,
        'threat' => '0.7500',
        'revision' => 0,
    ]));

    enablePsychSim();
    Http::fake(['*/evaluate' => Http::response(['decision' => 'defect'])]);

    $application = reviewApplication($applicant->id, $alliance->id);
    ageReviewApplication($application->id, 20);

    expect(app(ReviewAiAllianceApplicationsAction::class)->handle())->toBe(1)
        ->and($application->fresh()->status)->toBe(AllianceApplication::STATUS_REJECTED);
});

test('a cooperate read admits the same ranked applicant', function (): void {
    reviewProfile($this->currentUserId);
    $alliance = reviewAlliance($this->currentUserId);
    $applicant = User::factory()->create();
    reviewRank($applicant->id, 1);

    AiRelationship::unguarded(fn () => AiRelationship::create([
        'player_id' => $this->currentUserId,
        'other_player_id' => $applicant->id,
        'threat' => '0.7500',
        'revision' => 0,
    ]));

    enablePsychSim();
    Http::fake(['*/evaluate' => Http::response(['decision' => 'cooperate'])]);

    $application = reviewApplication($applicant->id, $alliance->id);
    ageReviewApplication($application->id, 20);

    expect(app(ReviewAiAllianceApplicationsAction::class)->handle())->toBe(1)
        ->and($application->fresh()->status)->toBe(AllianceApplication::STATUS_ACCEPTED);
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

// Past a million points per member every alliance read as elite, and a top-15% cut in a six-empire universe admitted no one (9372).
test('an elite alliance admits a peer as strong as its members', function (): void {
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
    Highscore::unguarded(fn () => Highscore::updateOrCreate(
        ['player_id' => $applicant->id],
        ['general' => 1_500_000, 'general_rank' => 0, 'economy' => 1000, 'research' => 1000],
    ));
    foreach (range(1, 4) as $stronger) {
        Highscore::unguarded(fn () => Highscore::updateOrCreate(
            ['player_id' => User::factory()->create()->id],
            ['general' => 5_000_000, 'general_rank' => 0, 'economy' => 1000, 'research' => 1000],
        ));
    }

    $application = reviewApplication($applicant->id, $alliance->id);
    ageReviewApplication($application->id, 20);

    expect(app(ReviewAiAllianceApplicationsAction::class)->handle())->toBe(1)
        ->and($application->fresh()->status)->toBe(AllianceApplication::STATUS_ACCEPTED);
});

test('a ranked leader every alliance rejected founds its own alliance', function (): void {
    reviewProfile($this->currentUserId);
    $alliance = reviewAlliance($this->currentUserId, 'ORBITAL');

    $outcast = User::factory()->create();
    reviewProfile($outcast->id, 13);
    reviewRank($outcast->id, 15);

    $application = reviewApplication($outcast->id, $alliance->id);
    AllianceApplication::unguarded(fn () => $application->update(['status' => AllianceApplication::STATUS_REJECTED]));

    expect(app(AdvanceAiAllianceLifeAction::class)->handle())->toBeGreaterThan(0)
        ->and(Alliance::query()->where('founder_user_id', $outcast->id)->exists())->toBeTrue()
        ->and(User::find($outcast->id)->alliance_id)->not->toBeNull();
});

// Every locked-out account founding its own left six empires in six clubs (9372): a follower waits and asks again.
test('a rejected follower founds nothing', function (): void {
    reviewProfile($this->currentUserId);
    $alliance = reviewAlliance($this->currentUserId, 'ORBITAL');

    $outcast = User::factory()->create();
    reviewProfile($outcast->id);
    reviewRank($outcast->id, 15);

    $application = reviewApplication($outcast->id, $alliance->id);
    AllianceApplication::unguarded(fn () => $application->update(['status' => AllianceApplication::STATUS_REJECTED]));
    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(Alliance::query()->where('founder_user_id', $outcast->id)->exists())->toBeFalse();
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
    reviewProfile($first->id, 13);
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

// Applicants race for a seat: the stronger one gets it even when the weaker asked first (alliance-politics.md).
test('the leader seats the strongest applicant waiting, not the first', function (): void {
    reviewProfile($this->currentUserId);
    $alliance = reviewAlliance($this->currentUserId);
    $weaker = User::factory()->create();
    reviewRank($weaker->id, 2);
    $stronger = User::factory()->create();
    Highscore::unguarded(fn () => Highscore::updateOrCreate(['player_id' => $stronger->id], ['general' => 9000, 'general_rank' => 1, 'economy' => 9000, 'research' => 9000]));

    $first = reviewApplication($weaker->id, $alliance->id);
    ageReviewApplication($first->id, 30);
    $second = reviewApplication($stronger->id, $alliance->id);
    ageReviewApplication($second->id, 20);

    app(ReviewAiAllianceApplicationsAction::class)->handle();

    expect($second->fresh()->status)->toBe(AllianceApplication::STATUS_ACCEPTED)
        ->and($first->fresh()->status)->toBe(AllianceApplication::STATUS_PENDING);
});

// Every club sent the same sentence to every newcomer, and a judge read both alliances as automated (9406). The welcome is
// one of the leader's own lines, in its persona's voice first, with the club's tag.
test('a seated applicant gets a welcome in the leader\'s voice', function (): void {
    reviewProfile($this->currentUserId);
    $alliance = reviewAlliance($this->currentUserId, 'WLCM');
    $applicant = User::factory()->create();
    reviewRank($applicant->id, 1);
    ageReviewApplication(reviewApplication($applicant->id, $alliance->id)->id, 30);

    app(ReviewAiAllianceApplicationsAction::class)->handle();

    $welcome = (string) ChatMessage::query()->where('sender_id', $this->currentUserId)->where('recipient_id', $applicant->id)->value('message');
    expect(str_replace('WLCM', '{tag}', $welcome))->toBeIn(voicedLines('casual', 'alliance_welcome'));
});

// The leader answers by rank, and a quarter hour of raids on the applicant can have made the club one it walks out of:
// 9427 seated three accounts at 00:15 to 00:25 and all three had left by 00:30.
test('an AI applicant the club has since fought is not seated', function (bool $fought, int $status): void {
    reviewProfile($this->currentUserId);
    $alliance = reviewAlliance($this->currentUserId, 'FOES');
    $applicant = User::factory()->create();
    reviewProfile($applicant->id);
    reviewRank($applicant->id, 1);
    // The rest of the cohort sits outside the club, so seating one more is not the share ceiling's refusal.
    foreach (User::factory()->count(3)->create() as $outsider) {
        reviewProfile($outsider->id);
    }
    $application = reviewApplication($applicant->id, $alliance->id);
    ageReviewApplication($application->id, 30);
    if ($fought) {
        AiRelationship::unguarded(fn () => AiRelationship::create([
            'player_id' => $applicant->id, 'other_player_id' => $this->currentUserId, 'threat' => '0.9000', 'trust' => '-0.5000', 'revision' => 0,
        ]));
    }

    app(ReviewAiAllianceApplicationsAction::class)->handle();

    expect($application->fresh()->status)->toBe($status);
})->with([
    'the club hit the applicant after it applied' => [true, AllianceApplication::STATUS_REJECTED],
    'no fight: the usual seat' => [false, AllianceApplication::STATUS_ACCEPTED],
]);
