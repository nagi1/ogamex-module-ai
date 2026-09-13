<?php

return [
    /*
     * Optional external cognition drivers. Every driver is disabled by default and
     * the native implementations remain the fallback, so a missing, stopped or
     * misconfigured sidecar never changes ordinary gameplay.
     *
     * One setting selects the affect and social-cognition driver pair, because both
     * contracts must share a single integrated character state.
     */
    'driver' => env('AI_COGNITION_DRIVER', 'native'),

    'circuit' => [
        // Consecutive failures before the driver is skipped entirely.
        'failures' => (int) env('AI_COGNITION_CIRCUIT_FAILURES', 3),
        // How long a tripped driver stays skipped before it is tried again.
        'cooldown_seconds' => (int) env('AI_COGNITION_CIRCUIT_COOLDOWN_SECONDS', 60),
    ],

    'payload' => [
        // The largest response body the module will read from any optional driver. The
        // module owns this bound rather than each driver, because a driver must not be
        // able to enlarge the input surface the module agreed to accept.
        'maximum_response_bytes' => (int) env('AI_COGNITION_MAXIMUM_RESPONSE_BYTES', 262_144),
    ],

    'memory' => [
        // Recall is a swap point, so the long-term memory implementation is chosen by
        // configuration rather than a fixed binding. Native scoped recall is the only
        // implementation today: an external engine is adopted by adding a case to
        // AiMemoryDriver and an arm to LongTermMemorySelector. The module's facts table
        // stays authoritative whichever driver answers.
        'driver' => env('AI_MEMORY_DRIVER', 'native'),
    ],

    'experience' => [
        'driver' => env('AI_EXPERIENCE_DRIVER', 'native'),
        // How far a finalized, matching outcome may move a decision score. Setting this
        // to zero disables the enrichment without deleting recorded evidence, which is
        // the baseline an ablation compares an enabled configuration against.
        'decision_weight' => (int) env('AI_EXPERIENCE_DECISION_WEIGHT', 20),
        'cbrkit' => [
            'base_url' => env('AI_EXPERIENCE_CBRKIT_URL', 'http://host.docker.internal:8091'),
            'connect_timeout_seconds' => (int) env('AI_EXPERIENCE_CBRKIT_CONNECT_TIMEOUT_SECONDS', 2),
            'timeout_seconds' => (int) env('AI_EXPERIENCE_CBRKIT_TIMEOUT_SECONDS', 5),
            // Bounds the casebase sent per request. The module, not the driver, decides
            // how much evidence a ranking may consider.
            'maximum_cases' => (int) env('AI_EXPERIENCE_CBRKIT_MAXIMUM_CASES', 200),
        ],
    ],

    'fatima' => [
        'base_url' => env('AI_COGNITION_FATIMA_URL', 'http://host.docker.internal:8092'),
        'connect_timeout_seconds' => (int) env('AI_COGNITION_FATIMA_CONNECT_TIMEOUT_SECONDS', 2),
        'timeout_seconds' => (int) env('AI_COGNITION_FATIMA_TIMEOUT_SECONDS', 5),
        // Serialises driver interactions, because the sidecar answers one request at a
        // time and a belief write must not interleave with another appraisal.
        'lock_seconds' => (int) env('AI_COGNITION_FATIMA_LOCK_SECONDS', 10),
        // The module re-sends this authored scenario before every appraisal, which both
        // resets the driver's emotional state and keeps the module authoritative.
        'scenario' => env('AI_COGNITION_FATIMA_SCENARIO', 'OgameCognition'),
        // Optional override for where the module's authored scenario lives. Leave it unset to
        // use the fixture the module ships; a blank value is treated as unset.
        'scenario_path' => env('AI_COGNITION_FATIMA_SCENARIO_PATH'),
        'instance' => (int) env('AI_COGNITION_FATIMA_INSTANCE', 1),
        // The counterparty the stimulus events are addressed to. The social driver
        // instead addresses a real counterparty, named after its player id.
        'counterparty' => env('AI_COGNITION_FATIMA_COUNTERPARTY', 'Other'),
        'exchange' => env('AI_COGNITION_FATIMA_EXCHANGE', 'CooperativeMove'),
        // The driver's intensities already land on the module's own 0..1 scale for the
        // authored rules, so the module clamps rather than rescales. No numeric
        // equivalence with the native engine is claimed.
        'intensity_ceiling' => (float) env('AI_COGNITION_FATIMA_INTENSITY_CEILING', 1.0),
        // CiF gates an exchange on one rapport value, so trust and affinity are reduced
        // to a single figure on the driver's scale.
        'rapport_scale' => (float) env('AI_COGNITION_FATIMA_RAPPORT_SCALE', 10.0),
    ],
];
