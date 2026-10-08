<?php

use Illuminate\Support\Facades\Http;
use Modules\AI\Actions\AdvanceAiAllianceLifeAction;
use Modules\AI\Actions\ApplyAiAllianceAction;
use Modules\AI\Domain\Social\AllianceChoice;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Enums\AiQueueActionReason;
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
use OGame\Models\AllianceMember;
use OGame\Models\Highscore;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

function enableProfile(int $playerId): void
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Casual,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 8,
        'enabled' => true,
    ]);
}

function rank(int $playerId, int $general, int $rank): void
{
    Highscore::unguarded(fn () => Highscore::updateOrCreate(
        ['player_id' => $playerId],
        ['general' => $general, 'general_rank' => $rank, 'economy' => $general, 'research' => $general],
    ));
}

function openAlliance(int $founderUserId, string $tag, string $text = ''): Alliance
{
    return Alliance::unguarded(fn (): Alliance => Alliance::create([
        'alliance_tag' => $tag,
        'alliance_name' => 'Alliance ' . $tag,
        'founder_user_id' => $founderUserId,
        'is_open' => true,
        'external_text' => $text,
    ]));
}

test('an account with no alliance applies through the host path', function (): void {
    enableProfile($this->currentUserId);
    rank($this->currentUserId, 1000, 1);

    $founder = User::factory()->create();
    $alliance = openAlliance($founder->id, 'TEST', 'Active players welcome');

    expect(app(AdvanceAiAllianceLifeAction::class)->handle())->toBe(1)
        ->and(AllianceApplication::where('user_id', $this->currentUserId)->where('alliance_id', $alliance->id)->exists())->toBeTrue()
        ->and(AllianceApplication::where('user_id', $this->currentUserId)->first()->application_message)->not->toBeEmpty();
});

test('a universe with no alliance has its first leader found one', function (): void {
    enableProfile($this->currentUserId);
    // Seed 13 draws a casual who leads (alliance-ambition.yaml).
    AiProfile::query()->where('player_id', $this->currentUserId)->update(['random_seed' => 13]);
    rank($this->currentUserId, 1000, 1);

    expect(app(AdvanceAiAllianceLifeAction::class)->handle())->toBe(1)
        ->and(Alliance::query()->count())->toBe(1)
        ->and(Alliance::query()->sole()->founder_user_id)->toBe($this->currentUserId)
        ->and(User::query()->find($this->currentUserId)->alliance_id)->not->toBeNull();
});

// With no drawn leader the first club still forms: the joiner whose kind most readily leads founds it. A turtle, whose
// kind never leads (alliance-ambition.yaml), founds nothing even when it would join (9402, 9405: no alliance in 12 h).
test('a universe with no drawn leader has its most ambitious joiner found the first club', function (AiArchetype $archetype, bool $founds): void {
    enableProfile($this->currentUserId);
    // Seed 8 draws a joiner that does not lead, for both kinds.
    AiProfile::query()->where('player_id', $this->currentUserId)->update(['random_seed' => 8, 'archetype' => $archetype]);
    rank($this->currentUserId, 1000, 1);

    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(Alliance::query()->where('founder_user_id', $this->currentUserId)->exists())->toBe($founds);
})->with([
    'a casual joiner' => [AiArchetype::Casual, true],
    'a turtle joiner' => [AiArchetype::Turtle, false],
]);

// Past the first club, joiners no club fits and no drawn leader stayed solo all day (9414-9417). Two of them get a club
// from the kind that most readily leads; one alone founds nothing, so six empires never end in six clubs (9372).
test('joiners no club will take get a second club only when there are two of them', function (bool $second, bool $founds): void {
    enableProfile($this->currentUserId);
    rank($this->currentUserId, 1000, 1);
    $shut = openAlliance(User::factory()->create()->id, 'SHUT');
    $shut->update(['is_open' => false]);
    if ($second) {
        $other = User::factory()->create();
        AiProfile::create(['player_id' => $other->id, 'archetype' => AiArchetype::Turtle, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 8, 'enabled' => true]);
        rank($other->id, 900, 2);
    }

    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(Alliance::query()->where('founder_user_id', $this->currentUserId)->exists())->toBe($founds);
})->with([
    'a casual and a turtle: the casual founds' => [true, true],
    'a casual alone waits' => [false, false],
]);

test('an account already in an alliance is left alone', function (): void {
    enableProfile($this->currentUserId);
    rank($this->currentUserId, 1000, 1);

    $founder = User::factory()->create();
    $alliance = openAlliance($founder->id, 'TEST', 'Active players welcome');
    User::whereKey($this->currentUserId)->update(['alliance_id' => $alliance->id]);

    expect(app(AdvanceAiAllianceLifeAction::class)->handle())->toBe(0)
        ->and(AllianceApplication::query()->count())->toBe(0);
});

test('a pending application is never duplicated', function (): void {
    enableProfile($this->currentUserId);
    rank($this->currentUserId, 1000, 1);

    $founder = User::factory()->create();
    $alliance = openAlliance($founder->id, 'TEST', 'Active players welcome');

    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(app(AdvanceAiAllianceLifeAction::class)->handle())->toBe(0)
        ->and(AllianceApplication::where('user_id', $this->currentUserId)->count())->toBe(1);
});

test('a newbie account chooses the mass alliance over the elite core', function (): void {
    $founder = User::factory()->create();

    $mass = openAlliance($founder->id, 'MASS', 'Everyone welcome');
    foreach ([$founder->id, User::factory()->create()->id, User::factory()->create()->id] as $memberId) {
        AllianceMember::unguarded(fn () => AllianceMember::create([
            'alliance_id' => $mass->id,
            'user_id' => $memberId,
            'rank_id' => null,
            'joined_at' => now(),
        ]));
    }
    AllianceHighscore::unguarded(fn () => AllianceHighscore::create([
        'alliance_id' => $mass->id,
        'general' => 40,
        'economy' => 20,
        'research' => 20,
        'military' => 0,
    ]));

    $eliteFounder = User::factory()->create();
    $elite = openAlliance($eliteFounder->id, 'ELITE', 'Strong fleeters only');
    AllianceMember::unguarded(fn () => AllianceMember::create([
        'alliance_id' => $elite->id,
        'user_id' => $eliteFounder->id,
        'rank_id' => null,
        'joined_at' => now(),
    ]));
    AllianceHighscore::unguarded(fn () => AllianceHighscore::create([
        'alliance_id' => $elite->id,
        'general' => 9_000_000,
        'economy' => 4_000_000,
        'research' => 5_000_000,
        'military' => 0,
    ]));

    // The account sits in the bottom half of the population, so it should pick the mass
    // catch-all, not the high points-per-member core.
    rank($this->currentUserId, 10, 2);
    rank($founder->id, 5, 1);
    rank($eliteFounder->id, 1_000_000, 3);

    expect(app(AllianceChoice::class)->choose($this->currentUserId)->id)->toBe($mass->id);
});

test('a strong account chooses the high points-per-member core over the mass', function (): void {
    $massFounder = User::factory()->create();
    $mass = openAlliance($massFounder->id, 'MASS', 'Everyone welcome');
    foreach ([$massFounder->id, User::factory()->create()->id, User::factory()->create()->id] as $memberId) {
        AllianceMember::unguarded(fn () => AllianceMember::create([
            'alliance_id' => $mass->id,
            'user_id' => $memberId,
            'rank_id' => null,
            'joined_at' => now(),
        ]));
    }
    AllianceHighscore::unguarded(fn () => AllianceHighscore::create([
        'alliance_id' => $mass->id,
        'general' => 40,
        'economy' => 20,
        'research' => 20,
        'military' => 0,
    ]));

    $eliteFounder = User::factory()->create();
    $elite = openAlliance($eliteFounder->id, 'ELITE', 'Strong fleeters only');
    AllianceMember::unguarded(fn () => AllianceMember::create([
        'alliance_id' => $elite->id,
        'user_id' => $eliteFounder->id,
        'rank_id' => null,
        'joined_at' => now(),
    ]));
    AllianceHighscore::unguarded(fn () => AllianceHighscore::create([
        'alliance_id' => $elite->id,
        'general' => 9_000_000,
        'economy' => 4_000_000,
        'research' => 5_000_000,
        'military' => 0,
    ]));

    // The account is rank 1 of 8 — inside the top 15% — so it wants quality over size.
    rank($this->currentUserId, 1_000_000, 1);
    foreach (range(2, 8) as $rank) {
        rank(User::factory()->create()->id, 100 * $rank, $rank);
    }

    expect(app(AllianceChoice::class)->choose($this->currentUserId)->id)->toBe($elite->id);
});

test('a mid account balances size and quality', function (): void {
    $founder = User::factory()->create();
    $alliance = openAlliance($founder->id, 'NORM', 'Active players welcome');
    AllianceMember::unguarded(fn () => AllianceMember::create([
        'alliance_id' => $alliance->id,
        'user_id' => $founder->id,
        'rank_id' => null,
        'joined_at' => now(),
    ]));
    AllianceHighscore::unguarded(fn () => AllianceHighscore::create([
        'alliance_id' => $alliance->id,
        'general' => 50_000,
        'economy' => 25_000,
        'research' => 25_000,
        'military' => 0,
    ]));

    // Rank 2 of 5 sits between the strong and newbie bands, so the account takes the normal one.
    rank($this->currentUserId, 500, 2);
    rank($founder->id, 1_000, 1);
    foreach (range(3, 5) as $rank) {
        rank(User::factory()->create()->id, 10 * $rank, $rank);
    }

    expect(app(AllianceChoice::class)->choose($this->currentUserId)->id)->toBe($alliance->id);
});

test('an account with no rank applies nowhere', function (): void {
    enableProfile($this->currentUserId);
    Highscore::query()->where('player_id', $this->currentUserId)->update(['general_rank' => null]);

    $founder = User::factory()->create();
    openAlliance($founder->id, 'TEST', 'Active players welcome');

    $result = app(ApplyAiAllianceAction::class)->handle($this->currentUserId);

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe(AiQueueActionReason::NoSuitableAlliance->value);
});

test('the application message matches the persona archetype', function (): void {
    enableProfile($this->currentUserId);
    rank($this->currentUserId, 1000, 1);

    $founder = User::factory()->create();
    openAlliance($founder->id, 'TEST', 'Active players welcome');

    $expected = [
        [AiArchetype::Miner, 'miner'],
        [AiArchetype::Fleeter, 'fleeter'],
        [AiArchetype::Turtle, 'defensive'],
        [AiArchetype::Trader, 'trader'],
        [AiArchetype::Casual, 'casual'],
    ];

    foreach ($expected as [$archetype, $needle]) {
        AiProfile::where('player_id', $this->currentUserId)->update(['archetype' => $archetype]);
        AllianceApplication::where('user_id', $this->currentUserId)->delete();

        expect(app(ApplyAiAllianceAction::class)->handle($this->currentUserId)->successful)->toBeTrue()
            ->and(strtolower(AllianceApplication::where('user_id', $this->currentUserId)->first()->application_message))->toContain($needle);
    }
});

test('an account without a profile sends the generic message', function (): void {
    rank($this->currentUserId, 1000, 1);

    $founder = User::factory()->create();
    openAlliance($founder->id, 'TEST', 'Active players welcome');

    expect(app(ApplyAiAllianceAction::class)->handle($this->currentUserId)->successful)->toBeTrue()
        ->and(AllianceApplication::where('user_id', $this->currentUserId)->first()->application_message)->toBe('Looking for an active alliance.');
});

test('the advance command reports the accounts it applied', function (): void {
    $this->artisan('ai:advance-alliance-life')->assertSuccessful();
});

test('a wary account skips an alliance whose founder it reads as exploitative', function (): void {
    config(['ai.cognition.mode' => 'external']);
    config(['ai.cognition.driver' => AiCognitionDriver::PsychSim->value]);
    app()->bind(AiClock::class, SystemAiClock::class);
    app()->when(PsychSimClient::class)
        ->needs(DriverCircuitBreaker::class)
        ->give(fn (): DriverCircuitBreaker => app()->makeWith(DriverCircuitBreaker::class, [
            'driver' => AiCognitionDriver::PsychSim->value,
        ]));
    Http::fake(['*/evaluate' => Http::response(['decision' => 'defect'])]);

    $hostileFounder = User::factory()->create();
    $hostile = openAlliance($hostileFounder->id, 'HOSTIL', 'Active players welcome');
    $safeFounder = User::factory()->create();
    $safe = openAlliance($safeFounder->id, 'SAFE', 'Active players welcome');

    // Both alliances score equally, so the first one created would win on ties; the
    // theory-of-mind read is the only reason the account lands on SAFE.
    rank($this->currentUserId, 1000, 1);
    rank($hostileFounder->id, 500, 2);
    rank($safeFounder->id, 100, 3);

    AiRelationship::unguarded(fn () => AiRelationship::create([
        'player_id' => $this->currentUserId,
        'other_player_id' => $hostileFounder->id,
        'threat' => '0.7500',
        'revision' => 0,
    ]));

    expect(app(AllianceChoice::class)->choose($this->currentUserId)->id)->toBe($safe->id);
});

test('a cooperate read keeps the alliance in the running', function (): void {
    config(['ai.cognition.mode' => 'external']);
    config(['ai.cognition.driver' => AiCognitionDriver::PsychSim->value]);
    app()->bind(AiClock::class, SystemAiClock::class);
    app()->when(PsychSimClient::class)
        ->needs(DriverCircuitBreaker::class)
        ->give(fn (): DriverCircuitBreaker => app()->makeWith(DriverCircuitBreaker::class, [
            'driver' => AiCognitionDriver::PsychSim->value,
        ]));
    Http::fake(['*/evaluate' => Http::response(['decision' => 'cooperate'])]);

    $founder = User::factory()->create();
    $alliance = openAlliance($founder->id, 'TEST', 'Active players welcome');
    rank($this->currentUserId, 1000, 1);
    rank($founder->id, 100, 2);

    AiRelationship::unguarded(fn () => AiRelationship::create([
        'player_id' => $this->currentUserId,
        'other_player_id' => $founder->id,
        'threat' => '0.7500',
        'revision' => 0,
    ]));

    expect(app(AllianceChoice::class)->choose($this->currentUserId)->id)->toBe($alliance->id);
});

// Real servers have players in no alliance at all; the ALLY-001 lane seated every account (alliance-politics.md).
test('a solo account applies nowhere and founds nothing', function (): void {
    enableProfile($this->currentUserId);
    // Seed 1 draws a casual who wants no club (alliance-ambition.yaml).
    AiProfile::query()->where('player_id', $this->currentUserId)->update(['random_seed' => 1]);
    rank($this->currentUserId, 1000, 1);
    openAlliance(User::factory()->create()->id, 'TEST', 'Active players welcome');

    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect(AllianceApplication::query()->where('user_id', $this->currentUserId)->exists())->toBeFalse()
        ->and(Alliance::query()->where('founder_user_id', $this->currentUserId)->exists())->toBeFalse();
});

test('a rejected applicant asks the same club again once the answer has stood a day', function (): void {
    enableProfile($this->currentUserId);
    rank($this->currentUserId, 1000, 1);
    $alliance = openAlliance(User::factory()->create()->id, 'TEST', 'Active players welcome');
    AllianceApplication::unguarded(fn () => AllianceApplication::create([
        'alliance_id' => $alliance->id, 'user_id' => $this->currentUserId, 'application_message' => 'x', 'status' => AllianceApplication::STATUS_REJECTED,
    ]));

    app(AdvanceAiAllianceLifeAction::class)->handle();
    $waiting = AllianceApplication::query()->where('user_id', $this->currentUserId)->where('status', AllianceApplication::STATUS_PENDING)->exists();
    $this->travel(25)->hours();
    app(AdvanceAiAllianceLifeAction::class)->handle();

    expect($waiting)->toBeFalse()
        ->and(AllianceApplication::query()->where('user_id', $this->currentUserId)->where('status', AllianceApplication::STATUS_PENDING)->exists())->toBeTrue();
});
