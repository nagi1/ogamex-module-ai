<?php

return [
    /*
     * Learned economy choices (plan/rl). Off by default: with the teacher policy and no recorder the
     * planner's own steps are used and nothing below is read.
     *
     * policy: teacher (the planner's own choice) | epsilon (the teacher, or a random legal candidate
     * with probability `epsilon`) | socket (ask the policy server at `socket`, fall back to the teacher) | model (the
     * economy model below, run in PHP; the teacher wherever it is unset)
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
    // An errand model trained in the simulator (feature version 3, ogrl.export_policy); unset keeps the engine's own errands.
    'errand_model' => env('AI_RL_ERRAND_MODEL', null),
    // An economy model trained in the simulator (`ogsim::season`, 25 state / 14 candidate columns), read by the `model` policy.
    'economy_model' => env('AI_RL_ECONOMY_MODEL', null),
    // Who picks the fleet errand: `model` (the errand model above when one is set) or `scripted` (the engine's own
    // scripted raider, whatever errand_model says). The play universe flies the scripted raider (WORK-ORDER §2).
    'fleet_errands' => env('AI_RL_FLEET_ERRANDS', 'model'),
    // Personas (archetype names, comma separated, e.g. "raider") whose fleet errands fly on the scripted raider while
    // every other account keeps the errand model: one universe, the split decided per account. Empty: nobody.
    'fleet_errands_scripted_personas' => array_values(array_filter(array_map(
        static fn (string $persona): string => strtolower(trim($persona)),
        explode(',', (string) env('AI_RL_FLEET_ERRANDS_SCRIPTED_PERSONAS', '')),
    ))),
    // The simulator's `hunt` arm: raiders probe the rivals whose fleet they can crash first (QueueableSpyPlanner).
    // Off by default: measured 7 Nov 2026 it lifted dominance but cost realism (plan/rl/STRONG-PLAYER-PLAN.md).
    'hunt' => (bool) env('AI_RL_HUNT', false),
];
