# Review record — grand-test live verification (16 September 2026)

One live pass over the running grand universe (`ogamex-grand`, 10 AI accounts, ~16 h at 1000×
economy/research/fleet). Read-only except the two deploys: every figure below comes from the
module's own artifacts (`ai_work_items`, `ai_action_receipts`, `ai_decision_traces`,
`ai_score_samples`, the host's `highscores`) or from the module's own commands
(`ai:pilot-report`, `ai:explain-decision`, `ai:record-score-samples`). No provider call was made:
the pilot report reads `language tokens 0`.

```json
{
  "window": "grand-live-verification",
  "date": "2026-09-16",
  "deployment": {
    "module": "dc3f597",
    "host": "98e148d7",
    "stack": "local-docker-dev/docker-compose.grand.yml (project ogamex-grand, DB ogamex-grand)",
    "restarts": 2,
    "horizon": "running, supervisor-ai holding 6 workers; no new failed job since 19:40:22Z",
    "note": "the first restart deployed 51f3ca4; the second deployed dc3f597 after the fixes below"
  },
  "counters": {
    "profiles_enabled": 10,
    "work_created": 9010,
    "work_completed": 8254,
    "work_retried": 1123,
    "work_stuck": 0,
    "actions_accepted": 3528,
    "actions_rejected": 365,
    "actions_processing": 1,
    "lateness_p50_minutes": 0.98,
    "lateness_p95_minutes": 3.47,
    "language_tokens": 0,
    "pilot_read_cost": {"milliseconds": 3274.9, "queries": 9},
    "score_samples": 150,
    "score_general_delta": {"min": 3222, "median": 25352, "max": 97978},
    "largest_hourly_jump": 21473,
    "zero_growth_accounts": 0,
    "host_general_spread": {"lowest": 3226, "highest": 97978, "ratio": "30x"},
    "host_general_ranks_held": [2, 3, 4, 5, 6, 7, 8, 9, 12, 13]
  },
  "failed_jobs_forensics": {
    "total": 470,
    "newest": "2026-09-15 19:40:22Z",
    "classes": [
      {"exception": "ErrorException: foreach() argument must be of type array|object, null given", "count": 467, "range": "14:38:28Z–19:40:22Z", "cause": "EspionageReport.defense is nullable in the host; QueueableUnitPlanner::observedDefendedTarget iterated it unguarded", "status": "fixed in 7624ec5, no recurrence after the 21:02Z restart"},
      {"exception": "Illuminate\\Queue\\TimeoutExceededException: ProcessAiWork has timed out", "count": 3, "range": "07:01:35Z–07:01:44Z", "cause": "Horizon killed the worker mid-handle; the item stayed Leased because the reclaim path is only reached when a job is delivered, and ai:run-due-work selected Pending and Retry only", "status": "fixed in dc3f597"}
    ]
  },
  "rejections_by_cause": [
    {"action": "QueueBuilding", "reason": "Not enough fields on this planet (host)", "count": 236},
    {"action": "QueueBuilding", "reason": "Maximum number of items already in queue (host)", "count": 48},
    {"action": "DispatchFleet", "reason": "Not enough units on the planet: espionage_probe (host)", "count": 32},
    {"action": "QueueUnits", "reason": "queue_not_created (module)", "count": 14},
    {"action": "DispatchFleet", "reason": "target_active_at_dispatch (module re-check)", "count": 7},
    {"action": "DispatchFleet", "reason": "Not enough resources on the planet (host)", "count": 5},
    {"action": "QueueBuilding", "reason": "shipyard_busy (module re-check)", "count": 1}
  ],
  "persona_behaviour_vs_player_model": {
    "reference": "specs/player-model.md — Miner 'mines, avoids expensive feuds'; Turtle 'security and predictable growth'; Fleeter 'fleet readiness, selective'; Trader 'deuterium/logistics, selective diplomacy'; Casual 'growth and occasional raids'",
    "observed": {
      "miner": "Build 67.1%, Research 22.1%, QueueUnits 5.2%, Spy 3.2%, Raid 0.0%",
      "turtle": "Build 54.1%, Research 25.6%, Spy 8.9%, QueueUnits 7.6%, Raid 0.0%",
      "fleeter": "Raid 37.1%, QueueUnits 21.2%, Build 19.7%, Spy 18.2%, Research 3.8%",
      "trader": "Build 62.8%, Research 24.6%, Spy 6.6%, QueueUnits 3.5%, Raid 0.0%",
      "casual": "Build 41.9%, Raid 25.0%, Research 18.2%, Spy 9.8%, QueueUnits 5.1%"
    },
    "verdict": "matches the documented model in every row. Non-attacker archetypes raid 0% of the time; the fleeter raids and probes; the casual's 25% 'occasional raids' is the documented behaviour, not a stray gate. No unprovoked attack on a non-inactive neighbour was observed."
  },
  "capability_reachability_live": {
    "impl_022_shipped": "astrophysics was 0 on all 10 accounts before this pass; capability:astrophysics work items appear from 21:02Z and 5 accounts now hold astrophysics 1–3",
    "colonisation_end_to_end": "player 13 (turtle) holds planets 20 and 30 — a colony ship was dispatched (receipt accepted 21:16:59Z) and settled; player 18 was dispatched at 21:17:09Z",
    "unblocked": ["colonise (first live colony)", "expedition (research now standing)"],
    "still_zero_astrophysics": [12, 14, 16, 19, 21],
    "fleetsave_transfer_recall": "no work item of kind FleetSave/Transfer/Recall has ever been created; the save paths have not yet been exercised live"
  },
  "findings": [
    "F1 (fixed, dc3f597): ai:run-due-work is the only producer of work claims and selected Pending and Retry alone, so a Lease left behind by a killed worker was unreachable — 3 items sat Leased 13 h while the pilot report counted them as stuck. Live proof: the scheduler's next pass, running the edited dispatcher off the bind mount, moved ids 12/13/17 to Completed at 21:08:15–21:08:54Z and stuck fell to 0.",
    "F2 (fixed, dc3f597): QueueableBuildingPlanner asked the host's planet type, queue space, requirements and affordability and not the field gate BuildingQueueService::start refuses on — 236 receipts (65% of all rejections) were the host refusing a build the planner should not have published. Three planets are at or past their cap (p12/19 155≥154, p20/27 155≥155, p16/23 143≥142), so every session there spent its one action on a refused build.",
    "F3 (open, measured): raid loot is not reinvested. p14 and p19 hold 881k and 1.82M metal against 1.5k and 1.6k crystal at mine levels 6/4/2 and 7/6/2, after 133 and 147 accepted fleet dispatches. Their sessions rank QueueUnits 55.2 over Build 40.3 on archetype_preference 15.00 vs 0.00 alone (FleeterPolicy weights QueueUnits 0.6 and Build not at all), and p14's 50 accepted QueueUnits are single cargo hulls. They are the two lowest scorers (3226, 4591) — 30x behind the leading miner.",
    "F4 (open, measured): storage sits at or past cap while production keeps running — p12 exactly full on all three resources (33.0M/33.0M, 5.36M/5.36M, 5.36M/5.36M) on a planet that is also field-full, p14 deuterium exactly full at 75k/75k, p19 deuterium 319k against a 20k warehouse. The storage pass does fire (p14 queued metal_store 15x and crystal_store 10x) but builds the warehouse whose resource is not the binding constraint, and a raid windfall is indistinguishable from ongoing production to the fill-time trigger.",
    "F5 (open, minor): 32 fleet dispatches were refused because the chosen spy origin had lost its only probe — the spy planner de-duplicates by target coordinates, not by probes already committed to a scheduled spy intent."
  ],
  "deploys": ["51f3ca4 (IMPL-023 state, 21:02Z)", "dc3f597 (F1+F2, 21:2xZ)"],
  "next": "F3 and F4 are one decision-core question — spend a windfall before warehousing it, and rank the binding constraint above the persona's habit — and need a named algorithm in gameplay-algorithms.md measured on a frozen clock before they touch the holy universe."
}
```

## What this pass changed

| Defect | Root cause | Fix | Verified |
| --- | --- | --- | --- |
| 3 stranded leases (13 h) | `ai:run-due-work` selected `Pending`/`Retry` only, so the reclaim `ProcessAiWork::isClaimable()` documents was unreachable | the pass admits an expired lease too | live: ids 12/13/17 → Completed, `stuck 0` |
| 236 field refusals | the building planner omitted the host's field gate | the same predicate now asks `getBuildingCount() < getPlanetFieldMax()` for a field-consuming object | tests: `AiCapabilityPublicationTest` field case; live pending |

Two tests were corrected rather than added: `AiAdmissionLimitTest` documented the reclaim in a comment
while asserting the old behaviour, and the `ReserveFloorTest` fixture set 210 fields on a 163-field
planet so its "accepts it" half only held while the gate was missing.
