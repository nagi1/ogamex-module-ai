<?php

use Modules\AI\Actions\AdvanceAiAllianceLifeAction;
use Modules\AI\Enums\AiActivityBand;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// ALLY-001: a half-written seat is a users.alliance_id the host holds no alliance_members row for.
// leaveAlliance refuses to remove it ("Member not found in alliance"), and the join half skips the
// account as already engaged, which is how the application lane the scorecard's alliance aspect
// measures stayed at zero. The pass releases the pointer, and the freed account asks a fitting club
// on the same pass instead of waiting out the host's join cooldown.

function danglingRank(int $playerId): void
{
    Highscore::unguarded(fn () => Highscore::updateOrCreate(
        ['player_id' => $playerId],
        ['general' => 500, 'general_rank' => 2, 'economy' => 500, 'research' => 500],
    ));
}

test('an account whose seat the host half wrote asks a fitting club on the same pass', function (): void {
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'activity_band' => AiActivityBand::Regular,
        'random_seed' => 42,
        'enabled' => true,
    ]);
    danglingRank($this->currentUserId);

    // The club the pointer names: the host holds no alliance_members row for the account, and the
    // club has stopped taking applications, so it is not the club the account would choose today.
    $seat = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'HALF', 'Half written');
    $seat->is_open = false;
    $seat->save();
    // The club that fits the account and is open for applications.
    $fits = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'FULL', 'Full seat');
    $fits->is_open = true;
    $fits->save();

    User::query()->whereKey($this->currentUserId)->update(['alliance_id' => $seat->id]);

    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(User::query()->whereKey($this->currentUserId)->value('alliance_id'))->toBeNull()
        ->and(AllianceApplication::query()->where('user_id', $this->currentUserId)->where('alliance_id', $fits->id)->value('status'))
        ->toBe(AllianceApplication::STATUS_PENDING);
});
