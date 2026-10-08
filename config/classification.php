<?php

// Jev (the TypeSafe classifier) through laravel/ai's Classification (plan/details/research/llm-classifier-everywhere.md).
// It reads the meaning of text a human wrote or the account is about to send; the module still decides. Off by
// default: a world replay with it off is identical, and every reader falls back to the authored rules.
return [
    'enabled' => (bool) env('AI_CLASSIFICATION_ENABLED', false),
    'provider' => env('AI_CLASSIFICATION_PROVIDER', 'typesafe'),
    // Null takes the provider's default classification model (jev-latest).
    'model' => env('AI_CLASSIFICATION_MODEL'),
    'timeout_seconds' => (int) env('AI_CLASSIFICATION_TIMEOUT_SECONDS', 10),
    // Fresh readings per day across the universe; a cached text costs nothing and is not counted.
    'daily_readings' => (int) env('AI_CLASSIFICATION_DAILY_READINGS', 5_000),
    // After a provider failure the readers stay on the rules this long rather than paying a timeout per message.
    'failure_pause_minutes' => (int) env('AI_CLASSIFICATION_FAILURE_PAUSE_MINUTES', 5),
    // Small models distilled from Jev's readings (rl/ogrl/distil_text.py), one JSON file per purpose and question. A
    // reading every question of which a model answers this surely is made in process, without a call.
    'distilled_path' => env('AI_CLASSIFICATION_DISTILLED_PATH', dirname(__DIR__) . '/resources/models/text'),
    'distilled_confidence' => (float) env('AI_CLASSIFICATION_DISTILLED_CONFIDENCE', 0.9),
];
