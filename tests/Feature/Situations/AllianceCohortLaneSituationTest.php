<?php

use Modules\AI\Actions\AdvanceAiAllianceLifeAction;
use Modules\AI\Enums\AiActivityBand;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\AllianceMember;
use OGame\Models\Highscore;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// The live cohort read (ALLY-001): every account sits in a club and one club holds most of the
// cohort. The lane only moves if such a club is left and the freed account then applies somewhere.

function cohortLaneProfile(int $playerId): void
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Casual,
        'skill_band' => AiSkillBand::Standard,
        'activity_band' => AiActivityBand::Regular,
        'random_seed' => 42,
        'enabled' => true,
    ]);

    Highscore::unguarded(fn () => Highscore::updateOrCreate(
        ['player_id' => $playerId],
        ['general' => 1000, 'general_rank' => 2, 'economy' => 1000, 'research' => 1000],
    ));
}

function cohortLaneClub(int $founderId, string $tag): Alliance
{
    $club = Alliance::unguarded(fn (): Alliance => Alliance::create([
        'alliance_tag' => $tag,
        'alliance_name' => 'Alliance ' . $tag,
        'founder_user_id' => $founderId,
        'is_open' => true,
    ]));

    cohortLaneSeat($club->id, $founderId);

    return $club;
}

function cohortLaneSeat(int $allianceId, int $playerId): void
{
    AllianceMember::unguarded(fn () => AllianceMember::create([
        'alliance_id' => $allianceId,
        'user_id' => $playerId,
        'rank_id' => null,
        'joined_at' => now(),
    ]));
    User::query()->whereKey($playerId)->update(['alliance_id' => $allianceId, 'lang' => 'en']);
}

test('a settled cohort whose one club holds most of it still creates an application', function (): void {
    $members = [];
    for ($index = 0; $index < 20; $index++) {
        $members[] = User::factory()->create(['lang' => 'en'])->id;
    }
    foreach ($members as $id) {
        cohortLaneProfile($id);
    }

    $crowded = cohortLaneClub($members[0], 'CROWD');
    foreach (array_slice($members, 1, 14) as $id) {
        cohortLaneSeat($crowded->id, $id);
    }
    foreach (array_slice($members, 15, 5) as $index => $id) {
        cohortLaneClub($id, 'CLUB' . $index);
    }

    // The host forbids joining for a cooldown of days after leaving, so the lane moves over a real
    // week, not six passes in one instant: a freed account applies on a later day, once the host's
    // cooldown has run out, which is what the seven-day cohort read measures.
    for ($day = 0; $day < 6; $day++) {
        $day > 0 && $this->travel(1)->days();
        app(AdvanceAiAllianceLifeAction::class)->handle();
    }

    $applied = AllianceApplication::query()->whereIn('user_id', $members)->count();
    $left = User::query()->whereIn('id', $members)->whereNull('alliance_id')->count();
    $diag = User::query()->whereIn('id', $members)->whereNull('alliance_id')->get()->map(function (User $user): string {
        $choice = app(\Modules\AI\Domain\Social\AllianceChoice::class)->choose($user->id);

        return $user->id . ' left_at=' . ($user->alliance_left_at?->toDateTimeString() ?? 'null')
            . ' candidate=' . ($choice?->id ?? 'none')
            . ' action=' . json_encode(app(\Modules\AI\Actions\ApplyAiAllianceAction::class)->handle($user->id)->reason ?? null);
    })->implode('; ');

    expect($left)->toBeGreaterThan(0, 'no account ever left the crowded club')
        ->and($applied)->toBeGreaterThan(0, 'left count: ' . $left . ', diag: ' . $diag);
});
