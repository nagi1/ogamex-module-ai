<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiLanguageRequestState;
use Modules\AI\Enums\AiUsageReservationState;
use Modules\AI\Models\AiLanguageRequest;
use Modules\AI\Models\AiUsageReservation;
use Modules\AI\Support\AiClock;

/**
 * Closes a language attempt whose provider completion stopped being observable.
 *
 * Two failures leave an attempt open: a call that timed out stays `Uncertain` because it may
 * still be completing remotely, and a worker killed between writing the receipt and settling
 * it leaves `Generating` with nobody left to finish it. Once the reconciliation window has
 * passed, neither outcome can be observed any more, so the attempt is charged at its reserved
 * maximum exactly once — the daily capacity stays honestly consumed and the unsettled
 * reservation stops masking remaining budget — and the reply stops waiting for text that is
 * not coming: the sealed authored message goes out.
 */
class ReconcileAiLanguageRequestsAction
{
    private const BATCH_SIZE = 200;

    public function __construct(private readonly AiClock $clock)
    {
    }

    /**
     * @return int the number of reservations settled
     */
    public function handle(): int
    {
        $deadline = $this->clock->now()->subMinutes(max(1, (int) config('ai.language.reconciliation_minutes', 30)));

        $requests = AiLanguageRequest::query()
            ->whereIn('state', $this->openStates())
            ->where('created_at', '<=', $deadline)
            ->oldest('id')
            ->limit(self::BATCH_SIZE)
            ->get(['id', 'conversation_reply_id', 'usage_reservation_id']);

        $settled = 0;

        foreach ($requests as $request) {
            $settled += $this->close($request);
        }

        return $settled;
    }

    /**
     * The reservation is settled before the reply is released, so an attempt interrupted
     * between the two is closed again by the next run without being charged twice.
     */
    private function close(AiLanguageRequest $request): int
    {
        $settled = $this->settle($request->usage_reservation_id);
        $this->markUnobserved($request->id);

        app(DeliverAiSealedReplyAction::class)->handle($request->conversation_reply_id);

        return $settled;
    }

    /**
     * A reservation that is no longer reserved was settled by the worker that owns it, so
     * only an attempt that is genuinely unsettled consumes budget here.
     */
    private function settle(int $usageReservationId): int
    {
        $reservation = AiUsageReservation::query()
            ->whereKey($usageReservationId)
            ->where('state', AiUsageReservationState::Reserved)
            ->first();

        if ($reservation === null) {
            return 0;
        }

        $result = app(SettleAiUsageReservationAction::class)->handle(
            $reservation->id,
            $reservation->reserved_input_tokens,
            $reservation->reserved_output_tokens,
            $this->clock->now(),
        );

        return $result === null ? 0 : 1;
    }

    /**
     * The open state is checked in the update itself: a worker that completed the attempt
     * while this sweep was reading it must not have its result overwritten. A late completion
     * is dropped the same way, so a closed attempt never replaces a reply already sent.
     */
    private function markUnobserved(int $languageRequestId): void
    {
        AiLanguageRequest::query()
            ->whereKey($languageRequestId)
            ->whereIn('state', $this->openStates())
            ->update(['state' => AiLanguageRequestState::Unobserved]);
    }

    /**
     * @return list<AiLanguageRequestState>
     */
    private function openStates(): array
    {
        return [AiLanguageRequestState::Generating, AiLanguageRequestState::Uncertain];
    }
}
