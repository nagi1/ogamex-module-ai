<?php

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiScoreSample;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\FixtureAiClock;
use OGame\Models\Highscore;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/FixtureAiClock.php';

uses(IsolatedAccountTestCase::class);

const SAMPLE_NOW = '2024-01-01 12:00:00';

beforeEach(function (): void {
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse(SAMPLE_NOW),
    ]));
});

// The host keeps current points and no history, so a sample is the account's public score as the
// host held it: the pass copies the host's numbers and restates no score rule of its own.
test('it records the host score row of every enabled account', function (): void {
    aiSampleProfile($this->currentUserId);
    aiSampleScore($this->currentUserId, general: 1_200, economy: 900, research: 250, militaryLost: 30, generalRank: 41);

    $this->artisan('ai:record-score-samples')
        ->expectsOutputToContain('Recorded 1 score samples for 2024-01-01 12:00:00')
        ->assertSuccessful();

    $sample = AiScoreSample::query()->sole();

    expect($sample->player_id)->toBe($this->currentUserId)
        ->and($sample->sampled_at->toDateTimeString())->toBe(SAMPLE_NOW)
        ->and($sample->general)->toBe(1_200)
        ->and($sample->economy)->toBe(900)
        ->and($sample->research)->toBe(250)
        ->and($sample->military_lost)->toBe(30)
        ->and($sample->general_rank)->toBe(41);
});

// One row per account per hour whatever cadence the operator runs the pass at: a rerun is a
// correction to the hour, never a second observation of it.
test('a second pass inside the same hour updates that hour instead of adding a row', function (): void {
    aiSampleProfile($this->currentUserId);
    aiSampleScore($this->currentUserId, general: 100);

    $this->artisan('ai:record-score-samples')->assertSuccessful();

    Highscore::query()->where('player_id', $this->currentUserId)->update(['general' => 250]);

    $this->artisan('ai:record-score-samples')->assertSuccessful();

    expect(AiScoreSample::query()->count())->toBe(1)
        ->and(AiScoreSample::query()->sole()->general)->toBe(250);
});

test('a pass in a later hour appends to the same account series', function (): void {
    aiSampleProfile($this->currentUserId);
    aiSampleScore($this->currentUserId, general: 100);

    $this->artisan('ai:record-score-samples')->assertSuccessful();

    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse(SAMPLE_NOW)->addHour(),
    ]));
    Highscore::query()->where('player_id', $this->currentUserId)->update(['general' => 150]);

    $this->artisan('ai:record-score-samples')->assertSuccessful();

    expect(AiScoreSample::query()->orderBy('sampled_at')->pluck('general')->all())->toBe([100, 150])
        ->and(AiScoreSample::query()->distinct()->count('sampled_at'))->toBe(2);
});

test('a disabled profile is never sampled', function (): void {
    aiSampleProfile($this->currentUserId, enabled: false);
    aiSampleScore($this->currentUserId, general: 100);

    $this->artisan('ai:record-score-samples')
        ->expectsOutputToContain('Recorded 0 score samples')
        ->assertSuccessful();

    expect(AiScoreSample::query()->count())->toBe(0);
});

// A young universe has no score row for an account until the host's own highscore pass writes one,
// so the skip is a reported state rather than a failure. The isolated account fixture already has a
// zeroed row from the host's tech observer, so it is removed to reach the genuinely empty state.
test('an enabled account with no host score row is skipped and counted', function (): void {
    aiSampleProfile($this->currentUserId);
    Highscore::query()->where('player_id', $this->currentUserId)->delete();

    $this->artisan('ai:record-score-samples')
        ->expectsOutputToContain('Recorded 0 score samples for 2024-01-01 12:00:00 (1 enabled accounts had no score row yet)')
        ->assertSuccessful();

    expect(AiScoreSample::query()->count())->toBe(0);
});

test('the switch stops the collection and says so', function (): void {
    config(['ai.review.enabled' => false]);
    aiSampleProfile($this->currentUserId);
    aiSampleScore($this->currentUserId, general: 100);

    $this->artisan('ai:record-score-samples')
        ->expectsOutputToContain('Score sampling is disabled')
        ->assertSuccessful();

    expect(AiScoreSample::query()->count())->toBe(0);
});

function aiSampleProfile(int $playerId, bool $enabled = true): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'enabled' => $enabled,
    ]);
}

function aiSampleScore(
    int $playerId,
    int $general,
    int $economy = 0,
    int $research = 0,
    int $militaryBuilt = 0,
    int $militaryDestroyed = 0,
    int $militaryLost = 0,
    int|null $generalRank = null,
): Highscore {
    return Highscore::updateOrCreate(
        ['player_id' => $playerId],
        [
            'general' => $general,
            'economy' => $economy,
            'research' => $research,
            'military_built' => $militaryBuilt,
            'military_destroyed' => $militaryDestroyed,
            'military_lost' => $militaryLost,
            'general_rank' => $generalRank,
        ],
    );
}
