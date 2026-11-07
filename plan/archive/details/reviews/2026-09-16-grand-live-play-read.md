# Review record — grand-test live play read (16 September 2026, midday)

Read-only pass over the running grand universe (`ogamex-grand`, 10 AI accounts, ~31 h at 1000×
economy/research/fleet, `AI_POPULATION_SESSION_INTERVAL_SECONDS=5`). No code, no config and no row
was changed. Every figure comes from the module's own artifacts (`ai_work_items`,
`ai_action_receipts`, `ai_decision_traces`, `ai_score_samples`), the host's own tables/services
(`planets`, `fleet_missions`, `building_queues`, `PlayerService::getFleetSlotsMax()`,
`PlanetService::metalStorage()`), or the module's own commands (`ai:pilot-report`). Zero provider
calls: `language tokens 0`.

Deployment: module `dc3f597`+ (unchanged this pass, no restart), host `98e148d7`, stack
`local-docker-dev/docker-compose.grand.yml`, all three containers up, Horizon running, `failed_jobs`
**0** (the 470 from 15 Sep are fixed and have not recurred).

## Counters

```json
{
  "window": "pilot-report --days=3",
  "work": {"created": 22996, "completed": 22239, "retried": 1124, "stuck": 0},
  "actions": {"accepted": 8918, "rejected": 1765, "processing": 1},
  "lateness_minutes": {"p50": 0.93, "p95": 3.13},
  "language_tokens": 0,
  "score": {"accounts": 10, "samples": 160, "general_delta": {"min": 3427, "median": 28103, "max": 106135}, "zero_growth_accounts": 0, "military_lost": 58},
  "read_cost_ms": 988,
  "live_throughput": "198 work items in the last 15 min; sessions ~40/h/account (harness interval, worker-bound)",
  "growth_14h": {"best": "p17 +227,241 (rank 2 -> 1)", "worst": "p20 +0 (rank 6 -> 10)"}
}
```

## Capability coverage (work items ever created, whole run)

| Kind | Created | Last |
| --- | --- | --- |
| BuildFirstBuilding | 4890 | current |
| RunSession | 12176 | current |
| QueueResearch | 1025 | current |
| QueueUnits | 2601 | current |
| Colonize | 1457 | current |
| Spy | 422 | current |
| Raid | 345 | **2026-09-15 19:45** |
| Expedition | 145 | current |
| Transfer | 14 | current |
| **FleetSave** | **0** | **never** |
| **Recall** | **0** | **never** |

Candidates offered over all traces: Colonize 4066 · Expedition 3246 · Transfer 1622 · Raid 578 ·
**FleetSave 2** · **Recall 0**.

## Findings

**L1 (new, biggest waste). Fleet slots are 1 on eight accounts, and no dispatch planner but the save
path ever asks.** Live `getFleetSlotsMax()`: p12 1, p15 1, p16 1, p20 1, p21 1 (computer_technology 0);
p13/p14/p18/p19 3 (class bonus, computer_technology 0); p17 10 (computer_technology 9 — the only
account that ever researched it). Fleet dispatch refusals in 24 h, by account: p16 434, p20 292,
p12 188, p15 57, p21 53, p18 3 — **1027 receipts, 917 of them `CreateColony`, all
"Maximum number of fleets reached."** p17 is at zero. Only `QueueableFleetSavePlanner` reads
`getFleetSlotsMax()` (and only for the V8 split), so every other dispatch is published and refused by
the host. Gate 3 view: a professional player researches computer technology for exactly this reason;
the research ladder never picks it for nine of ten accounts.

**L2 (new). The raid pipeline is dead.** `RaidPlanner::plan()` is driven by an `EspionageReport`, and
espionage reports fell from 34–56/h (15 Sep 13:00–15:00) to ~1/h after 15 Sep 18:00. Raid was last
offered 15 Sep 19:45 and last selected then; in 400 recent fleeter (p14/p19) sessions Raid is not
among the candidates at all (offered: DoNothing 400, Build 340, QueueUnits 299, Expedition 115,
Research 60, Colonize 44, Spy 8). Every refused colony/spy dispatch (L1) is a missing report later.

**L3 (regression vs the 00:53 record). The fleeter plays like a miner.** p14/p19 last 400 sessions:
QueueUnits 296, Build 37, Research 36, Colonize 18, Spy 8, Expedition 5, **Raid 0**. The earlier
verdict ("matches the documented model in every row", Fleeter Raid 37.1 %) no longer holds; the
`archetype_preference 15.00` QueueUnits ranking that caused F3 now has nothing competing with it.

**L4 (persists, F4). A warehouse that is already full is refused a warehouse, and the mine that
should spend it may not be available.** `EconomyUpgrades::storage()` skips `hours <= 0.0` by design
("a warehouse that is already full is a spend signal"), and `production()` is bounded by the payback
horizon. Live: four homeworlds sit at exactly 100 % storage — p12 33,005,000/33,005,000, p15
60,510,000/60,510,000, p16 18,005,000/18,005,000, p20 33,005,000/33,005,000 (p20's deuterium is above
cap) — and `QueueableBuildingPlanner::plan(20)` returns a *research* step, no build at all, so the
metal produced (8.9 M/h) is discarded every hour. This is the E3/E6 collision the 15 Sep W7 attempt
found; it is now measurable on four accounts instead of one.

**L5 (new). p20 has been frozen for 14 h: colonize outranks everything, every session.** Last 6 h:
174 sessions, 175 Colonize candidates, 168 colonize work items, **zero other decisions**. 112 of them
refused at the fleet cap, 56 accepted. Score +0 in 14 h (30,503, rank 6 → 10). It now holds two
colonies founded 15 Sep 21:29/21:33 with `metal_max = 0`, production 0 and mines 0/0/0 — the host's
own `PlanetService` reports `metalStorage() = 0`, and `time_last_update` equals their creation time,
so their stats have never been materialised since settlement. Colonize scores ~49.6 against research
39.6 / units 40.1 / expedition 38.7, so the trader buys a hull it cannot dispatch instead of
developing what it owns.

**L6 (observability, open). The hourly growth sample silently skipped 14 hours.** `ai_score_samples`
has hourly buckets 15 Sep 07:00 → 22:00, then nothing until 16 Sep 12:00 (written by hand this pass)
and 13:00 (written by the scheduler itself). The cohort produced 825–1047 work items in *every* one of
those hours and the scheduler container ticks `ai:run-due-work` every 10 s, so neither the run nor the
host was down. A manual run completes in ~1 s. Cause unidentified; the schedule discards its output
(`> /dev/null 2>&1`), so there is no evidence trail.

**L7 (observability, open). Stop counters have never been written.** `ai_stop_counters` is empty after
31 h, while 470 sessions chose DoNothing and every one of their traces carries exactly one candidate
whose reason is `always_available` — the artifact cannot say why nothing else was available. The
"why was the population quiet" surface the review loop depends on has produced no row.

**L8 (closed since the last record).** F1 (stranded leases) holds: `stuck 0`, `failed_jobs 0`. F2
(field gate) holds: "Not enough fields" fell from 236/24 h pre-fix to 15 in the last 12 h. IMPL-023
holds: 10 of 10 accounts now hold astrophysics 1–6 and 14 colonies exist.

## Gaps this read could not answer from existing artifacts

- Why `FleetSave` was offered only twice and `Recall` never (L-extra): no artifact records why a
  capability produced no candidate — the same gap as L7.
- Which of L1/L4/L5 is worth fixing first (all three are "the decision core ignores a host ceiling").
- `queue_not_created` (module-side reason, 177 receipts/24 h) has no stated cause in the receipt.

## Suggested next step (not started)

L1 and L4 are both "read the host's own ceiling before publishing", both are provable on a frozen
clock, and both are one predicate in the planner that already decides. L2/L3/L5 look like one ranking
question (what outranks what when a dispatch cannot fly). Per §9 of the grand-test plan this needs a
named algorithm in `gameplay-algorithms.md` and a frozen-clock before/after before anything touches
the holy universe. The holy DB was not touched by this read.
