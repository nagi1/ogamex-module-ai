<?php

return [
    /*
     * The review loop reads records the module already keeps: decision traces, work items,
     * receipts and stop counters. This switch governs the one thing the review adds — an hourly
     * sample of each account's public score, because the host stores current points and no
     * history, so the growth curve only exists if the module samples it.
     *
     * Default on, because reading the results is what makes a pilot evidence, and the read itself
     * costs nothing. Turning it off stops the sampling and nothing else: the records the operator
     * page and the pilot report are built on stay written, so a staff member can still diagnose a
     * quiet population with the collection switched off.
     */
    'enabled' => env('AI_REVIEW_ENABLED', true),
];
