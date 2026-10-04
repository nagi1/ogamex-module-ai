<?php

return [
    /*
     * Learned economy choices (plan/rl). Off by default: with the teacher policy and no recorder the
     * planner's own steps are used and nothing below is read.
     *
     * policy: teacher (the planner's own choice) | epsilon (the teacher, or a random legal candidate
     * with probability `epsilon`) | socket (ask the policy server at `socket`, fall back to the teacher)
     */
    'policy' => env('AI_RL_POLICY', 'teacher'),
    'epsilon' => (float) env('AI_RL_EPSILON', 0.1),
    'socket' => env('AI_RL_SOCKET', null),
    'socket_timeout_ms' => (int) env('AI_RL_SOCKET_TIMEOUT_MS', 2000),
    // Share of accounts the policy decides for, picked by a hash of the seed and the player; the rest
    // keep the teacher and are recorded as such.
    'learner_share' => (float) env('AI_RL_LEARNER_SHARE', 1.0),
    'seed' => (int) env('AI_RL_SEED', 0),
    // A JSON Lines file every choice point is appended to ("{pid}" is replaced by the process id).
    'record' => env('AI_RL_RECORD', null),
];
