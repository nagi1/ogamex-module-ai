# Review record — cohort measurement limits (18 September 2026)

Cheap, bounded, machine-parsable record per the [improvement loop](../specs/improvement-loop.md).

```json
{
  "window": "disc-010-011-cohort-limits",
  "date": "2026-09-18",
  "decisions": [
    {
      "task": "DISC-010",
      "finding": "at fleet_speed 1000 an inbound attack averages 5.7s, so the reactive fleetsave window is almost never sampled in the accelerated cohorts",
      "decision": "record the limitation as permanent for the accelerated cohort; the save-failure metric is only measurable in a 1x universe",
      "code_change": false
    },
    {
      "task": "DISC-011",
      "finding": "ogamex-pve has 19 enabled AI profiles against 1 non-faction account, so the four coalition-side consultation triggers never fire (454 battle reports, 0 fleet-loss signals)",
      "decision": "accept dormancy — the triggers are correctly scoped to a non-faction counterparty and fire when a real non-faction population exists",
      "code_change": false
    }
  ]
}
```

## What happened

Two cohort-level measurement limits were confirmed and recorded rather than patched around.

**DISC-010 — fleet save is unobservable at 1000x speed.** The reactive fleetsave candidate needs an
inbound non-espionage attack, which at `fleet_speed = 1000` lasts ~5.7 seconds and is almost never
caught by a session sample; the proactive path needs an absence above two hours while the harness
session interval is seconds. The planner itself is healthy (run directly against eight live grand
accounts it returned a save for every one, fleet values 12.6M–127.5M against 5k–50k exposure bands).
So this is a measurement limit of the accelerated cohort, not a defect: the goal metric of whether a
save ever fails has to be taken in a 1x universe.

**DISC-011 — coalition-side campaign triggers need a non-faction account.** FleetLoss, RepeatedSetback,
ContestedObjective and CoalitionConflict are all produced by battles involving a non-faction player,
and the PVE universe holds exactly one non-faction account against 19 AI profiles. Only `new_phase` and
`rank_change` fire live, which is the expected behaviour until a real non-faction population exists.
The triggers are correctly scoped; nothing is shipped-but-silent.

## Verification

No code changed. Both findings are recorded here and in `DECISIONS.md`.
