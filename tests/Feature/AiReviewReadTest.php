<?php

use Carbon\CarbonImmutable;
use Modules\AI\Console\Commands\ReportAiPilot;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\FixtureAiClock;
use OGame\Models\Highscore;
use OGame\Models\User;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/FixtureAiClock.php';

uses(IsolatedAccountTestCase::class);

const REVIEW_NOW = '2024-01-01 14:00:00';

beforeEach(function (): void {
    aiReviewClock(REVIEW_NOW);
});

/**
 * The read a review parses: one window, fixed field names, and the growth the host keeps no history
 * of. The figures are asserted exactly, because a review that cannot state last window's numbers
 * cannot compare this one against it.
 */
test('the JSON window carries the score movement the host cannot', function (): void {
    $peerId = aiReviewSecondPlayer();
    aiReviewProfile($this->currentUserId);
    aiReviewProfile($peerId);

    // Three hours, two accounts: one grows and then loses a fleet, the other does not move at all.
    $sampleHour = function (int $hoursAgo, int $general, int $militaryLost, int $peerGeneral) use ($peerId): void {
        aiReviewClock(CarbonImmutable::parse(REVIEW_NOW)->subHours($hoursAgo));
        aiReviewScore($this->currentUserId, $general, $militaryLost);
        aiReviewScore($peerId, $peerGeneral);
        $this->artisan('ai:record-score-samples')->assertSuccessful();
    };

    $sampleHour(2, 100, 0, 100);
    $sampleHour(1, 250, 0, 100);
    $sampleHour(0, 400, 40, 100);

    $payload = aiReviewJson(['--days' => 1, '--json' => true]);

    expect(array_keys($payload))->toBe([
        'days', 'profiles', 'work', 'actions', 'lateness', 'language', 'score', 'feedback', 'read_cost',
    ])->and($payload['score'])->toBe([
        'enabled' => true,
        'accounts' => 2,
        'samples' => 6,
        'general_delta' => ['min' => 0, 'median' => 0, 'max' => 300],
        'largest_hourly_jump' => 150,
        'zero_growth_accounts' => 1,
        'military_lost' => 40,
    ])->and($payload['read_cost']['queries'])->toBeGreaterThan(0)
        ->and($payload['read_cost']['milliseconds'])->toBeGreaterThanOrEqual(0.0);
});

test('the human rendering tells an empty window apart from a switched-off collection', function (): void {
    aiReviewProfile($this->currentUserId);

    // Nothing sampled yet is one finding, and no growth after sampling is another.
    $this->artisan('ai:pilot-report', ['--days' => 1])
        ->expectsOutputToContain('score: no samples in this window')
        ->expectsOutputToContain('read cost:')
        ->assertSuccessful();

    aiReviewScore($this->currentUserId, general: 100);
    $this->artisan('ai:record-score-samples')->assertSuccessful();

    $this->artisan('ai:pilot-report', ['--days' => 1])
        ->expectsOutputToContain('score: 1 accounts · 1 samples · general delta min 0 · median 0 · max 0 · largest hour +0 · no growth 1 · military lost 0')
        ->assertSuccessful();

    config(['ai.review.enabled' => false]);

    $this->artisan('ai:pilot-report', ['--days' => 1])
        ->expectsOutputToContain('score: not collected (ai.review.enabled is false)')
        ->assertSuccessful();
});

/**
 * The command's own output, captured through an explicit buffered run: a review reads the JSON
 * stdout shape a script would read, not a rendering of it.
 *
 * @param array<string, mixed> $parameters
 * @return array<string, mixed>
 */
function aiReviewJson(array $parameters): array
{
    $command = app(ReportAiPilot::class);
    $command->setLaravel(app());

    $output = new BufferedOutput();
    $exitCode = $command->run(app()->makeWith(ArrayInput::class, ['parameters' => $parameters]), $output);

    expect($exitCode)->toBe(0);

    return json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);
}

function aiReviewClock(string $now): void
{
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse($now),
    ]));
}

function aiReviewProfile(int $playerId, bool $enabled = true): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 7,
        'enabled' => $enabled,
    ]);
}

function aiReviewScore(int $playerId, int $general, int $militaryLost = 0): Highscore
{
    return Highscore::query()->updateOrCreate(
        ['player_id' => $playerId],
        ['general' => $general, 'military_lost' => $militaryLost],
    );
}

function aiReviewSecondPlayer(): int
{
    return User::withoutEvents(fn (): User => User::factory()->create())->id;
}
