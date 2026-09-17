<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Operability\AiOperabilityOverview;
use Modules\AI\Enums\AiLanguageRequestState;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiLanguageRequest;
use Modules\AI\Models\AiOperabilitySwitch;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiStopCounter;
use Modules\AI\Models\AiUsageReservation;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;

/**
 * Collects the operator's view of one universe: what is configured, what is running, what was
 * refused today and what the optional provider has cost.
 *
 * Today is the window because that is the question a pilot asks — is the population working
 * now — and because every count here is bounded by a day rather than growing with the
 * population. Nothing in this summary decides anything; the caps are enforced where the work
 * is admitted, and this reads their outcome.
 */
class SummarizeAiOperabilityAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(): AiOperabilityOverview
    {
        $now = $this->clock->now();
        $switch = AiOperabilitySwitch::query()->orderByDesc('id')->first();

        return app()->makeWith(AiOperabilityOverview::class, [
            'workEnabled' => $switch->enabled ?? true,
            'switchReason' => $switch?->reason,
            'switchedAt' => $switch?->changed_at?->toDateTimeString(),
            'switchedByPlayerId' => $switch?->actor_player_id,
            'limits' => $this->limits(),
            'stopReasons' => $this->stopReasons($now->toDateString()),
            'population' => $this->population($now),
            'actions' => $this->actions($now),
            'language' => $this->language($now),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function limits(): array
    {
        return [
            'profile_cap' => max(0, (int) config('ai.population.profile_cap', 0)),
            'active_session_cap' => max(0, (int) config('ai.population.active_session_cap', 0)),
            'dispatch_batch_size' => max(1, (int) config('ai.population.dispatch_batch_size', 100)),
            'session_action_cap' => (int) config('ai.population.session_action_cap', 1),
            // The language budget is a daily universe attempt count, so it belongs beside the
            // population caps even though the language slice owns its own configuration.
            'language_daily_attempts' => (int) config('ai.language.daily_limits.universe.attempts', 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stopReasons(string $day): array
    {
        return AiStopCounter::query()
            ->where('observed_on', $day)
            ->orderByDesc('occurrences')
            ->get()
            ->map(static fn (AiStopCounter $counter): array => [
                'reason' => $counter->reason->value,
                'occurrences' => $counter->occurrences,
                'context' => $counter->last_context ?? [],
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function population(CarbonImmutable $now): array
    {
        return [
            'profiles' => AiProfile::query()->where('enabled', true)->count(),
            'due_work' => AiWorkItem::query()
                ->whereIn('state', [AiWorkState::Pending, AiWorkState::Retry])
                ->where('due_at', '<=', $now)
                ->count(),
            'sessions_in_flight' => AiWorkItem::query()
                ->where('state', AiWorkState::Leased)
                ->where('lease_until', '>', $now)
                ->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function actions(CarbonImmutable $now): array
    {
        return AiActionReceipt::query()
            ->where('created_at', '>=', $now->startOfDay())
            ->pluck('state')
            ->countBy(static fn (AiReceiptState $state): string => $state->name)
            ->all();
    }

    /**
     * @return array<string, int|float>
     */
    private function language(CarbonImmutable $now): array
    {
        $reservations = AiUsageReservation::query()
            ->where('reserved_for', $now->toDateString())
            ->get(['reserved_input_tokens', 'reserved_output_tokens', 'actual_input_tokens', 'actual_cached_input_tokens', 'actual_output_tokens', 'cost']);

        // Cached input was billed, so it belongs in the actual total; the hit rate is its share of
        // the input, which is the only measurement a stable prompt prefix can be judged by.
        $inputTokens = (int) $reservations->sum(static fn ($reservation): int => (int) $reservation->actual_input_tokens + (int) $reservation->actual_cached_input_tokens);
        $cachedInputTokens = (int) $reservations->sum(static fn ($reservation): int => (int) $reservation->actual_cached_input_tokens);

        return [
            'attempts' => AiLanguageRequest::query()->where('created_at', '>=', $now->startOfDay())->count(),
            'in_flight' => AiLanguageRequest::query()->where('state', AiLanguageRequestState::Generating)->count(),
            'reserved_tokens' => (int) $reservations->sum(static fn ($reservation): int => $reservation->reserved_input_tokens + $reservation->reserved_output_tokens),
            'actual_tokens' => $inputTokens + (int) $reservations->sum(static fn ($reservation): int => (int) $reservation->actual_output_tokens),
            'cached_input_tokens' => $cachedInputTokens,
            'cache_hit_rate' => $inputTokens === 0 ? 0.0 : round($cachedInputTokens / $inputTokens, 4),
            'cost' => round((float) $reservations->sum(static fn ($reservation): float => (float) $reservation->cost), 8),
        ];
    }
}
