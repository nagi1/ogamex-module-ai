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

// ALLY-001: the cohort's membership stalled at seeding, so the lane the scorecard's alliance aspect
// measures created no application for a week. A users.alliance_id written without an alliance_members
// row is a membership the host's leaveAlliance refuses to remove ("Member not found in alliance"),
// which left every account engaged forever. The pass frees such a misfit, and the apply half then
// puts its application in the host's table.

function staleRank(int $playerId, int $general, int $rank): void
{
    Highscore::unguarded(fn () => Highscore::updateOrCreate(
        ['player_id' => $playerId],
        ['general' => $general, 'general_rank' => $rank, 'economy' => $general, 'research' => $general],
    ));
}

function staleCohort(int $clubId, string $language): int
{
    $playerId = User::factory()->create(['lang' => $language])->id;
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'activity_band' => AiActivityBand::Regular,
        'random_seed' => 42,
        'enabled' => true,
    ]);
    staleRank($playerId, 500, 2);
    User::query()->whereKey($playerId)->update(['alliance_id' => $clubId]);

    return $playerId;
}

/** An open club kept by a non-AI founder who states no pace, so it fits whoever asks. */
function staleClub(string $tag, string $language): Alliance
{
    $founder = User::factory()->create(['lang' => $language]);
    $club = Alliance::unguarded(fn (): Alliance => Alliance::create([
        'alliance_tag' => $tag,
        'alliance_name' => 'Alliance ' . $tag,
        'founder_user_id' => $founder->id,
        'is_open' => true,
        'external_text' => 'Active players welcome',
    ]));
    AllianceMember::unguarded(fn () => AllianceMember::create([
        'alliance_id' => $club->id,
        'user_id' => $founder->id,
        'rank_id' => null,
        'joined_at' => now(),
    ]));

    return $club;
}

/** An account the module drives, seated nowhere and unranked: the rest of a cohort a club does not hold. */
function staleInertProfiles(int $amount): void
{
    for ($account = 0; $account < $amount; $account++) {
        AiProfile::create([
            'player_id' => User::factory()->create(['lang' => 'en'])->id,
            'archetype' => AiArchetype::Miner,
            'skill_band' => AiSkillBand::Standard,
            'activity_band' => AiActivityBand::Regular,
            'random_seed' => 42,
            'enabled' => true,
        ]);
    }
}

test('a member of a club that fits is kept, and applies nowhere', function (): void {
    $small = staleClub('SMLL', 'en');
    $fits = staleClub('FITS', 'en');

    $cohort = [$this->currentUserId];
    User::query()->whereKey($this->currentUserId)->update(['alliance_id' => $small->id]);
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'activity_band' => AiActivityBand::Regular,
        'random_seed' => 42,
        'enabled' => true,
    ]);
    staleRank($this->currentUserId, 500, 2);

    // One more account in the club: a club of two keeps its members.
    $cohort[] = staleCohort($small->id, 'en');

    // The rest of the cohort plays elsewhere, so the club holds no share of it.
    staleInertProfiles(3);

    app(AdvanceAiAllianceLifeAction::class)->handle();

    // Both are in a club whose founder is a stranger; their link is left alone, so no application appears.
    expect(User::query()->whereIn('id', $cohort)->whereNull('alliance_id')->count())->toBe(0)
        ->and(AllianceApplication::query()->whereIn('user_id', $cohort)->where('alliance_id', $fits->id)->count())->toBe(0);
});
