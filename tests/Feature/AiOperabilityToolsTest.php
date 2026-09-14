<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\BuildAiPilotReportAction;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiLanguageRequestState;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiUsageReservationState;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiLanguageRequest;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiUsageReservation;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;
use Modules\AI\Tests\Support\FixtureAiClock;
use OGame\Models\User;

require_once __DIR__ . '/../Support/AiQueueModuleTestCase.php';
require_once __DIR__ . '/../Support/FixtureAiClock.php';

uses(AiQueueModuleTestCase::class);

// The module clock is pinned to the time the host test case travels to, so a window that ends
// "now" contains the fixtures this test writes.
const PILOT_NOW = '2024-01-01 00:00:00';

beforeEach(function (): void {
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse(PILOT_NOW),
    ]));
});

function pilotWorkItem(string $suffix, AiWorkState $state, int $lateMinutes, int $attempts = 1): AiWorkItem
{
    return AiWorkItem::create([
        'player_id' => 987_001,
        'kind' => AiWorkKind::RunSession,
        'due_at' => CarbonImmutable::parse(PILOT_NOW)->subMinutes($lateMinutes),
        'idempotency_key' => 'pilot:' . $suffix,
        'state' => $state,
        'attempts' => $attempts,
    ]);
}

function pilotReceipt(string $suffix, AiReceiptState $state): AiActionReceipt
{
    return AiActionReceipt::create([
        'player_id' => 987_001,
        'idempotency_key' => 'pilot-receipt:' . $suffix,
        'action_type' => AiActionType::QueueBuilding,
        'state' => $state,
    ]);
}

function pilotLanguageRequest(int $tokens): void
{
    $reservation = AiUsageReservation::query()->create([
        'universe_scope' => 'default',
        'player_id' => 987_001,
        'conversation_key' => '987001:987002',
        'request_key' => 'pilot-reservation',
        'reserved_for' => '2024-01-01',
        'reserved_input_tokens' => 2_000,
        'reserved_output_tokens' => 320,
        'actual_input_tokens' => $tokens,
        'actual_output_tokens' => 0,
        'state' => AiUsageReservationState::Settled,
    ]);

    AiLanguageRequest::query()->create([
        'conversation_reply_id' => 987_001,
        'usage_reservation_id' => $reservation->id,
        'request_key' => 'pilot-language-request',
        'state' => AiLanguageRequestState::Completed,
        'context_hash' => hash('sha256', 'pilot'),
    ]);
}

test('seeding refuses production without any override', function (): void {
    $this->app->instance('env', 'production');
    // A cohort an operator already seeded into this database must not make a refusal look like a
    // success, so the claim is that the attempt created nothing rather than that none exist.
    $before = User::query()->where('email', 'like', '%@ai-pilot.invalid')->count();

    $this->artisan('ai:seed-test-universe', ['--confirm' => true])
        ->expectsOutputToContain('Refusing to seed synthetic accounts in production.')
        ->assertExitCode(1);

    expect(User::query()->where('email', 'like', '%@ai-pilot.invalid')->count())->toBe($before);
});

test('seeding requires an explicit confirmation', function (): void {
    $before = User::query()->where('email', 'like', '%@ai-pilot.invalid')->count();

    $this->artisan('ai:seed-test-universe')
        ->expectsOutputToContain('Re-run with --confirm.')
        ->assertExitCode(1);

    expect(User::query()->where('email', 'like', '%@ai-pilot.invalid')->count())->toBe($before);
});

/**
 * Removes a pilot cohort an earlier run or an operator left in this shared database, so the counts
 * below describe what this test caused rather than what it inherited. The test transaction rolls
 * the deletion back, and the checks come off because a planet and its owner reference each other.
 */
function clearSeededPilotAccounts(): void
{
    $ids = User::query()->where('email', 'like', '%@ai-pilot.invalid')->pluck('id');

    if ($ids->isEmpty()) {
        return;
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    DB::table('users')->whereIn('id', $ids)->delete();
    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

test('seeding creates ordinary accounts with an enabled profile and a first session', function (): void {
    clearSeededPilotAccounts();

    $this->artisan('ai:seed-test-universe', ['--players' => 2, '--confirm' => true])
        ->expectsOutputToContain('Their first session is due now')
        ->assertExitCode(0);

    $seeded = User::query()->where('email', 'like', '%@ai-pilot.invalid')->orderBy('id')->get();
    $profiles = AiProfile::query()->whereIn('player_id', $seeded->pluck('id'))->orderBy('player_id')->get();

    expect($seeded)->toHaveCount(2)
        ->and($profiles)->toHaveCount(2)
        ->and($profiles->first()->enabled)->toBeTrue()
        ->and($profiles->first()->archetype)->toBe(AiArchetype::cases()[0])
        ->and($profiles->last()->archetype)->toBe(AiArchetype::cases()[1])
        ->and($profiles->first()->skill_band)->toBe(AiSkillBand::cases()[0])
        ->and($profiles->first()->random_seed)->toBe(10_001);

    foreach ($profiles as $profile) {
        $work = AiWorkItem::query()->where('player_id', $profile->player_id)->sole();

        expect($work->kind)->toBe(AiWorkKind::RunSession)
            ->and($work->state)->toBe(AiWorkState::Pending)
            ->and($work->idempotency_key)->toBe('session:' . $profile->player_id . ':1');
    }
});

test('seeding refuses to be the first account in a universe', function (): void {
    // The host promotes the first registered account to admin, so seeding into an empty universe
    // would hand an AI the staff role. Account and planet reference each other, so the fixture
    // empties the table with the checks off; the test transaction rolls every row back.
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    DB::table('users')->delete();
    DB::statement('SET FOREIGN_KEY_CHECKS = 1');

    $this->artisan('ai:seed-test-universe', ['--players' => 1, '--confirm' => true])
        ->expectsOutputToContain('Register the first human account before seeding')
        ->assertExitCode(1);
});

test('seeding twice reuses the same accounts instead of adding more', function (): void {
    clearSeededPilotAccounts();

    $this->artisan('ai:seed-test-universe', ['--players' => 1, '--confirm' => true])->assertExitCode(0);
    $seeded = User::query()->where('email', 'like', '%@ai-pilot.invalid')->sole();
    $accounts = User::query()->count();

    $this->artisan('ai:seed-test-universe', ['--players' => 1, '--confirm' => true])
        ->expectsOutputToContain('already seeded')
        ->assertExitCode(0);

    expect(User::query()->count())->toBe($accounts)
        ->and(User::query()->where('email', 'like', '%@ai-pilot.invalid')->count())->toBe(1)
        ->and(AiProfile::query()->where('player_id', $seeded->id)->count())->toBe(1)
        ->and(AiWorkItem::query()->where('player_id', $seeded->id)->count())->toBe(1);
});

test('the pilot report counts outcomes, failures, lateness and provider tokens', function (): void {
    pilotWorkItem('on-time', AiWorkState::Completed, 3);
    pilotWorkItem('late', AiWorkState::Completed, 30);
    pilotWorkItem('retried', AiWorkState::Retry, 5, 3);
    AiWorkItem::query()->create([
        'player_id' => 987_002,
        'kind' => AiWorkKind::RunSession,
        'due_at' => CarbonImmutable::parse(PILOT_NOW)->subHour(),
        'idempotency_key' => 'pilot:stuck',
        'state' => AiWorkState::Leased,
        'lease_until' => CarbonImmutable::parse(PILOT_NOW)->subMinute(),
    ]);
    pilotReceipt('accepted', AiReceiptState::Accepted);
    pilotReceipt('rejected', AiReceiptState::Rejected);
    pilotLanguageRequest(1_200);

    $report = app(BuildAiPilotReportAction::class)->handle(1);

    expect($report->days)->toBe(1)
        ->and($report->work)->toBe(['created' => 4, 'completed' => 2, 'retried' => 1, 'stuck' => 1])
        ->and($report->actions)->toBe(['Accepted' => 1, 'Rejected' => 1])
        ->and($report->latencyMinutes)->toEqualCanonicalizing([3.0, 30.0])
        ->and($report->language)->toBe(['attempts' => 1, 'tokens' => 1_200])
        ->and($report->feedback)->toBeNull()
        ->and($report->latencyPercentile(50.0))->toBe(3.0)
        ->and($report->latencyPercentile(95.0))->toBe(30.0);

    $this->artisan('ai:pilot-report', ['--days' => 1])
        ->expectsOutputToContain('lateness: p50 3.0 min · p95 30.0 min over 2 completed sessions')
        ->expectsOutputToContain('actions: Accepted 1, Rejected 1')
        ->expectsOutputToContain('human feedback: not recorded for this window.')
        ->assertExitCode(0);
});

test('an empty window reports zeroes instead of failing', function (): void {
    $report = app(BuildAiPilotReportAction::class)->handle(1);

    expect($report->latencyMinutes)->toBe([])
        ->and($report->latencyPercentile(50.0))->toBe(0.0)
        ->and($report->work['created'])->toBe(0)
        ->and($report->actions)->toBe([]);

    $this->artisan('ai:pilot-report')
        ->expectsOutputToContain('actions: none')
        ->assertExitCode(0);
});

test('human feedback is read from the operator file, and a bad one is refused', function (): void {
    $path = sys_get_temp_dir() . '/ai-feedback-' . uniqid('', true) . '.json';
    file_put_contents($path, json_encode(['pressure' => 'manageable', 'recovery' => 'fine'], JSON_THROW_ON_ERROR));

    $this->artisan('ai:pilot-report', ['--feedback' => $path])
        ->expectsOutputToContain('feedback pressure: manageable')
        ->assertExitCode(0);

    expect(app(BuildAiPilotReportAction::class)->handle(1, $path)->feedback)
        ->toBe(['pressure' => 'manageable', 'recovery' => 'fine']);

    unlink($path);

    $this->artisan('ai:pilot-report', ['--feedback' => $path])
        ->expectsOutputToContain('Feedback file not found')
        ->assertExitCode(1);
});

test('a feedback file that is not a JSON object is refused', function (): void {
    $path = sys_get_temp_dir() . '/ai-feedback-' . uniqid('', true) . '.json';

    file_put_contents($path, 'not json');
    $this->artisan('ai:pilot-report', ['--feedback' => $path])
        ->expectsOutputToContain('Feedback file is not valid JSON')
        ->assertExitCode(1);

    file_put_contents($path, '"a string"');
    $this->artisan('ai:pilot-report', ['--feedback' => $path])
        ->expectsOutputToContain('Feedback file must contain a JSON object')
        ->assertExitCode(1);

    unlink($path);
});
