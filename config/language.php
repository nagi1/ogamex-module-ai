<?php

return [
    'enabled' => env('AI_LANGUAGE_ENABLED', true),
    // Opt-in: an AI replying to another AI may reach the provider. Off (default), an
    // AI counterparty keeps the authored zero-prompt path, so an all-AI universe spends
    // nothing unless the operator turns this on. Daily limits and the monthly wall still apply.
    'ai_to_ai' => (bool) env('AI_LANGUAGE_AI_TO_AI', false),
    // The model words the account's own lines (alliance politics, calls, pleas, refusals) from the facts of the situation;
    // the authored line stays the fallback and the decision is never the model's. Off (default) for tests and installs
    // that have not chosen to spend; the sim scripts and the deployment turn it on.
    'compose' => (bool) env('AI_LANGUAGE_COMPOSE', false),
    'compose_limits' => [
        'universe' => ['attempts' => (int) env('AI_LANGUAGE_COMPOSE_UNIVERSE_ATTEMPTS', 4_000), 'input_tokens' => 4_000_000, 'output_tokens' => 400_000],
        'player' => ['attempts' => (int) env('AI_LANGUAGE_COMPOSE_PLAYER_ATTEMPTS', 400), 'input_tokens' => 400_000, 'output_tokens' => 40_000],
        'conversation' => ['attempts' => 40, 'input_tokens' => 40_000, 'output_tokens' => 4_000],
    ],
    'provider' => env('AI_LANGUAGE_PROVIDER', 'deepseek'),
    'model' => env('AI_LANGUAGE_MODEL', 'deepseek-flash'),
    'timeout_seconds' => (int) env('AI_LANGUAGE_TIMEOUT_SECONDS', 20),
    // A timed-out provider call is charged at its reserved maximum once this window
    // passes, because its remote completion can no longer be observed.
    'reconciliation_minutes' => (int) env('AI_LANGUAGE_RECONCILIATION_MINUTES', 30),
    'context_characters' => (int) env('AI_LANGUAGE_CONTEXT_CHARACTERS', 8_000),
    'maximum_reply_characters' => (int) env('AI_LANGUAGE_MAXIMUM_REPLY_CHARACTERS', 1_200),
    'maximum_input_tokens' => (int) env('AI_LANGUAGE_MAXIMUM_INPUT_TOKENS', 2_000),
    'maximum_output_tokens' => (int) env('AI_LANGUAGE_MAXIMUM_OUTPUT_TOKENS', 320),
    'universe_scope' => env('AI_LANGUAGE_UNIVERSE_SCOPE', 'default'),
    'daily_limits' => [
        'universe' => ['attempts' => 500, 'input_tokens' => 1_000_000, 'output_tokens' => 160_000],
        'player' => ['attempts' => 10, 'input_tokens' => 20_000, 'output_tokens' => 3_200],
        'conversation' => ['attempts' => 3, 'input_tokens' => 6_000, 'output_tokens' => 960],
    ],
];
