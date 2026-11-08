<?php

// The model lab: an AI console tab that asks the two trained models (fleet and economy) what every AI account should do
// right now, beside what the built-in rules would do, as a dry run (rolled back, nothing queued). Off by default; the
// play universe turns it on through AI_MODEL_LAB_ENABLED.
return [
    'enabled' => (bool) env('AI_MODEL_LAB_ENABLED', false),
    // The models it asks; unset, the ones the game is configured with (AI_RL_ERRAND_MODEL, AI_RL_ECONOMY_MODEL).
    'fleet_model' => env('AI_MODEL_LAB_FLEET_MODEL'),
    'economy_model' => env('AI_MODEL_LAB_ECONOMY_MODEL'),
    // An input this many standard deviations from the training data is reported as a situation the model never saw.
    'outlier_sigma' => (float) env('AI_MODEL_LAB_OUTLIER_SIGMA', 4.0),
];
