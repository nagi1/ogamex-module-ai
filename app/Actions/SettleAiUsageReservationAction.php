<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiUsageBudgetScope;
use Modules\AI\Enums\AiUsageReservationState;
use Modules\AI\Models\AiUsageBudget;
use Modules\AI\Models\AiUsageReservation;

class SettleAiUsageReservationAction
{
    public function handle(int $reservationId, int $actualInputTokens, int $actualOutputTokens, CarbonImmutable $settledAt): AiUsageReservation|null
    {
        if ($actualInputTokens < 0 || $actualOutputTokens < 0) {
            return null;
        }

        return DB::transaction(function () use ($reservationId, $actualInputTokens, $actualOutputTokens, $settledAt): AiUsageReservation|null {
            $reservation = AiUsageReservation::query()->lockForUpdate()->find($reservationId);

            if ($reservation === null) {
                return null;
            }

            if ($reservation->state === AiUsageReservationState::Settled) {
                return $reservation;
            }

            if ($actualInputTokens > $reservation->reserved_input_tokens || $actualOutputTokens > $reservation->reserved_output_tokens) {
                return null;
            }

            $this->releaseUnusedTokens($reservation, $actualInputTokens, $actualOutputTokens);

            $reservation->update([
                'actual_input_tokens' => $actualInputTokens,
                'actual_output_tokens' => $actualOutputTokens,
                'state' => AiUsageReservationState::Settled,
                'settled_at' => $settledAt,
            ]);

            return $reservation->refresh();
        });
    }

    private function releaseUnusedTokens(AiUsageReservation $reservation, int $actualInputTokens, int $actualOutputTokens): void
    {
        $inputDifference = $reservation->reserved_input_tokens - $actualInputTokens;
        $outputDifference = $reservation->reserved_output_tokens - $actualOutputTokens;

        if ($inputDifference === 0 && $outputDifference === 0) {
            return;
        }

        AiUsageBudget::query()
            ->where('reserved_for', $reservation->reserved_for)
            ->where(function ($query) use ($reservation): void {
                $query->where(function ($scopeQuery) use ($reservation): void {
                    $scopeQuery->where('scope', AiUsageBudgetScope::Universe)->where('scope_key', $reservation->universe_scope);
                })->orWhere(function ($scopeQuery) use ($reservation): void {
                    $scopeQuery->where('scope', AiUsageBudgetScope::Player)->where('scope_key', (string) $reservation->player_id);
                })->orWhere(function ($scopeQuery) use ($reservation): void {
                    $scopeQuery->where('scope', AiUsageBudgetScope::Conversation)->where('scope_key', $reservation->conversation_key);
                });
            })
            ->orderBy('scope')
            ->orderBy('scope_key')
            ->lockForUpdate()
            ->get()
            ->each(fn (AiUsageBudget $budget) => $budget->decrementEach([
                'reserved_input_tokens' => $inputDifference,
                'reserved_output_tokens' => $outputDifference,
            ]));
    }
}
