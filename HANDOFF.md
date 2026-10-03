# Handoff: local verifier loop

The module's state of play and work order live in `plan/HANDOFF.md`. This file is the contract with the
local verifier (it runs, it reports, it fixes nothing in planner code).

## Request

Written after the 2026-10-03 12:05 UTC results. Code only, none of it run in the cloud. Pull both repos first.

Pushed to module main this cycle:
- AUTH_UPTIME: a failed session's one-minute retry (`ProcessAiWork::scheduleSessionRecovery`) now takes the routine's next wake when the account is asleep, instead of firing in the dark period.
- LIFE_FIGHTS: `CandidateActionFactory` drops empty-planet raid candidates (rejection `defended_target_preferred`) whenever a defended target also clears the raid planner, so sessions fight when they can and farm only when nothing defended is viable.

Not changed: NAKED_BESIDE_WALLED (player 117). The planner already orders a wall for every bare sibling (`standingDefenceOrders`), so I could not find a code cause from the report alone.

Please run next:
1. Let the stack run at least two hours on the new code, then re-read `verify-cohorts`. AUTH_UPTIME reads a 7-day window, so players 96-99 may stay listed until old dark-period sessions age out. Report their per-hour session counts for the last 6 hours instead.
2. LIFE_FIGHTS: report attacks launched per hour and the share of new battles with combat rounds counted from battles created after the pull, not the 24h figure.
3. NAKED_BESIDE_WALLED: for player 117 report each planet's defence units, its unit queue, its shipyard level, its metal/crystal/deuterium stock, and the last 3 `QueueUnits` outcomes for it (state and refusal text).
4. Raid rejection reasons: dump the `ai:raid-rejected:*` cache counters.

## State of play for the cloud model (2026-10-03 12:12 UTC)

Read `AGENTS.md` (three gates) first. The cohort is `local-docker-dev/docker-compose.grand.yml` (db ogamex-grand, 100 AI
accounts). Tools: `bash scripts/ogamex pulse|scorecard|stuck|account PID|why PID|economy PID|prove CODE|test-one NAME`
(prefix `OGAMEX_RUNNER=local-docker-dev`). Never run tests on the shared lane 1 DB; `test-one` picks a private lane.

### What the cohort does now
- Scorecard 15/15 aspects pass on counts, but the day is lopsided: sessions choose QueueUnits 58%, Transfer 28%,
  Raid 0% (2 attacks in 15 min). `LIFE_FIGHTS` fails: 9% of battles have combat rounds, the rest pillage empty planets.
  `NAKED_BESIDE_WALLED` and `AUTH_UPTIME` fail too.
- Open gap number one: **raids do not win the arbitration** against Transfer/QueueUnits, so no real fights, no moons,
  no death stars. `CandidateActionFactory::features` is a fixed score table; read it against `bash scripts/ogamex why PID`.
- Already fixed this session (do not redo): dispatcher batch (`ai:run-due-work` defaults to the configured batch, 400);
  proactive saves used the routine's absence instead of the real next login (accelerated cohorts parked fleets every
  session); `RaidPlanner` priced fuel for the whole stock instead of the launch fleet; transfer planner offered ferries
  whose tanks cannot hold the fuel; `QueueAiTransferAction` loads biggest holds first; Rust battle engine only in
  `NativeRaidEstimator`; alliance ceiling removed; war-fleet role (`QueueableUnitPlanner::capitalFleet`).

### The harness (what runs, who does what)
- **DeepSeek writers** (`scripts/harness-live.sh`, `strategy-pipeline.py implement`): 1 writer, 1 model call at a time, high
  reasoning, 800k context. About 90% of rows. A row has a 250-call budget (`ROW_CALL_BUDGET`): past it the writer hands the
  row to Claude with the last failure (`hand_to_lane`, note `WRITER-HANDOFF`). Tests that fail on purpose to print state
  are refused. A failing live proof step settles 45 min before the writer gets the row back (`LIVE_SETTLE_SECONDS`).
- **Claude lane** (`scripts/claude-lane.py`, Sonnet 5.5, standalone `claude` binary): hard code only, one row at a time,
  no verification work, 100 turns, 45 min. Takes: handed-over rows, rows with 400+ writer calls, rows 2+ others wait on.
  Never FAST-/RULE-/STUCK-/WIK-/JEV- rows or QUAL-DEDUP. Two unsettled runs block the row for the owner.
- **Babysitter** (`scripts/babysit.py`, `babysitter.sh`): unsticks rows held 5 min, delegates to the lane, flags reopen churn,
  commits run records at most every 6 h. One agent in the tree at a time: writers wait while a Claude run is live.
- Page: https://ogamex-next.test/ai-harness (glance cards, spend, Claude lane panel). Spend since 1 Oct: DeepSeek about
  $13 / 650M tokens; Claude lane usage in `plan/research/ogame/claude-lane/usage.jsonl`.

### Ledger (python3 plan/tasks/task.py ...)
236 done, 19 todo, 160 deferred (P3, WIK and strategy-pipeline rows are frozen by the owner; do not unfreeze).
Open P0/P1: LIFE-001 (raids fight, held by the lane), LIFE-003 (moons, needs LIFE-001), QUAL-010 (AUTH_UPTIME), QUAL-013
(LIFE_FIGHTS), ALLY-001 (alliance lane, held by the lane), and STUCK-* rows (routine, for the writers).

### Rules the owner set
No caps, quotas or forced outcomes: progress must look like a human player. No hardcoded object names. Rust engine only.
Performance later. Commit by file name with the trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`;
never `git add -A`, stash, reset, clean or force-push. No `else`/`elseif`, `app()` not `new`, Pest Feature tests, no Mockery.

## Results

Cycle: 2026-10-03 12:05 UTC
Pull: ogamex-next f8122d8d, ogamex-module-ai bdd6dff (both already up to date with origin/main; no stack rebuild needed)
Stack: harness (1 writer, 1 model call), babysitter and the claude lane (one agent in the tree at a time) running.

### Failing proofs
None: no row was delivered awaiting its proof this cycle (UNPROVEN is empty, 9 rows READY).

### Passing proofs (last 24h notes)
- PERS-002 09:01 test:PersonaDecompositionTest aspect:economy
- PERS-007 08:49 test:EveryPlanetBuildsSessionSituationTest aspect:economy invariant:IDLE_QUEUES
- FLEET-002 08:38 DebrisBesidePlanetSituationTest, DebrisRecycleSituationTest, aspect:recycle
- FLEET-003 08:39 InboundAttackSituationTest, FleetSaveBeforeBedSituationTest, aspect:fleet_save
- FAST-invariant-IDLE-QUEUES 09:00 and FAST-invariant-NAKED-BESIDE-WALLED 09:09 harness:self-check

### Crashes, exceptions, stack traces
None in the last 60 min: grand queue worker, scheduler and app logs have no exception lines; failed_jobs 0 in the last hour.

### Scorecard aspects still failing
Scorecard: 15 of 15 aspects pass (24h window).
Cohort invariants (verify-cohorts): 102 violations across 3 invariants.
- LIFE_FIGHTS: 100 of 1110 battles today had combat rounds (9%): raids pillage empty planets instead of fighting.
- NAKED_BESIDE_WALLED: accounts with a planet at zero defence beside a walled one (e.g. player 117).
- AUTH_UPTIME: many accounts (e.g. players 96-99) fail the uptime shape.
Cohort pulse, last 15 min: 1912 sessions on 100 accounts, backlog 14 late items (was 430+), sessions chose QueueUnits 57%, Transfer 29%, Raid 0%; missions launched: Transport 448, Expedition 27, Attack 4, Colonisation 3.
Raid and fight share is the open gap: attacks fell to 4 in this window.
