<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\ReserveAiUsageAction;
use Modules\AI\Actions\SettleAiUsageReservationAction;
use Modules\AI\Domain\Conversation\UsageBudgetLimit;
use Modules\AI\Domain\Conversation\UsageBudgetLimits;
use Modules\AI\Domain\Conversation\UsageReservationRequest;
use Modules\AI\Enums\AiUsageBudgetScope;
use Modules\AI\Enums\AiUsageReservationState;
use Modules\AI\Models\AiUsageBudget;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

test('usage reservation atomically enforces every scope and preserves idempotency', function (): void {
    $reserve = app(ReserveAiUsageAction::class);
    $limits = app()->makeWith(UsageBudgetLimits::class, [
        'universe' => usageBudgetLimit(2, 300, 100),
        'player' => usageBudgetLimit(2, 300, 100),
        'conversation' => usageBudgetLimit(1, 200, 80),
    ]);
    $first = $reserve->handle(usageReservationRequest($this->currentUserId, 'conversation-a', 'request-a', 100, 40), $limits);
    $duplicate = $reserve->handle(usageReservationRequest($this->currentUserId, 'conversation-a', 'request-a', 100, 40), $limits);
    $conversationCap = $reserve->handle(usageReservationRequest($this->currentUserId, 'conversation-a', 'request-b', 100, 40), $limits);
    $secondConversation = $reserve->handle(usageReservationRequest($this->currentUserId, 'conversation-b', 'request-c', 100, 40), $limits);
    $playerCap = $reserve->handle(usageReservationRequest($this->currentUserId, 'conversation-c', 'request-d', 100, 40), $limits);

    expect($first)->not->toBeNull()
        ->and($duplicate?->id)->toBe($first?->id)
        ->and($conversationCap)->toBeNull()
        ->and($secondConversation)->not->toBeNull()
        ->and($playerCap)->toBeNull()
        ->and(AiUsageBudget::query()->where('scope', AiUsageBudgetScope::Universe)->sole()->reserved_attempts)->toBe(2)
        ->and(AiUsageBudget::query()->where('scope', AiUsageBudgetScope::Player)->sole()->reserved_input_tokens)->toBe(200)
        ->and(AiUsageBudget::query()->where('scope', AiUsageBudgetScope::Conversation)->where('scope_key', 'conversation-a')->sole()->reserved_output_tokens)->toBe(40);
});

test('settling a reservation releases unused token capacity once while retaining its attempted request', function (): void {
    $reservation = app(ReserveAiUsageAction::class)->handle(
        usageReservationRequest($this->currentUserId, 'conversation-a', 'request-a', 200, 80),
        usageBudgetLimits(),
    );
    expect($reservation)->not->toBeNull();

    $settled = app(SettleAiUsageReservationAction::class)->handle($reservation->id, 120, 30, CarbonImmutable::parse('2026-09-11 12:00 UTC'));
    $duplicateSettlement = app(SettleAiUsageReservationAction::class)->handle($reservation->id, 1, 1, CarbonImmutable::parse('2026-09-11 13:00 UTC'));

    expect($settled?->state)->toBe(AiUsageReservationState::Settled)
        ->and($settled?->actual_input_tokens)->toBe(120)
        ->and($settled?->actual_output_tokens)->toBe(30)
        ->and($duplicateSettlement?->actual_input_tokens)->toBe(120)
        ->and(AiUsageBudget::query()->where('scope', AiUsageBudgetScope::Universe)->sole()->reserved_attempts)->toBe(1)
        ->and(AiUsageBudget::query()->where('scope', AiUsageBudgetScope::Universe)->sole()->reserved_input_tokens)->toBe(120)
        ->and(AiUsageBudget::query()->where('scope', AiUsageBudgetScope::Universe)->sole()->reserved_output_tokens)->toBe(30);
});

test('invalid requested or actual token counts are refused without changing a budget', function (): void {
    $invalidReservation = app(ReserveAiUsageAction::class)->handle(
        usageReservationRequest($this->currentUserId, 'conversation-a', 'request-a', -1, 20),
        usageBudgetLimits(),
    );
    $reservation = app(ReserveAiUsageAction::class)->handle(
        usageReservationRequest($this->currentUserId, 'conversation-a', 'request-b', 20, 20),
        usageBudgetLimits(),
    );
    expect($reservation)->not->toBeNull();
    $invalidSettlement = app(SettleAiUsageReservationAction::class)->handle($reservation->id, -1, 10, CarbonImmutable::parse('2026-09-11 12:00 UTC'));
    $oversizedSettlement = app(SettleAiUsageReservationAction::class)->handle($reservation->id, 21, 10, CarbonImmutable::parse('2026-09-11 12:00 UTC'));

    expect($invalidReservation)->toBeNull()
        ->and($invalidSettlement)->toBeNull()
        ->and($oversizedSettlement)->toBeNull()
        ->and(AiUsageBudget::query()->where('scope', AiUsageBudgetScope::Universe)->sole()->reserved_input_tokens)->toBe(20);
});

test('an unknown or exact settlement does not change a valid reservation ledger', function (): void {
    $unknownSettlement = app(SettleAiUsageReservationAction::class)->handle(999, 1, 1, CarbonImmutable::parse('2026-09-11 12:00 UTC'));
    $reservation = app(ReserveAiUsageAction::class)->handle(
        usageReservationRequest($this->currentUserId, 'conversation-a', 'request-a', 20, 10),
        usageBudgetLimits(),
    );
    expect($reservation)->not->toBeNull();

    $settled = app(SettleAiUsageReservationAction::class)->handle($reservation->id, 20, 10, CarbonImmutable::parse('2026-09-11 12:00 UTC'));

    expect($unknownSettlement)->toBeNull()
        ->and($settled?->state)->toBe(AiUsageReservationState::Settled)
        ->and(AiUsageBudget::query()->where('scope', AiUsageBudgetScope::Universe)->sole()->reserved_input_tokens)->toBe(20)
        ->and(AiUsageBudget::query()->where('scope', AiUsageBudgetScope::Universe)->sole()->reserved_output_tokens)->toBe(10);
});

function usageReservationRequest(int $playerId, string $conversationKey, string $requestKey, int $inputTokens, int $outputTokens): UsageReservationRequest
{
    return app()->makeWith(UsageReservationRequest::class, [
        'universeScope' => 'default',
        'playerId' => $playerId,
        'conversationKey' => $conversationKey,
        'requestKey' => $requestKey,
        'inputTokens' => $inputTokens,
        'outputTokens' => $outputTokens,
        'reservedAt' => CarbonImmutable::parse('2026-09-11 10:00 UTC'),
    ]);
}

function usageBudgetLimits(): UsageBudgetLimits
{
    return app()->makeWith(UsageBudgetLimits::class, [
        'universe' => usageBudgetLimit(5, 1000, 500),
        'player' => usageBudgetLimit(5, 1000, 500),
        'conversation' => usageBudgetLimit(5, 1000, 500),
    ]);
}

function usageBudgetLimit(int $attempts, int $inputTokens, int $outputTokens): UsageBudgetLimit
{
    return app()->makeWith(UsageBudgetLimit::class, [
        'attempts' => $attempts,
        'inputTokens' => $inputTokens,
        'outputTokens' => $outputTokens,
    ]);
}
