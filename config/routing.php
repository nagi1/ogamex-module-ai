<?php

/**
 * Which vendor answers a language request, and in what order.
 *
 * The module owns the ladder; the SDK owns the failover. A rung names a provider and a model,
 * and may be gated to the part of the day when that vendor is worth using, which is what turns
 * an off-peak discount into a routing rule instead of a comment.
 *
 * Off by default. While it is off, the single `ai.language.provider` / `ai.language.model` pair
 * behaves exactly as it did before this file existed, so switching routing on is the only way to
 * change which vendor answers.
 */
return [
    /*
     * When off, every task uses the `ai.language` pair below and the ladders are ignored.
     */
    'enabled' => env('AI_ROUTING_ENABLED', false),

    /*
     * Named windows in UTC, evaluated as half-open periods: [start, end). A period must not wrap
     * past midnight; a window that needs that is two periods. These are the vendor's own published
     * peak hours, so a rung gated to `off_peak` is the cheap half of the day.
     *
     * DeepSeek: 01:00-04:00 and 06:00-10:00 UTC, Monday to Friday, at twice the off-peak rate.
     */
    'windows' => [
        'deepseek_peak' => [
            'days' => [1, 2, 3, 4, 5],
            'periods' => [['01:00', '04:00'], ['06:00', '10:00']],
        ],
    ],

    /*
     * Per-vendor metadata. A vendor with no window is never in peak, so `peak`-gated rungs on it
     * are never selected and `off_peak`-gated ones always are.
     */
    'vendors' => [
        'deepseek' => ['window' => 'deepseek_peak'],
        'openai' => [],
    ],

    /*
     * One ladder per task kind, in the order the SDK should try the rungs. A rung may carry
     * `during` => peak | off_peak | any (default any), evaluated against that vendor's window.
     *
     * A vendor whose key is absent from the environment is dropped before the call, so a missing
     * credential reads as "that vendor is off" rather than a round trip that must fail. The first
     * surviving rung answers unless the provider itself fails over to the next one.
     *
     * A peak-gated rung is how a free or flat-priced vendor takes the expensive half of the day:
     *
     *     ['provider' => 'openrouter', 'model' => '<free-model>', 'during' => 'peak'],
     */
    'ladders' => [
        'conversation_reply' => [
            ['provider' => 'deepseek', 'model' => 'deepseek-flash'],
            ['provider' => 'openai', 'model' => 'gpt-5.6-luna'],
        ],
        'campaign_consultation' => [
            ['provider' => 'deepseek', 'model' => 'deepseek-v4-pro'],
        ],
        'conformance' => [
            ['provider' => 'deepseek', 'model' => 'deepseek-flash'],
        ],
    ],
];
