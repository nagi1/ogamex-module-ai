<?php

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Modules\AI\Actions\ReconcileAiLanguageRequestsAction;
use Modules\AI\Enums\AiLanguageRequestState;
use Modules\AI\Enums\AiUsageReservationState;
use Modules\AI\Models\AiLanguageRequest;
use Modules\AI\Models\AiUsageReservation;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;
use Modules\AI\Tests\Support\FixtureAiClock;
use OGame\Models\ChatMessage;

require_once __DIR__ . '/../Support/AiQueueModuleTestCase.php';
require_once __DIR__ . '/../Support/FixtureAiClock.php';
require_once __DIR__ . '/../Support/LanguageGatewayTimeout.php';
require_once __DIR__ . '/../Support/LanguageTestFixtures.php';

uses(AiQueueModuleTestCase::class);

beforeEach(function (): void {
    config(['ai.language.reconciliation_minutes' => 30]);
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse('2024-01-01 00:00:00 UTC'),
    ]));
});

test('a stale uncertain attempt is charged at its reserved maximum and released as authored text', function (): void {
    [$reply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I will send 500 metal.');
    $request = languageRequestRecord($reply, AiLanguageRequestState::Uncertain);
    $request->update(['created_at' => CarbonImmutable::parse('2023-12-31 22:00:00 UTC')]);

    $reconciled = app(ReconcileAiLanguageRequestsAction::class)->handle();
    $reservation = AiUsageReservation::query()->sole();

    expect($reconciled)->toBe(1)
        ->and($reservation->state)->toBe(AiUsageReservationState::Settled)
        ->and($reservation->actual_input_tokens)->toBe(2_000)
        ->and($reservation->actual_output_tokens)->toBe(320)
        ->and($request->refresh()->state)->toBe(AiLanguageRequestState::Unobserved)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->sole()->message)->toBe('Authored fallback.')
        ->and(app(ReconcileAiLanguageRequestsAction::class)->handle())->toBe(0);
});

test('a worker killed between its receipt and its settlement is closed the same way', function (): void {
    [$reply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I will send 500 metal.');
    $request = languageRequestRecord($reply, AiLanguageRequestState::Generating);
    $request->update(['created_at' => CarbonImmutable::parse('2023-12-31 22:00:00 UTC')]);

    expect(app(ReconcileAiLanguageRequestsAction::class)->handle())->toBe(1)
        ->and($request->refresh()->state)->toBe(AiLanguageRequestState::Unobserved)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->sole()->message)->toBe('Authored fallback.');
});

test('an attempt whose reservation was settled elsewhere is closed without being charged twice', function (): void {
    [$reply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I will send 500 metal.');
    $request = languageRequestRecord($reply, AiLanguageRequestState::Uncertain);
    $request->update(['created_at' => CarbonImmutable::parse('2023-12-31 22:00:00 UTC')]);
    AiUsageReservation::query()->sole()->update([
        'state' => AiUsageReservationState::Settled,
        'actual_input_tokens' => 10,
        'actual_output_tokens' => 5,
    ]);

    expect(app(ReconcileAiLanguageRequestsAction::class)->handle())->toBe(0)
        ->and($request->refresh()->state)->toBe(AiLanguageRequestState::Unobserved)
        ->and(AiUsageReservation::query()->sole()->actual_input_tokens)->toBe(10);
});

test('a recent uncertain attempt and already settled attempts keep their accounting', function (): void {
    [$recentReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I will send 500 metal.');
    languageRequestRecord($recentReply, AiLanguageRequestState::Uncertain);

    [$runningReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I will send 500 metal.');
    languageRequestRecord($runningReply, AiLanguageRequestState::Generating);

    [$completedReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I will send 500 metal.');
    $completed = languageRequestRecord($completedReply, AiLanguageRequestState::Completed);
    $completed->update(['created_at' => CarbonImmutable::parse('2023-12-31 22:00:00 UTC')]);

    [$failedReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I will send 500 metal.');
    $failed = languageRequestRecord($failedReply, AiLanguageRequestState::Failed);
    $failed->update(['created_at' => CarbonImmutable::parse('2023-12-31 22:00:00 UTC')]);

    expect(app(ReconcileAiLanguageRequestsAction::class)->handle())->toBe(0)
        ->and(AiUsageReservation::query()->where('state', AiUsageReservationState::Reserved)->count())->toBe(4)
        ->and(AiLanguageRequest::query()->whereIn('state', [AiLanguageRequestState::Uncertain, AiLanguageRequestState::Generating])->count())->toBe(2);
});

test('the reconciliation command reports the attempts it settled', function (): void {
    [$reply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I will send 500 metal.');
    $request = languageRequestRecord($reply, AiLanguageRequestState::Uncertain);
    $request->update(['created_at' => CarbonImmutable::parse('2023-12-31 22:00:00 UTC')]);

    $this->artisan('ai:reconcile-language-requests')
        ->expectsOutputToContain('1 AI language reservation(s) reconciled.')
        ->assertExitCode(0);

    expect(AiLanguageRequest::query()->sole()->state)->toBe(AiLanguageRequestState::Unobserved);
});

test('the module schedules the reconciliation command', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(static fn (object $event): bool => str_contains((string) $event->command, 'ai:reconcile-language-requests'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/10 * * * *');
});
