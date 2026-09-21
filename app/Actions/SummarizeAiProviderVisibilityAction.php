<?php

namespace Modules\AI\Actions;

use Modules\AI\Domain\Operability\AiProviderVisibilityOverview;
use Modules\AI\Enums\AiUsageReservationState;
use Modules\AI\Models\AiLanguageRequest;
use Modules\AI\Models\AiUsageReservation;
use Modules\AI\Support\AiClock;

/**
 * Per-vendor lane visibility: what each provider lane attempted, how slow it was, how many
 * tokens it moved and what it cost, so a paid lane silently failing over reads as a lane
 * difference rather than a surprise bill. Never per-account: a vendor is a lane, not a persona.
 */
class SummarizeAiProviderVisibilityAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(): AiProviderVisibilityOverview
    {
        $requests = AiLanguageRequest::query()
            ->where('created_at', '>=', $this->clock->now()->subDays(30))
            ->whereNotNull('provider')
            ->get(['provider', 'latency_milliseconds', 'input_tokens', 'output_tokens', 'usage_reservation_id']);

        if ($requests->isEmpty()) {
            return app()->makeWith(AiProviderVisibilityOverview::class, ['configured' => false, 'vendors' => []]);
        }

        $costByReservation = AiUsageReservation::query()
            ->whereIn('id', $requests->pluck('usage_reservation_id')->all())
            ->get(['id', 'cost'])
            ->keyBy('id')
            ->map(static fn (AiUsageReservation $reservation): float => (float) $reservation->cost);

        $vendors = $requests
            ->groupBy('provider')
            ->map(function ($group, string $provider) use ($costByReservation): array {
                $latencies = $group->pluck('latency_milliseconds')->filter();
                $average = $latencies->avg();

                return [
                    'provider' => $provider,
                    'attempts' => $group->count(),
                    'avgLatencyMs' => $average === null ? null : (int) round($average),
                    'tokens' => $group->sum('input_tokens') + $group->sum('output_tokens'),
                    'cost' => (float) $group->pluck('usage_reservation_id')->sum(fn (int $id): float => $costByReservation[$id] ?? 0.0),
                ];
            })
            ->values()
            ->all();

        return app()->makeWith(AiProviderVisibilityOverview::class, [
            'configured' => true,
            'vendors' => $vendors,
            'monthToDateCost' => $this->monthToDateCost(),
            'monthlyCeiling' => (float) config('ai.cognition.monthly_cost_usd', 0),
        ]);
    }

    /**
     * Settled spend since the first of the current month, across every generative lane.
     * This is the figure the reservation admission layer compares against the wall.
     */
    private function monthToDateCost(): float
    {
        return (float) AiUsageReservation::query()
            ->where('state', AiUsageReservationState::Settled)
            ->where('reserved_for', '>=', $this->clock->now()->startOfMonth()->toDateString())
            ->sum('cost');
    }
}
