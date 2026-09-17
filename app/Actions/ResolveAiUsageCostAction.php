<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;

/**
 * Turns a settled provider call into its dollar cost.
 *
 * The rate table is configuration (`ai.pricing.rates`), keyed by `provider.model`, so a new or
 * repriced model is a config diff rather than a code change. The peak/off-peak split reuses the
 * vendor windows routing already evaluates, so the price and the route agree on which half of
 * the day it is.
 *
 * A request whose model has no rate fails closed: reporting no cost is honest where a guessed
 * cost would be a number that looks measured and is not.
 */
class ResolveAiUsageCostAction
{
    public function handle(
        string|null $provider,
        string|null $model,
        int $inputTokens,
        int $cachedInputTokens,
        int $outputTokens,
        CarbonImmutable $at,
    ): float|null {
        if ($provider === null || $model === null || $inputTokens < 0 || $cachedInputTokens < 0 || $outputTokens < 0) {
            return null;
        }

        // Read the table whole rather than through dot notation: model names like gpt-5.6-luna
        // contain dots, which the config helper would treat as nesting separators.
        $rates = (array) config('ai.pricing.rates');
        $rate = $rates[$provider . '.' . $model] ?? null;

        if (!is_array($rate) || !isset($rate['input'], $rate['cached_input'], $rate['output'])) {
            return null;
        }

        $isPeak = app(ResolveAiProviderRouteAction::class)->isPeakFor($provider, $at);
        $multiplier = $isPeak ? (float) config('ai.pricing.peak_multiplier', 2.0) : 1.0;

        $dollars = (
            ($inputTokens * (float) $rate['input'])
            + ($cachedInputTokens * (float) $rate['cached_input'])
            + ($outputTokens * (float) $rate['output'])
        ) / 1_000_000 * $multiplier;

        return round($dollars, 8);
    }
}
