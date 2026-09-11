<?php

return [
    'enabled' => env('AI_LANGUAGE_ENABLED', false),
    'provider' => env('AI_LANGUAGE_PROVIDER', 'openai'),
    'model' => env('AI_LANGUAGE_MODEL', 'gpt-5-mini'),
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
