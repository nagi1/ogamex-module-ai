<?php

return [
    /*
     * Admission limits for a disclosed pilot.
     *
     * Every cap is checked before work is handed to a queue, and the reason a pass stopped is
     * recorded in `ai_stop_counters`, because a population that quietly stopped working looks
     * exactly like a population with nothing to do. A size cap of zero means the cap is not
     * enforced; the action cap has no such exemption, because zero there is a real setting:
     * the accounts keep planning and touch nothing.
     */
    'profile_cap' => (int) env('AI_POPULATION_PROFILE_CAP', 0),
    'active_session_cap' => (int) env('AI_POPULATION_ACTIVE_SESSION_CAP', 0),
    'dispatch_batch_size' => (int) env('AI_POPULATION_DISPATCH_BATCH_SIZE', 100),
    'session_action_cap' => (int) env('AI_POPULATION_SESSION_ACTION_CAP', 1),
    // Zero preserves each persona's waking-day cadence; a positive value is an explicit
    // accelerated-run control that still uses the normal session, planner and executor paths.
    'session_interval_seconds' => (int) env('AI_POPULATION_SESSION_INTERVAL_SECONDS', 0),
];
