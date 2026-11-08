<?php

// The model lab: an operator tab in the AI console that runs the trained weight models on the live accounts' real game
// state, as a dry run (rolled back, nothing queued), and shows what each model sees, scores and picks. Off by default;
// the play universe turns it on through AI_MODEL_LAB_ENABLED.
return [
    'enabled' => (bool) env('AI_MODEL_LAB_ENABLED', false),
    // The models the lab offers, "label=path" comma separated; a relative path is read under storage/rl/ogsim/runs.
    // The default lists the deployed fleet model, its predecessor, the later ES gate, the clone it grew from and the
    // best economy model (shown with the reason it cannot run on the game's state).
    'models' => env(
        'AI_MODEL_LAB_MODELS',
        'g16 (deployed fleet)=es-late-c4/final-g16.policy.json,'
        . 'g14=es-late-c3/final-g14.policy.json,'
        . 'gate-30 (ES from g16)=es-g16-e4/es/gate-30.policy.json,'
        . 'C6 clone of the teacher=bc-late-c6/errand.policy.json,'
        . 'best-es3-s10 (economy)=train4/best-es3-s10.policy.json',
    ),
    // A feature this many standard deviations from the training mean is flagged: the model never saw such a value.
    'outlier_sigma' => (float) env('AI_MODEL_LAB_OUTLIER_SIGMA', 4.0),
];
