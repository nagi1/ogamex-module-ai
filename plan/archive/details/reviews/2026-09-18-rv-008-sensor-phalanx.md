# Review record — RV-008 sensor phalanx (18 September 2026)

Cheap, bounded, machine-parsable record per the [improvement loop](../specs/improvement-loop.md).

```json
{
  "window": "rv-008-sensor-phalanx",
  "date": "2026-09-18",
  "artifacts": {
    "chain": "app/Domain/Decision/FacilityChain.php",
    "planner": "app/Domain/Decision/QueueablePhalanxPlanner.php",
    "action": "app/Actions/QueueAiPhalanxAction.php",
    "model": "app/Models/AiPhalanxScan.php",
    "raid_guard": "app/Domain/Decision/RaidPlanner.php",
    "test": "tests/Feature/SensorPhalanxTest.php"
  },
  "counters": {
    "tests": 5,
    "passed": 5,
    "assertions": 11,
    "gate2_findings": 0,
    "provider_calls": 0,
    "scan_ttl_hours": 2
  },
  "findings": [
    "the phalanx is built through the existing building queue: the moon-station chain step is module taste, and the lunar_base prerequisite comes from the host's recursive requirement graph",
    "a scan is a candidate action of its own work item, executed through the host's PhalanxService whose canScanTarget is respected exactly as the only legality gate",
    "the scan persists only the incoming-ship count; raw fleet data stays in the host's own mission rows",
    "the raid decision refuses a target whose recent scan saw incoming ships, which is the ninja warning the espionage report cannot show"
  ]
}
```

## What happened

The host's `PhalanxService` was shipped but never called. The account now builds the sensor phalanx on
an owned moon through the existing chain, scans a raid target in range before committing, and lets that
scan refuse a raid the espionage report alone would have accepted — the stationed/incoming fleet the
report cannot see.

## Verification

- `tests/Feature/SensorPhalanxTest.php` — 5 tests / 11 assertions passed (host runner; the container
  runner was unavailable due to a host-MySQL reverse-DNS regression, unrelated to this change).
- Gate 2 review — clean after adding `QueueAiPhalanx` to the deliberate-seam allowlist.
- Pint (`--test`) — clean on all changed code.
