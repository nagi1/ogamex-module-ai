<?php

return [
    /*
     * Optional external cognition drivers. Every driver is disabled by default and
     * the native implementations remain the fallback, so a missing, stopped or
     * misconfigured sidecar never changes ordinary gameplay.
     */
    'circuit' => [
        // Consecutive failures before the driver is skipped entirely.
        'failures' => (int) env('AI_COGNITION_CIRCUIT_FAILURES', 3),
        // How long a tripped driver stays skipped before it is tried again.
        'cooldown_seconds' => (int) env('AI_COGNITION_CIRCUIT_COOLDOWN_SECONDS', 60),
    ],

    'experience' => [
        'driver' => env('AI_EXPERIENCE_DRIVER', 'native'),
        'cbrkit' => [
            'base_url' => env('AI_EXPERIENCE_CBRKIT_URL', 'http://host.docker.internal:8091'),
            'connect_timeout_seconds' => (int) env('AI_EXPERIENCE_CBRKIT_CONNECT_TIMEOUT_SECONDS', 2),
            'timeout_seconds' => (int) env('AI_EXPERIENCE_CBRKIT_TIMEOUT_SECONDS', 5),
            // Bounds the casebase sent per request. The module, not the driver, decides
            // how much evidence a ranking may consider.
            'maximum_cases' => (int) env('AI_EXPERIENCE_CBRKIT_MAXIMUM_CASES', 200),
        ],
    ],
];
