<?php

use Modules\AI\Enums\AiQueueName;

/*
|--------------------------------------------------------------------------
| AI module Horizon lanes
|--------------------------------------------------------------------------
|
| Modules\AI\Support\HorizonConfiguration applies this plan to config('horizon.*')
| from the module's HorizonServiceProvider while the module is enabled, so the host
| horizon file stays generic and a disabled module leaves no AI supervisor, wait or
| queue behind.
|
| The module provider reads this file as a fallback when config('ai.horizon') is
| absent, so the lanes are still registered when the module is enabled after
| `php artisan config:cache` already wrote a cache without the module's config.
|
*/

return [
    // Register the Horizon pools. The queue names are announced to the host even when
    // this is false, so the host knows they are module-owned.
    'enabled' => env('AI_HORIZON_ENABLED', true),

    // Supervisor name => full Horizon supervisor options. Each supervisor is also
    // provisioned in every host environment using the matching ceiling in "processes".
    'supervisors' => [
        'supervisor-ai' => [
            'connection' => 'redis',
            'queue' => [AiQueueName::Ai->value],
            'balance' => env('AI_HORIZON_WORK_BALANCE', 'auto'),
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => (int) env('AI_HORIZON_WORK_MEMORY', 256),
            // ProcessAiWork owns the attempt cap; the job property wins over this.
            'tries' => 3,
            // Must clear ProcessAiWork::$timeout and stay below the redis retry_after.
            'timeout' => (int) env('AI_HORIZON_WORK_TIMEOUT', 30),
            'sleep' => (float) env('AI_HORIZON_SLEEP', 1.0),
            'nice' => 0,
        ],
        'supervisor-ai-language' => [
            'connection' => 'redis',
            'queue' => [AiQueueName::AiLanguage->value],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => (int) env('AI_HORIZON_LANGUAGE_MEMORY', 256),
            // The module reserves and settles one attempt, so the lane never retries.
            'tries' => 1,
            // Must clear ai.language.timeout_seconds plus margin.
            'timeout' => (int) env('AI_HORIZON_LANGUAGE_TIMEOUT', 60),
            'sleep' => (float) env('AI_HORIZON_SLEEP', 1.0),
            'nice' => 0,
        ],
    ],

    // Supervisor name => pool ceiling applied to every host environment.
    'processes' => [
        'supervisor-ai' => (int) env('AI_HORIZON_WORK_PROCESSES', 1),
        'supervisor-ai-language' => (int) env('AI_HORIZON_LANGUAGE_PROCESSES', 1),
    ],

    // Queue name => seconds before Horizon reports a long wait. The connection prefix
    // is added when the plan is applied.
    'waits' => [
        AiQueueName::Ai->value => 120,
        // Language replies are delayed by design and coalesced.
        AiQueueName::AiLanguage->value => 180,
    ],
];
