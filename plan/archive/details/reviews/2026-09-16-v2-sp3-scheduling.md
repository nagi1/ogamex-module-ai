# V2 reaction wake + SP3 next-wake — 16 September 2026 (DEF-001, IMPL-042)

The last two open scheduling gaps, closed in one pass. V2 was the deferred fleetsave follow-up
(signal 1, the only one a player can test); SP3 was the wave-6 strategy-depth gap W6-6. Both read
the host's own fleet/queue data and never name an object or a mission type (gate 1).

| Gap | Shipped as | Frozen-clock evidence | Verdict |
| --- | --- | --- | --- |
| G8 — reaction wake (V2) | `inboundThreat` applies the 120–180 s window to `fleetsave_eligible`: an inbound landing more than 180 s out withholds the save and publishes `reaction_wake_at = arrival − draw(120,180)`; below the host's 10 s detector floor the doomed save is not attempted; `SessionDecisionService` clamps the successor to the wake | `FleetSavePlannerTest` (early notice withholds + wake in the window; inside-floor not saved), `DeterministicSessionLoopTest` (the successor lands on the reaction wake) | **closed (DEF-001)** |
| W6-6 — next-wake (SP3) | `SessionDecisionService::nextMaterialEventWake` clamps the successor to the earliest build/research/fleet finish inside the waking window (`SessionPlanner::isAwake`), plus a right-skewed arrival delay; the routine session stays the upper bound | `DeterministicSessionLoopTest` (building/research/own-fleet/inbound terms all pull the wake earlier), `RoutineAndPolicyTest` (awake in window, asleep in the dark period) | **closed (IMPL-042)** |

Deferred refinements, recorded not left silent: `resource_eta`, `storage_threshold` and the
non-fleet `slot_free` terms of SP3 — each nameable and host-computable, none an open gap.

Verification: 794 Pest tests / 2552 assertions green, Pint clean, module PHPStan 0 errors, Rector
dry-run clean, Gate 2 exit 0, PCOV 100.00% (6500/6500). Rows corrected in
[`GAP-REGISTER.md`](../GAP-REGISTER.md) (G8, W6-6) and [`strategy-principles.md`](../research/strategy-principles.md)
(FS-004, AUTH-004).
