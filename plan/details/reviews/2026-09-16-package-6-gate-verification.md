# Package 6 gate verification — 16 September 2026 (REV-002)

Scope: the whole uncommitted module batch on top of `bf064ac` — the Package 6 cooperative-PvE slices
plus the Wave-8 ordinary-play fixes and 6B — paired with the host half now committed as `30b50cef`
(hostility) and `d65ada1e` (fleet-ceiling calculation lookup, R11).

## Gates (module-local)

| Gate | Result |
| --- | --- |
| Gate 2 review (`ogamex gate`) | clean — 13 deliberate seams `[allowed]`, no must-fix |
| Pint (13 changed files) | clean |
| Module PHPStan (`phpstan.neon`, level 8) | 0 errors |
| Pest | 789 tests / 2535 assertions, green |
| PCOV (`Modules/AI/app`, excluding `app/Rules`) | 6431/6431 = 100.00% |

## Slices verified in this pass

- **Package 6 board** (IMPL-026…034) — campaign records, objective resolution, campaign director,
  reward allocator guard, `CooperativeHostilityPolicy` registration, 6C operator control, 6A
  consultation lane (transport, brief, receipts, profile-bounded ranking adjustment; off by default).
- **6B** (IMPL-031) — opt-in, profile-weighted affect appetite in `UtilityScorer`, bounded by
  `ai.cognition.affect.decision_weight` (default 0); conformance record at
  `specs/driver-utilisation-conformance.md`.
- **Wave-8 fixes** (IMPL-035…040) — dispatch ceiling, full-storage spend, colonise eligibility, probe
  budget, quiet reason; IMPL-041 diagnosed (no code change).

## Defects closed while re-running the gates

- `PlayerObservationService::canDevelopColony()` missing `ObjectService` import (runtime
  `Class not found`).
- 17 module-PHPStan errors (nullable planet/player services, missing iterable generics, unhandled
  `match`, undefined `schedule_generation` property) — all fixed at the root, no baselines.
- Test fixtures gating off every fleet/colony candidate since IMPL-035/037; replay scenario missing
  the `fleet_slots_free`/`colonize_eligible` observation fields.
