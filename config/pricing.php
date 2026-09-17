<?php

return [
    /*
     * What a settled provider call cost, in US dollars per 1M tokens, off-peak.
     *
     * A rate is keyed by `provider.model`. A request whose provider/model pair has no rate
     * fails closed: no cost is claimed rather than a wrong one. Peak hours bill at
     * `peak_multiplier` times the off-peak rate, decided by the vendor windows already
     * defined in `config/routing.php`; a vendor with no window is never peak, so the
     * multiplier only bites vendors that actually publish one.
     *
     * Rates are configuration, not code: a vendor repricing is a config diff, and a new
     * model is a new key rather than a code change. Reasoning tokens are billed output and
     * belong in the `output` column.
     */
    'currency' => 'usd',

    'peak_multiplier' => 2.0,

    'rates' => [
        // DeepSeek (api-docs.deepseek.com, 16 Sep 2026). `deepseek-flash` is DeepSeek-V4.1-Flash.
        'deepseek.deepseek-flash' => [
            'input' => 0.15,
            'cached_input' => 0.003,
            'output' => 0.60,
        ],
        'deepseek.deepseek-v4-pro' => [
            'input' => 0.66,
            'cached_input' => 0.022,
            'output' => 1.98,
        ],
        // OpenAI (openai.com/api/pricing, 16 Sep 2026). Luna is GPT-5.6 Luna.
        'openai.gpt-5.6-luna' => [
            'input' => 0.20,
            'cached_input' => 0.02,
            'output' => 1.20,
        ],
    ],
];
