<?php

return [
    /*
     * The campaign consultation lane is fail-closed: `off` by default, so a module
     * update never starts sending campaign facts to a provider. `observe` records a
     * validated recommendation without applying it; `advice` may apply a
     * profile-bounded ranking adjustment. This is operator policy, not a game rule.
     */
    'mode' => env('AI_CAMPAIGN_CONSULTATION_MODE', 'off'),

    'provider' => env('AI_CAMPAIGN_CONSULTATION_PROVIDER', 'openai'),
    'model' => env('AI_CAMPAIGN_CONSULTATION_MODEL', 'gpt-5-mini'),
    'timeout_seconds' => (int) env('AI_CAMPAIGN_CONSULTATION_TIMEOUT_SECONDS', 20),
    'maximum_reason_characters' => (int) env('AI_CAMPAIGN_CONSULTATION_MAXIMUM_REASON_CHARACTERS', 400),
    'maximum_evidence_ids' => (int) env('AI_CAMPAIGN_CONSULTATION_MAXIMUM_EVIDENCE_IDS', 8),
    'maximum_input_tokens' => (int) env('AI_CAMPAIGN_CONSULTATION_MAXIMUM_INPUT_TOKENS', 4_000),
    'maximum_output_tokens' => (int) env('AI_CAMPAIGN_CONSULTATION_MAXIMUM_OUTPUT_TOKENS', 640),
    'universe_scope' => env('AI_CAMPAIGN_CONSULTATION_UNIVERSE_SCOPE', 'default'),

    /*
     * Which campaign events may trigger a consultation. Removing a trigger here is
     * operator policy; the lane can never self-expand this list.
     */
    'allowed_triggers' => [
        'fleet_loss',
        'repeated_setback',
        'contested_objective',
        'coalition_conflict',
        'new_phase',
        'rank_change',
    ],

    'trigger_cooldown_seconds' => (int) env('AI_CAMPAIGN_CONSULTATION_TRIGGER_COOLDOWN_SECONDS', 3600),
    'maximum_advice_age_seconds' => (int) env('AI_CAMPAIGN_CONSULTATION_MAXIMUM_ADVICE_AGE_SECONDS', 900),

    /*
     * Hard per-day ceilings, read by the admission layer before any provider call and
     * settled by the receipt ledger after it.
     */
    'daily_limits' => [
        'universe' => ['attempts' => 50, 'input_tokens' => 100_000, 'output_tokens' => 16_000],
        'campaign' => ['attempts' => 10, 'input_tokens' => 20_000, 'output_tokens' => 3_200],
        'account' => ['attempts' => 3, 'input_tokens' => 6_000, 'output_tokens' => 960],
    ],
    'concurrency_cap' => (int) env('AI_CAMPAIGN_CONSULTATION_CONCURRENCY_CAP', 1),
    'reconciliation_minutes' => (int) env('AI_CAMPAIGN_CONSULTATION_RECONCILIATION_MINUTES', 30),
];
