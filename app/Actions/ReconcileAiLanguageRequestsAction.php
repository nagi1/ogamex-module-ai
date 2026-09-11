<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiLanguageRequestState;
use Modules\AI\Enums\AiUsageReservationState;
use Modules\AI\Models\AiLanguageRequest;
use Modules\AI\Models\AiUsageReservation;
use Modules\AI\Support\AiClock;

class ReconcileAiLanguageRequestsAction
{
    private const BATCH_SIZE = 200;

    public function __construct(private readonly AiClock $clock)
    {
    }

    /**
     * Charges an attempt whose provider completion can no longer be observed at its
     * reserved maximum: the daily capacity stays honestly consumed once, the unsettled
     * reservation stops masking remaining budget and the reply is never resent blindly.
     */
    public function handle(): int
    {
        $deadline = $this->clock->now()->subMinutes(max(1, (int) config('ai.language.reconciliation_minutes', 30)));

        $requests = AiLanguageRequest::query()
            ->where('state', AiLanguageRequestState::Uncertain)
            ->where('created_at', '<=', $deadline)
            ->oldest('id')
            ->limit(self::BATCH_SIZE)
            ->get(['id', 'usage_reservation_id']);

        if ($requests->isEmpty()) {
            return 0;
        }

        $reservations = AiUsageReservation::query()
            ->whereIn('id', $requests->pluck('usage_reservation_id')->all())
            ->where('state', AiUsageReservationState::Reserved)
            ->get();

        $settled = 0;

        foreach ($reservations as $reservation) {
            $settled += $this->settle($reservation);
        }

        return $settled;
    }

    private function settle(AiUsageReservation $reservation): int
    {
        $result = app(SettleAiUsageReservationAction::class)->handle(
            $reservation->id,
            $reservation->reserved_input_tokens,
            $reservation->reserved_output_tokens,
            $this->clock->now(),
        );

        return $result === null ? 0 : 1;
    }
}
