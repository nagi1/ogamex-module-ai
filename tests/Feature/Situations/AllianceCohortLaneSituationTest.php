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

// The live cohort read (ALLY-001): every account sits in a club. A club that fits its members is a
// club they keep, however many of the cohort it holds: no ceiling pushes a player out of it.

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

test('a settled cohort in a club that fits it stays seated, however large the club is', function (): void {
    $members = [$this->currentUserId, User::factory()->create()->id, User::factory()->create()->id, User::factory()->create()->id, User::factory()->create()->id];
    foreach ($members as $id) {
        cohortLaneProfile($id);
    }
    $club = cohortLaneClub($members[0], 'BIG');
    foreach (array_slice($members, 1) as $id) {
        cohortLaneSeat($club->id, $id);
    }

    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(User::query()->whereIn('id', $members)->where('alliance_id', $club->id)->count())->toBe(count($members))
        ->and(AllianceApplication::query()->whereIn('user_id', $members)->count())->toBe(0);
});
