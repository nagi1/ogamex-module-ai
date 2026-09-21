<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\BuildAiSituationPanelAction;
use Modules\AI\Actions\SetAiAccountEnabledAction;
use Modules\AI\Actions\SummarizeAiLivenessAction;
use Modules\AI\Actions\SummarizeAiProviderVisibilityAction;
use Modules\AI\Actions\SummarizeAiStorageHealthAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiLanguageRequestState;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiUsageReservationState;
use Modules\AI\Models\AiLanguageRequest;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSchedule;
use Modules\AI\Models\AiUsageReservation;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;
use Modules\AI\Tests\Support\FixtureAiClock;

require_once __DIR__ . '/../Support/AiQueueModuleTestCase.php';
require_once __DIR__ . '/../Support/FixtureAiClock.php';

uses(AiQueueModuleTestCase::class);

const MONITORING_NOW = '2026-09-20 12:00:00';

beforeEach(function (): void {
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse(MONITORING_NOW),
    ]));
});

test('liveness separates a stalled account from a quiet but healthy one', function (): void {
    AiSchedule::create([
        'player_id' => $this->currentUserId,
        'timezone' => 'Europe/Paris',
        'next_due_at' => CarbonImmutable::parse('2026-09-20 11:00:00'),
        'last_activity_at' => CarbonImmutable::parse('2026-09-20 10:00:00'),
    ]);
    $healthy = $this->createUser();
    AiSchedule::create([
        'player_id' => $healthy->id,
        'timezone' => 'Europe/Paris',
        'next_due_at' => CarbonImmutable::parse('2026-09-20 13:00:00'),
        'last_activity_at' => CarbonImmutable::parse('2026-09-20 11:30:00'),
    ]);

    $overview = app(SummarizeAiLivenessAction::class)->handle();

    expect($overview->overdueAccounts)->toBe(1)
        ->and($overview->lastActivityAt)->toBe('2026-09-20 11:30:00');
});

test('storage health reports a table whose oldest row is older than its window', function (): void {
    AiObservation::create([
        'player_id' => $this->currentUserId,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => 9001,
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => null,
        'source_time' => CarbonImmutable::parse('2026-08-01 12:00:00'),
        'observed_at' => CarbonImmutable::parse('2026-08-01 12:00:00'),
        'created_at' => CarbonImmutable::parse('2026-08-01 12:00:00'),
    ]);

    $overview = app(SummarizeAiStorageHealthAction::class)->handle();

    $row = collect($overview->tables)->firstWhere('model', 'AiObservation');

    expect($row)->not->toBeNull()
        ->and($row['behind'])->toBeTrue()
        ->and($row['oldestAgeDays'])->toBeGreaterThanOrEqual(30);
});

test('provider visibility aggregates per vendor and reports an unconfigured ledger', function (): void {
    expect(app(SummarizeAiProviderVisibilityAction::class)->handle()->configured)->toBeFalse();

    $openai = aiMonitoringReservation(1, 0.0042);
    $anthropic = aiMonitoringReservation(2, 0.0011);
    aiMonitoringRequest($openai->id, 'openai', 100, 50, 300);
    aiMonitoringRequest($anthropic->id, 'anthropic', 60, 40, 200);

    $overview = app(SummarizeAiProviderVisibilityAction::class)->handle();

    expect($overview->configured)->toBeTrue()
        ->and($overview->vendors)->toHaveCount(2);

    $openaiRow = collect($overview->vendors)->firstWhere('provider', 'openai');

    expect($openaiRow['attempts'])->toBe(1)
        ->and($openaiRow['tokens'])->toBe(150)
        ->and($openaiRow['avgLatencyMs'])->toBe(300)
        ->and($openaiRow['cost'])->toEqualWithDelta(0.0042, 0.0001);
});

test('the situation panel answers the five review questions with an evidence class', function (): void {
    $panel = app(BuildAiSituationPanelAction::class)->handle(7);

    expect($panel->questions)->toHaveCount(5)
        ->and(collect($panel->questions)->pluck('evidence')->all())->each->toBe('measured')
        ->and(collect($panel->questions)->every(fn (array $row): bool => $row['question'] !== '' && $row['figure'] !== '' && $row['window'] === 7))->toBeTrue();
});

test('stopping one account records the reason and leaves the others running', function (): void {
    $other = $this->createUser();

    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 1, 'enabled' => true]);
    AiProfile::create(['player_id' => $other->id, 'archetype' => AiArchetype::Fleeter, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 2, 'enabled' => true]);

    app(SetAiAccountEnabledAction::class)->handle($this->currentUserId, false, 'misbehaving', 7);

    $stopped = AiProfile::query()->where('player_id', $this->currentUserId)->sole();

    expect($stopped->enabled)->toBeFalse()
        ->and(AiProfile::query()->where('player_id', $other->id)->sole()->enabled)->toBeTrue()
        ->and($stopped->settings['account_switch']['enabled'])->toBeFalse()
        ->and($stopped->settings['account_switch']['reason'])->toBe('misbehaving')
        ->and($stopped->settings['account_switch']['actor_player_id'])->toBe(7);
});

function aiMonitoringReservation(int $seed, float $cost): AiUsageReservation
{
    return AiUsageReservation::create([
        'universe_scope' => 'ogamex',
        'player_id' => 1,
        'conversation_key' => 'conv-' . $seed,
        'request_key' => 'reservation-' . $seed,
        'reserved_for' => '2026-09-20',
        'reserved_input_tokens' => 100,
        'reserved_output_tokens' => 100,
        'state' => AiUsageReservationState::Settled,
        'cost' => $cost,
    ]);
}

function aiMonitoringRequest(int $reservationId, string $provider, int $input, int $output, int $latency): AiLanguageRequest
{
    return AiLanguageRequest::create([
        'conversation_reply_id' => $reservationId + 1000,
        'usage_reservation_id' => $reservationId,
        'request_key' => 'language-' . $reservationId,
        'state' => AiLanguageRequestState::Completed,
        'provider' => $provider,
        'model' => 'test-model',
        'context_hash' => str_repeat('a', 64),
        'input_tokens' => $input,
        'output_tokens' => $output,
        'latency_milliseconds' => $latency,
        // The suite freezes the framework clock, so pin created_at inside the
        // action's 30-day window rather than inheriting the frozen timestamp.
        'created_at' => CarbonImmutable::parse(MONITORING_NOW),
    ]);
}
