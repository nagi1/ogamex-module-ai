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

test('cached input is recorded, priced at the cached rate and released against the whole input', function (): void {
    $reservation = app(ReserveAiUsageAction::class)->handle(
        usageReservationRequest($this->currentUserId, 'conversation-a', 'request-a', 200, 80),
        usageBudgetLimits(),
    );
    expect($reservation)->not->toBeNull();

    // 120 uncached at $0.15, 80 served from the provider's cache at $0.003 and 30 output at $0.60,
    // per 1M tokens off-peak (a Sunday, so no multiplier).
    $expectedCost = (120 * 0.15 + 80 * 0.003 + 30 * 0.60) / 1_000_000;

    $settled = app(SettleAiUsageReservationAction::class)->handle(
        $reservation->id,
        120,
        30,
        CarbonImmutable::parse('2026-09-13 12:00 UTC'),
        'deepseek',
        'deepseek-flash',
        80,
    );

    expect($settled?->state)->toBe(AiUsageReservationState::Settled)
        ->and($settled?->actual_input_tokens)->toBe(120)
        ->and($settled?->actual_cached_input_tokens)->toBe(80)
        ->and($settled?->cost)->toEqualWithDelta($expectedCost, 1e-12)
        // The cached half was billed too, so the release is measured against both halves: 200
        // reserved minus 200 consumed leaves the budget whole rather than 80 short.
        ->and(AiUsageBudget::query()->where('scope', AiUsageBudgetScope::Universe)->sole()->reserved_input_tokens)->toBe(200);
});

test('the input ceiling counts the cached input the provider served', function (): void {
    $reservation = app(ReserveAiUsageAction::class)->handle(
        usageReservationRequest($this->currentUserId, 'conversation-a', 'request-a', 200, 80),
        usageBudgetLimits(),
    );
    expect($reservation)->not->toBeNull();

    // 190 uncached alone fits inside the 200 the reservation holds; the 20 cached tokens that came
    // with it do not, so the settlement is refused instead of recorded short of what was billed.
    $refused = app(SettleAiUsageReservationAction::class)->handle(
        $reservation->id,
        190,
        10,
        CarbonImmutable::parse('2026-09-13 12:00 UTC'),
        'deepseek',
        'deepseek-flash',
        20,
    );

    expect($refused)->toBeNull()
        ->and($reservation->refresh()->state)->toBe(AiUsageReservationState::Reserved)
        ->and(AiUsageBudget::query()->where('scope', AiUsageBudgetScope::Universe)->sole()->reserved_input_tokens)->toBe(200);
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
