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

// The live cohort read (ALLY-001): every account sits in a club, and the club one account joined at
// seeding is the club it would choose today, so nothing moves and the application lane creates
// nothing. The club that fits its members is kept while it holds less than the cohort's share; the
// club that holds the share is no longer the one its members would choose (invariant ALLIANCE_SHARE).

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

/** $amount more accounts the module drives, seated nowhere: the rest of a cohort a club does not hold. */
function cohortLaneInertProfiles(int $amount): void
{
    for ($account = 0; $account < $amount; $account++) {
        AiProfile::create([
            'player_id' => User::factory()->create(['lang' => 'en'])->id,
            'archetype' => AiArchetype::Casual,
            'skill_band' => AiSkillBand::Standard,
            'activity_band' => AiActivityBand::Regular,
            'random_seed' => 42,
            'enabled' => true,
        ]);
    }
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

    // The rest of the cohort plays elsewhere: the club is a strong club, not most of the neighbourhood.
    cohortLaneInertProfiles(15);

    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(User::query()->whereIn('id', $members)->where('alliance_id', $club->id)->count())->toBe(count($members))
        ->and(AllianceApplication::query()->whereIn('user_id', $members)->count())->toBe(0);
});

test('a club holding the cohort\'s share loses a member, who asks another club on the same pass', function (): void {
    $members = [$this->currentUserId, User::factory()->create()->id, User::factory()->create()->id, User::factory()->create()->id, User::factory()->create()->id];
    foreach ($members as $id) {
        cohortLaneProfile($id);
    }
    $club = cohortLaneClub($members[0], 'BIG');
    foreach (array_slice($members, 1) as $id) {
        cohortLaneSeat($club->id, $id);
    }

    // Every AI account sits in the one club: the share the cohort invariant forbids.
    $elsewhere = cohortLaneClub(User::factory()->create()->id, 'SMLL');

    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(User::query()->whereIn('id', $members)->where('alliance_id', $club->id)->count())->toBe(count($members) - 1)
        ->and(AllianceApplication::query()->whereIn('user_id', $members)->where('alliance_id', $elsewhere->id)
            ->where('status', AllianceApplication::STATUS_PENDING)->count())->toBe(1);
});
