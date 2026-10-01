<?php

use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\ReviewAiAllianceApplicationsAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\AllianceApplication;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// SIM-001's situation (scripts/cohort-scenario.php `alliance-application`): an application row (status
// pending) waits on an alliance an AI account leads. A leader reads it and decides; it does not
// sit pending past the age floor.
test('a pending application to an AI-led alliance is decided on the leader\'s next pass', function (): void {
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Casual,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 18_000 + $this->currentUserId,
        'enabled' => true,
    ]);
    $alliance = app(AllianceService::class)->createAlliance($this->currentUserId, 'SITU', 'Situation');
    $applicant = User::factory()->create();
    Highscore::unguarded(fn () => Highscore::updateOrCreate(
        ['player_id' => $applicant->id],
        ['general' => 1000, 'general_rank' => 1, 'economy' => 1000, 'research' => 1000],
    ));

    // The row the live situation plants: alliance_id, user_id, application_message, status 0.
    $id = DB::table('alliance_applications')->insertGetId([
        'alliance_id' => $alliance->id,
        'user_id' => $applicant->id,
        'application_message' => 'Active player looking for a home.',
        'status' => AllianceApplication::STATUS_PENDING,
        'created_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(30),
    ]);

    expect(app(ReviewAiAllianceApplicationsAction::class)->handle())->toBe(1)
        ->and(AllianceApplication::query()->whereKey($id)->value('status'))->not->toBe(AllianceApplication::STATUS_PENDING);
});
