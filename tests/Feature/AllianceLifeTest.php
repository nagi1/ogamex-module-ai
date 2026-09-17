<?php

use Modules\AI\Actions\AdvanceAiAllianceLifeAction;
use Modules\AI\Domain\Social\AllianceChoice;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
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
        'random_seed' => 42,
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
