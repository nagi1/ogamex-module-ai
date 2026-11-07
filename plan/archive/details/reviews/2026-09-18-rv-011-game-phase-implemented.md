# Review record — RV-011 game-phase classification (18 September 2026)

Cheap, bounded, machine-parsable record per the [improvement loop](../specs/improvement-loop.md).

```json
{
  "window": "rv-011-game-phase",
  "date": "2026-09-18",
  "artifacts": {
    "enum": "app/Enums/GamePhase.php",
    "planner": "app/Domain/Decision/RaidPlanner.php",
    "test": "tests/Feature/RaidDepthTest.php"
  },
  "counters": {
    "tests": 4,
    "passed": 4,
    "assertions": 4,
    "gate2_findings": 0,
    "provider_calls": 0
  },
  "findings": [
    "the phase is derived from host reads only: one planet is the opening, a colony is mid, astrophysics 23 is late",
    "the named consumer is RaidPlanner target-class escalation: inactives in every phase, actives once colonised, active fleets only at astrophysics 23",
    "inactivity is the host's own rule (PlayerService::isInactive, seven days since last login) read fresh at decision time",
    "a report with no known owner is refused, never raided"
  ]
}
```

## What happened

The research (`2026-09-18-rv-011-game-phase-research.md`) named the strongest phase-dependent
behaviours and recommended `RaidPlanner` target-class escalation as the one consumer. That consumer was
built: `GamePhase` classifies the account from its astrophysics level and planet count, and
`RaidPlanner::targetEligible()` refuses a raid whose target class the phase has not unlocked.

## Verification

- `tests/Feature/RaidDepthTest.php` — the 4 new phase tests pass; the full file is 31 tests / 54
  assertions green (run in the grand app container against `ogamex-test`, host PHP lacks FFI).
- `bash scripts/ogamex gate` — 0 findings.
- Pint on the touched files — passed.
- Module PHPStan (level 8, scoped to the changed files) — no new findings; two pre-existing
  `counterScore()` list-type errors in `launchUnits()` are untouched and out of scope.
