<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\AI\Domain\Conversation\UsageBudgetLimit;
use Modules\AI\Domain\Conversation\UsageBudgetLimits;
use Modules\AI\Domain\Conversation\UsageReservationRequest;
use Modules\AI\Enums\AiUsageBudgetScope;
use Modules\AI\Enums\AiUsageReservationState;
use Modules\AI\Models\AiUsageBudget;
use Modules\AI\Models\AiUsageReservation;

class ReserveAiUsageAction
{
    public function handle(UsageReservationRequest $request, UsageBudgetLimits $limits): AiUsageReservation|null
    {
        if ($request->inputTokens < 0 || $request->outputTokens < 0) {
            return null;
        }

        return DB::transaction(function () use ($request, $limits): AiUsageReservation|null {
            $budgetRows = $this->lockBudgetRows($request);

            $existing = AiUsageReservation::query()
                ->where('request_key', $request->requestKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            if (!$this->canReserve($budgetRows, $limits, $request)) {
                return null;
            }

            $this->increaseReservedUsage($budgetRows, $request);

            return AiUsageReservation::query()->create([
                'universe_scope' => $request->universeScope,
                'player_id' => $request->playerId,
                'conversation_key' => $request->conversationKey,
                'request_key' => $request->requestKey,
                'reserved_for' => $request->reservedAt->toDateString(),
                'reserved_input_tokens' => $request->inputTokens,
                'reserved_output_tokens' => $request->outputTokens,
                'state' => AiUsageReservationState::Reserved,
            ]);
        });
    }

    /** @return Collection<int, AiUsageBudget> */
    private function lockBudgetRows(UsageReservationRequest $request): Collection
    {
        $reservedFor = $request->reservedAt->toDateString();
        $scopes = $this->scopesFor($request);

        AiUsageBudget::query()->upsert(
            $scopes->map(fn (array $scope): array => [
                'scope' => $scope['scope']->value,
                'scope_key' => $scope['key'],
                'reserved_for' => $reservedFor,
                'reserved_attempts' => 0,
                'reserved_input_tokens' => 0,
                'reserved_output_tokens' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all(),
            ['scope', 'scope_key', 'reserved_for'],
            ['updated_at'],
        );

        return AiUsageBudget::query()
            ->where('reserved_for', $reservedFor)
            ->where(function ($query) use ($scopes): void {
                $scopes->each(function (array $scope) use ($query): void {
                    $query->orWhere(function ($scopeQuery) use ($scope): void {
                        $scopeQuery->where('scope', $scope['scope'])->where('scope_key', $scope['key']);
                    });
                });
            })
            ->orderBy('scope')
            ->orderBy('scope_key')
            ->lockForUpdate()
            ->get();
    }

    /** @return Collection<int, array{scope: AiUsageBudgetScope, key: string}> */
    private function scopesFor(UsageReservationRequest $request): Collection
    {
        return collect([
            ['scope' => AiUsageBudgetScope::Universe, 'key' => $request->universeScope],
            ['scope' => AiUsageBudgetScope::Player, 'key' => (string) $request->playerId],
            ['scope' => AiUsageBudgetScope::Conversation, 'key' => $request->conversationKey],
        ])->sortBy(fn (array $scope): string => sprintf('%d:%s', $scope['scope']->value, $scope['key']))->values();
    }

    /** @param Collection<int, AiUsageBudget> $budgetRows */
    private function canReserve(Collection $budgetRows, UsageBudgetLimits $limits, UsageReservationRequest $request): bool
    {
        return $budgetRows->every(fn (AiUsageBudget $budget): bool => $this->withinLimit($budget, $this->limitFor($budget->scope, $limits), $request));
    }

    private function withinLimit(AiUsageBudget $budget, UsageBudgetLimit $limit, UsageReservationRequest $request): bool
    {
        return $budget->reserved_attempts + 1 <= $limit->attempts
            && $budget->reserved_input_tokens + $request->inputTokens <= $limit->inputTokens
            && $budget->reserved_output_tokens + $request->outputTokens <= $limit->outputTokens;
    }

    private function limitFor(AiUsageBudgetScope $scope, UsageBudgetLimits $limits): UsageBudgetLimit
    {
        return match ($scope) {
            AiUsageBudgetScope::Universe => $limits->universe,
            AiUsageBudgetScope::Player => $limits->player,
            AiUsageBudgetScope::Conversation => $limits->conversation,
        };
    }

    /** @param Collection<int, AiUsageBudget> $budgetRows */
    private function increaseReservedUsage(Collection $budgetRows, UsageReservationRequest $request): void
    {
        $budgetRows->each(fn (AiUsageBudget $budget) => $budget->incrementEach([
            'reserved_attempts' => 1,
            'reserved_input_tokens' => $request->inputTokens,
            'reserved_output_tokens' => $request->outputTokens,
        ]));
    }
}
