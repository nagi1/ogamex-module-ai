<?php

use Modules\AI\Actions\AdvanceAiAllianceLifeAction;
use Modules\AI\Domain\Social\AllianceChoice;
use Modules\AI\Enums\AiActivityBand;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\AllianceHighscore;
use OGame\Models\AllianceMember;
use OGame\Models\Highscore;
use OGame\Models\User;
use Symfony\Component\Yaml\Yaml;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// DEF-002: the fit an account weighs before asking a club to take it in. The numbers live in
// resources/behavior/alliance_fit.yaml; the applicant's language (users.lang) and activity band
// (its profile) and the club's own (through its founder) are host state. A club that does not fit
// is never asked, so an account without a fitting club founds its own — which is what stops the
// cohort from converging on one club (invariant ALLIANCE_SHARE).

function allianceFitPolicy(): array
{
    return Yaml::parseFile(dirname(__DIR__, 2) . '/resources/behavior/alliance_fit.yaml');
}

function allianceFitRank(int $playerId, int $general, int $rank): void
{
    Highscore::unguarded(fn () => Highscore::updateOrCreate(
        ['player_id' => $playerId],
        ['general' => $general, 'general_rank' => $rank, 'economy' => $general, 'research' => $general],
    ));
}

function allianceFitSpeaks(int $playerId, string $language): void
{
    User::query()->whereKey($playerId)->update(['lang' => $language]);
}

function allianceFitProfile(int $playerId, AiActivityBand|null $band, AiArchetype $archetype = AiArchetype::Miner): void
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => $archetype,
        'skill_band' => AiSkillBand::Standard,
        'activity_band' => $band,
        'random_seed' => 42,
        'enabled' => true,
    ]);
}

function allianceFitMember(int $allianceId, int $userId): void
{
    AllianceMember::unguarded(fn () => AllianceMember::create([
        'alliance_id' => $allianceId,
        'user_id' => $userId,
        'rank_id' => null,
        'joined_at' => now(),
    ]));
}

/** An account the module drives, seated nowhere and unranked: the rest of a cohort a club does not hold. */
function allianceFitOtherAccounts(int $amount): void
{
    for ($account = 0; $account < $amount; $account++) {
        allianceFitProfile(User::factory()->create(['lang' => 'en'])->id, AiActivityBand::Regular, AiArchetype::Casual);
    }
}

/** An open club of $members members and $points standing, kept by a founder of $language and $band. */
function allianceFitClub(string $tag, string $language, AiActivityBand|null $band, int $members = 1, int $points = 0): Alliance
{
    $founder = User::factory()->create(['lang' => $language]);

    if ($band !== null) {
        allianceFitProfile($founder->id, $band, AiArchetype::Casual);
    }

    $club = Alliance::unguarded(fn (): Alliance => Alliance::create([
        'alliance_tag' => $tag,
        'alliance_name' => 'Alliance ' . $tag,
        'founder_user_id' => $founder->id,
        'is_open' => true,
        'external_text' => 'Active players welcome',
    ]));

    allianceFitMember($club->id, $founder->id);

    for ($member = 1; $member < $members; $member++) {
        allianceFitMember($club->id, User::factory()->create()->id);
    }

    AllianceHighscore::unguarded(fn () => AllianceHighscore::create([
        'alliance_id' => $club->id,
        'general' => $points,
        'economy' => $points,
        'research' => 0,
        'military' => 0,
    ]));

    return $club;
}

test('the fit rule is stated as data and holds no member ceiling', function (): void {
    $policy = allianceFitPolicy();

    expect($policy['fit']['language']['match'])->toBeGreaterThan(0.0)
        ->and($policy['fit']['band']['tolerance'])->toBeInt()
        ->and(array_key_exists('ceiling', $policy))->toBeFalse()
        ->and(json_encode($policy))->not->toContain('ceiling');
});

test('a club matching the account language and pace beats the bigger club that does not', function (): void {
    allianceFitProfile($this->currentUserId, AiActivityBand::Hardcore, AiArchetype::Fleeter);
    allianceFitSpeaks($this->currentUserId, 'de');
    allianceFitRank($this->currentUserId, 500, 2);
    allianceFitRank(User::factory()->create()->id, 1000, 1);
    allianceFitRank(User::factory()->create()->id, 100, 3);

    $big = allianceFitClub('BIG', 'en', AiActivityBand::Casual, 3, 40);
    $fits = allianceFitClub('FITS', 'de', AiActivityBand::Hardcore, 1, 40);

    $chosen = app(AllianceChoice::class)->choose($this->currentUserId);

    expect($chosen?->id)->toBe($fits->id)
        ->and($chosen?->id)->not->toBe($big->id);
});

test('a pace two bands away does not fit, one band away does', function (): void {
    allianceFitProfile($this->currentUserId, AiActivityBand::Regular);
    allianceFitSpeaks($this->currentUserId, 'en');
    allianceFitRank($this->currentUserId, 500, 2);
    allianceFitRank(User::factory()->create()->id, 1000, 1);

    allianceFitClub('FAR', 'en', AiActivityBand::Hardcore, 1, 40);
    expect(app(AllianceChoice::class)->choose($this->currentUserId))->toBeNull();

    $near = allianceFitClub('NEAR', 'en', AiActivityBand::Active, 1, 40);
    expect(app(AllianceChoice::class)->choose($this->currentUserId)?->id)->toBe($near->id);
});

test('an account that states no pace joins on language alone, and joins nothing in another language', function (): void {
    allianceFitProfile($this->currentUserId, null);
    allianceFitSpeaks($this->currentUserId, 'de');
    allianceFitRank($this->currentUserId, 500, 2);

    expect(app(AllianceChoice::class)->choose($this->currentUserId))->toBeNull();

    $club = allianceFitClub('SPRACH', 'de', null, 1, 40);
    expect(app(AllianceChoice::class)->choose($this->currentUserId)?->id)->toBe($club->id);
});

test('an account no club fits founds its own instead of piling into one', function (): void {
    allianceFitProfile($this->currentUserId, AiActivityBand::Hardcore, AiArchetype::Fleeter);
    allianceFitSpeaks($this->currentUserId, 'de');
    allianceFitRank($this->currentUserId, 500, 2);
    allianceFitRank(User::factory()->create()->id, 1000, 1);
    allianceFitClub('ENCL', 'en', AiActivityBand::Casual, 2, 40);

    expect(app(AdvanceAiAllianceLifeAction::class)->handle())->toBeGreaterThan(0)
        ->and(Alliance::query()->where('founder_user_id', $this->currentUserId)->exists())->toBeTrue()
        ->and(AllianceApplication::query()->where('user_id', $this->currentUserId)->count())->toBe(0);
});

test('a club that fits takes the application instead of the account founding a second', function (): void {
    allianceFitProfile($this->currentUserId, AiActivityBand::Hardcore, AiArchetype::Fleeter);
    allianceFitSpeaks($this->currentUserId, 'de');
    allianceFitRank($this->currentUserId, 500, 2);
    allianceFitClub('DEUT', 'de', AiActivityBand::Hardcore, 1, 40);

    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(Alliance::query()->count())->toBe(1)
        ->and(AllianceApplication::query()->where('user_id', $this->currentUserId)->exists())->toBeTrue();
});

test('a member whose club is two paces away leaves it, and one whose club fits stays', function (): void {
    allianceFitProfile($this->currentUserId, AiActivityBand::Regular);
    allianceFitSpeaks($this->currentUserId, 'en');
    allianceFitRank($this->currentUserId, 500, 2);
    $far = allianceFitClub('FAR', 'en', AiActivityBand::Hardcore, 1, 40);
    allianceFitMember($far->id, $this->currentUserId);
    User::query()->whereKey($this->currentUserId)->update(['alliance_id' => $far->id]);

    expect(app(AllianceChoice::class)->currentClubFits($this->currentUserId))->toBeFalse();

    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(User::query()->whereKey($this->currentUserId)->value('alliance_id'))->not->toBe($far->id);
});

test('a member of a club that fits does not leave', function (): void {
    allianceFitProfile($this->currentUserId, AiActivityBand::Regular);
    allianceFitSpeaks($this->currentUserId, 'en');
    allianceFitRank($this->currentUserId, 500, 2);
    $near = allianceFitClub('NEAR', 'en', AiActivityBand::Active, 1, 40);
    allianceFitMember($near->id, $this->currentUserId);
    User::query()->whereKey($this->currentUserId)->update(['alliance_id' => $near->id]);

    // The rest of the cohort plays elsewhere, so the club is a fit, not most of the neighbourhood.
    allianceFitOtherAccounts(3);

    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(User::query()->whereKey($this->currentUserId)->value('alliance_id'))->toBe($near->id);
});
