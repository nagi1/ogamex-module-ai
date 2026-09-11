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

test('a stale uncertain attempt is charged at its reserved maximum exactly once', function (): void {
    [$reply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I will send 500 metal.');
    $request = languageRequestRecord($reply, AiLanguageRequestState::Uncertain);
    $request->update(['created_at' => CarbonImmutable::parse('2023-12-31 22:00:00 UTC')]);

    $reconciled = app(ReconcileAiLanguageRequestsAction::class)->handle();
    $reservation = AiUsageReservation::query()->sole();

    expect($reconciled)->toBe(1)
        ->and($reservation->state)->toBe(AiUsageReservationState::Settled)
        ->and($reservation->actual_input_tokens)->toBe(2_000)
        ->and($reservation->actual_output_tokens)->toBe(320)
        ->and($request->refresh()->state)->toBe(AiLanguageRequestState::Uncertain)
        ->and(app(ReconcileAiLanguageRequestsAction::class)->handle())->toBe(0);
});

test('a recent uncertain attempt and already settled attempts keep their accounting', function (): void {
    [$recentReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I will send 500 metal.');
    languageRequestRecord($recentReply, AiLanguageRequestState::Uncertain);

    [$completedReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I will send 500 metal.');
    $completed = languageRequestRecord($completedReply, AiLanguageRequestState::Completed);
    $completed->update(['created_at' => CarbonImmutable::parse('2023-12-31 22:00:00 UTC')]);

    [$failedReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I will send 500 metal.');
    $failed = languageRequestRecord($failedReply, AiLanguageRequestState::Failed);
    $failed->update(['created_at' => CarbonImmutable::parse('2023-12-31 22:00:00 UTC')]);

    expect(app(ReconcileAiLanguageRequestsAction::class)->handle())->toBe(0)
        ->and(AiUsageReservation::query()->where('state', AiUsageReservationState::Reserved)->count())->toBe(3);
});

test('the reconciliation command reports the attempts it settled', function (): void {
    [$reply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I will send 500 metal.');
    $request = languageRequestRecord($reply, AiLanguageRequestState::Uncertain);
    $request->update(['created_at' => CarbonImmutable::parse('2023-12-31 22:00:00 UTC')]);

    $this->artisan('ai:reconcile-language-requests')
        ->expectsOutputToContain('1 AI language reservation(s) reconciled.')
        ->assertExitCode(0);

    expect(AiLanguageRequest::query()->sole()->state)->toBe(AiLanguageRequestState::Uncertain);
});

test('the module schedules the reconciliation command', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(static fn (object $event): bool => str_contains((string) $event->command, 'ai:reconcile-language-requests'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/10 * * * *');
});
