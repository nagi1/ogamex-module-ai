<?php

return [
    /*
     * The campaign consultation lane is fail-closed: `off` by default, so a module
     * update never starts sending campaign facts to a provider. `observe` records a
     * validated recommendation without applying it; `advice` may apply a
     * profile-bounded ranking adjustment. This is operator policy, not a game rule.
     */
    'mode' => env('AI_CAMPAIGN_CONSULTATION_MODE', 'off'),

    'provider' => env('AI_CAMPAIGN_CONSULTATION_PROVIDER', 'deepseek'),
    'model' => env('AI_CAMPAIGN_CONSULTATION_MODEL', 'deepseek-flash'),
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
        'new_colony',
        'rank_change',
    ],

    'trigger_cooldown_seconds' => (int) env('AI_CAMPAIGN_CONSULTATION_TRIGGER_COOLDOWN_SECONDS', 3600),
    'maximum_advice_age_seconds' => (int) env('AI_CAMPAIGN_CONSULTATION_MAXIMUM_ADVICE_AGE_SECONDS', 900),

    /*
     * The self-reported confidence a recommendation must reach before it may move a ranking.
     * The model's score is not calibrated, so it is a fail-safe only: below this floor (or when
     * it is absent) the deterministic native decision stands rather than being nudged.
     */
    'minimum_confidence' => (float) env('AI_CAMPAIGN_CONSULTATION_MINIMUM_CONFIDENCE', 0.5),

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

    /*
     * The advisor: DeepSeek Flash is consulted at a few big moments and only chooses among the legal
     * options the rules computed (with their forecasts), or "none" to keep the rule's choice. Each
     * area has its own mode, off | observe | advice, and every one is off by default. `observe`
     * records the advice beside the rule's choice; `advice` applies it. Any timeout, failure, invalid
     * answer or cap keeps the rule's choice. Area 1 (offense) rides the campaign lane above.
     */
    'advisor' => [
        'modes' => [
            'offense' => env('AI_ADVISOR_OFFENSE_MODE', 'off'),
            'diplomacy' => env('AI_ADVISOR_DIPLOMACY_MODE', 'off'),
            'conversation' => env('AI_ADVISOR_CONVERSATION_MODE', 'off'),
            'costly' => env('AI_ADVISOR_COSTLY_MODE', 'off'),
        ],
        // Area 4: an action whose cost or risk is at least this share of the account's value is consulted.
        'costly_share' => (float) env('AI_ADVISOR_COSTLY_SHARE', 0.3),
        'timeout_seconds' => (int) env('AI_ADVISOR_TIMEOUT_SECONDS', 20),
        'maximum_input_tokens' => (int) env('AI_ADVISOR_MAXIMUM_INPUT_TOKENS', 3_000),
        'maximum_output_tokens' => (int) env('AI_ADVISOR_MAXIMUM_OUTPUT_TOKENS', 400),
        'maximum_reason_characters' => 200,
        'maximum_text_characters' => 300,
        'daily_limits' => [
            'universe' => ['attempts' => (int) env('AI_ADVISOR_UNIVERSE_DAILY_CALLS', 400), 'input_tokens' => 1_200_000, 'output_tokens' => 160_000],
            'account' => ['attempts' => (int) env('AI_ADVISOR_ACCOUNT_DAILY_CALLS', 12), 'input_tokens' => 36_000, 'output_tokens' => 4_800],
        ],
    ],
];
