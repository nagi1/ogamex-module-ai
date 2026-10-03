<?php

use Modules\AI\Actions\AdvanceAiAllianceLifeAction;
use Modules\AI\Enums\AiActivityBand;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\AllianceHighscore;
use OGame\Models\AllianceMember;
use OGame\Models\Highscore;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// ALLY-001: the lane has to keep moving on a cohort that has settled — every member in a club that
// fits its language, pace and rank tier, so no seat stops fitting, the leave half frees nobody and
// the application lane creates nothing. One seat does stop fitting without the club changing: the
// member beside a club-mate the module has recorded as an enemy, which is the club a player walks
// out of. The line lives in resources/behavior/alliance_fit.yaml; the threat recorded for an attack
// on the account itself sits on it, and the softer shared-enemy read a bystander forms stays under it.

function enmityProfile(int $playerId): void
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'activity_band' => AiActivityBand::Regular,
        'random_seed' => 42,
        'enabled' => true,
    ]);
}

function enmityRank(int $playerId, int $general, int $rank): void
{
    Highscore::unguarded(fn () => Highscore::updateOrCreate(
        ['player_id' => $playerId],
        ['general' => $general, 'general_rank' => $rank, 'economy' => $general, 'research' => $general],
    ));
}

function enmitySeat(int $allianceId, int $userId): void
{
    AllianceMember::unguarded(fn () => AllianceMember::create([
        'alliance_id' => $allianceId,
        'user_id' => $userId,
        'rank_id' => null,
        'joined_at' => now(),
    ]));
    User::query()->whereKey($userId)->update(['alliance_id' => $allianceId, 'lang' => 'en']);
}

function enmityClub(string $tag, int $founderId, int $members = 1): Alliance
{
    $club = Alliance::unguarded(fn (): Alliance => Alliance::create([
        'alliance_tag' => $tag,
        'alliance_name' => 'Alliance ' . $tag,
        'founder_user_id' => $founderId,
        'is_open' => true,
        'external_text' => 'Active players welcome',
    ]));

    enmitySeat($club->id, $founderId);

    for ($member = 1; $member < $members; $member++) {
        enmitySeat($club->id, User::factory()->create(['lang' => 'en'])->id);
    }

    AllianceHighscore::unguarded(fn () => AllianceHighscore::create([
        'alliance_id' => $club->id,
        'general' => 40,
        'economy' => 20,
        'research' => 20,
        'military' => 0,
    ]));

    return $club;
}

/** The threat the account holds toward one counterparty, as the battle observer records it. */
function enmityThreat(int $playerId, int $otherPlayerId, float $threat): void
{
    AiRelationship::unguarded(fn () => AiRelationship::create([
        'player_id' => $playerId,
        'other_player_id' => $otherPlayerId,
        'threat' => $threat,
        'revision' => 0,
    ]));
}

/** The rest of the cohort, seated nowhere: a club that holds them is not most of the neighbourhood. */
function enmityBystanders(int $amount): void
{
    for ($account = 0; $account < $amount; $account++) {
        enmityProfile(User::factory()->create(['lang' => 'en'])->id);
    }
}

/**
 * The account seated beside the club-mate, with an open club of its own language and pace waiting:
 * the seat and the club it would rather be in.
 *
 * @return array{0: Alliance, 1: Alliance}
 */
function enmitySituation(int $playerId, float|null $threat): array
{
    enmityProfile($playerId);
    enmityRank($playerId, 500, 2);
    enmityRank(User::factory()->create(['lang' => 'en'])->id, 1_000, 1);
    enmityBystanders(4);

    $home = enmityClub('HOME', User::factory()->create(['lang' => 'en'])->id);
    $fits = enmityClub('FITS', User::factory()->create(['lang' => 'en'])->id);

    $clubmate = User::factory()->create(['lang' => 'en'])->id;
    enmityProfile($clubmate);
    enmitySeat($home->id, $playerId);
    enmitySeat($home->id, $clubmate);

    if ($threat !== null) {
        enmityThreat($playerId, $clubmate, $threat);
    }

    return [$home, $fits];
}

test('a member beside a club-mate at the enmity line leaves and asks the club that fits', function (): void {
    [, $fits] = enmitySituation($this->currentUserId, 0.25);

    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(User::query()->whereKey($this->currentUserId)->value('alliance_id'))->toBeNull()
        ->and(AllianceApplication::query()->where('user_id', $this->currentUserId)
            ->where('alliance_id', $fits->id)->value('status'))->toBe(AllianceApplication::STATUS_PENDING);
});

test('a member beside a club-mate past the line leaves just the same', function (): void {
    $home = enmitySituation($this->currentUserId, 0.30)[0];

    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(User::query()->whereKey($this->currentUserId)->value('alliance_id'))
        ->not->toBe($home->id);
});

test('a member beside a club-mate under the line keeps the seat', function (): void {
    $home = enmitySituation($this->currentUserId, 0.24)[0];

    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(User::query()->whereKey($this->currentUserId)->value('alliance_id'))->toBe($home->id)
        ->and(AllianceApplication::query()->where('user_id', $this->currentUserId)->count())->toBe(0);
});

test('a member with no recorded enemy keeps the seat', function (): void {
    $home = enmitySituation($this->currentUserId, null)[0];

    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(User::query()->whereKey($this->currentUserId)->value('alliance_id'))->toBe($home->id)
        ->and(AllianceApplication::query()->where('user_id', $this->currentUserId)->count())->toBe(0);
});
