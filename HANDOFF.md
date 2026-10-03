# Handoff: local verifier loop

The module's state of play and work order live in `plan/HANDOFF.md`. This file is the contract with the
local verifier (it runs, it reports, it fixes nothing in planner code).

## Request

Written 2026-10-03 ~12:50 UTC. Code only, none of it run in the cloud. Pull both repos first (host commit b5fb9527 changes
`tests/TestCase.php`; the module adds `ai:sim`). Rebuild is not needed beyond the usual `composer dump-autoload`.

### New: fast time. Stop waiting on wall-clock hours

Game time is now a thing the harness controls. `Modules\AI\Support\SimulatedTime` freezes/moves every Carbon flavour at once
(host `now()`, `Date::now()`, the module `AiClock`). Four tools sit on it. Use them instead of waiting two hours:

1. **`OGAMEX_RUNNER=local-docker-dev bash scripts/ogamex sim --hours=24`**
   Clones the grand database into `ogamex-sim` (pure SQL, about a minute), then plays 24 simulated hours through the real
   `ProcessAiWork`, routines, waking windows, night rest, fleet arrivals and the scheduler's maintenance commands. The clock
   jumps straight to the next instant anything is due, so a quiet night is one jump. Then it prints the scorecard and the
   `verify-cohorts` invariants (AUTH_UPTIME, LIFE_FIGHTS, NAKED_BESIDE_WALLED ...) read at the simulated instant over the same window.
   The live cohort is never touched (the command refuses any database whose name lacks "sim").
   Useful flags (all passed to `ai:sim`): `--accounts=20` plays only the first 20 accounts (fastest), `--max-wall=600`
   stops after 600 real seconds and keeps state, `SIM_KEEP=1` continues the last copy from where it stopped instead of re-cloning
   (so `SIM_KEEP=1 ... sim --hours=24` is day 2), `--keep-accelerated` keeps the 5 s session interval (default is real routines).
   Output ends with `SIM: <h> played in <s> (x<speedup>), <n> sessions, <n> other, <n> errors` and an error digest.
2. **`PROVE_SIM_HOURS=48 bash scripts/ogamex prove CODE`**: runs the test steps, then simulates 48 h on a copy and judges every
   live step (situation, invariant, aspect) on that copy over exactly those hours. One run settles a row that used to need a
   day of waiting. Without the variable `prove` behaves exactly as before.
3. **`bash scripts/ogamex clock-sweep ProcessAiWorkTest`** runs a test file with the clock frozen at UTC hours 0 3 6 ... 21
   (`OGAMEX_TEST_NOW`, honoured by the host `tests/TestCase.php`). `CLOCK-SWEEP: FAIL` names the hours it breaks at, so a test
   that only passes in the profile's waking window is found in one run instead of by luck. Pass your own hours:
   `clock-sweep ProcessAiWorkTest 2 14`. `OGAMEX_TEST_NOW` also works on any `test-one`.
4. **`AI_SIM_NOW=2026-10-05T12:00:00Z`** in front of any module script, tinker run or artisan command freezes that process at the
   instant (the module provider reads it at boot). Unset, nothing changes.

Please run, in this order, and report what each prints:
- `clock-sweep ProcessAiWorkTest` (the 12:14 failure was a time-of-day dependence; this proves the fix holds at every hour).
- `clock-sweep RunDueAiWorkTest`, `RoutineCadenceTest`, `IdleOverrideAndAntiBotCadenceTest`, `DeterministicSessionLoopTest`,
  `ProcessAiSessionTest`, `SimulatedTimeTest`. Report only the FAIL lines.
- `sim --hours=24 --accounts=20 --max-wall=900` first (smoke: does `ai:sim` run, what is the speedup, which errors in the digest), then
  `sim --hours=48` for the full cohort. Report: speedup, the per-hour session table for players 96-99 (AUTH_UPTIME), attacks launched
  per hour, LIFE_FIGHTS share, the NAKED_BESIDE_WALLED rows for player 117, and the `ai:raid-rejected:*` counters (file cache on the
  copy, read them with `AI_SIM_NOW` set to the printed SIM_NOW and `CACHE_STORE=file`).
- If `ai:sim` throws on first use, fix the cause in `app/Console/Commands/SimulateAiTime.php` (it is new and unrun) and say what you changed.

### Harness fixes from the 12:22 output (fast-time thread)
- `prove` now simulates by default under the harness runner (12 h, `HARNESS_SIM_HOURS` to change, `PROVE_SIM_HOURS=0` for the old live
  read). LIFE-001 / ALLY-001 / PERS-006 failed only on "0 events in the cohort's last hours"; the live steps now judge simulated play.
  A simulated proof skips the 45 min settle (`proofs/CODE.sim`). If `ai:sim` fails, `prove` falls back to the live cohort and says so.
- The quality read reported NAKED_BESIDE_WALLED as tracked by ALLY-001 one pass and PERS-004 the next: the dedupe matched any row that
  mentioned the name in its notes. It now prefers the row whose gap_ref, then title, carries the name.
- Please check on the first cycle: a `prove LIFE-001` log shows `--- simulating 12h` and a `SIM:` line; report the speedup.

- The hourly cohort read in `harness-live.sh` now runs `sim --hours=6 --accounts=30` first (primary verdict, the live read stays below it as
  the secondary line); `HARNESS_READ_SIM_HOURS=0` turns it off. The babysitter's steering block, which every writer and the Claude lane
  read, now says to use `sim` / `prove` / `clock-sweep` instead of waiting. Please confirm the log shows `=== simulated read` and its `SIM:` line.

### Still open from the 12:05 request (answer them through `sim`, not by waiting)
- AUTH_UPTIME: per-hour session counts for players 96-99 over the simulated window.
- LIFE_FIGHTS: attacks per hour and combat-rounds share of battles created in the simulated window.
- NAKED_BESIDE_WALLED player 117: per planet defence units, unit queue, shipyard level, stock, last 3 `QueueUnits` outcomes.

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

Cycle: 2026-10-03 13:04 UTC (INTERIM: the 24h smoke sim and the 48h sim are still running; final numbers follow in the next push)
Pull: ogamex-next b5fb9527; ogamex-module-ai 22cb3ee (cloud: fast time, hourly simulated read) with local fixes on top. composer dump-autoload done in
the dev and grand containers; grand queue worker and scheduler restarted; harness (1 writer) and babysitter restarted onto the new code.

### Request item 1: clock-sweep (all eight hours 0 3 6 ... 21)
- ProcessAiWorkTest: PASS at every hour (the 12:14 failure is gone).
- RunDueAiWorkTest, RoutineCadenceTest, IdleOverrideAndAntiBotCadenceTest, ProcessAiSessionTest, SimulatedTimeTest: PASS at every hour.
- **DeterministicSessionLoopTest: FAIL at UTC hours 0, 9, 18.** One test, "a long absence is clamped under the host inactive-deletion window"
  (tests/Feature/DeterministicSessionLoopTest.php:240): expects next_due_at = now + 2 days exactly, gets about 1.9 days less
  (1789123920 vs 1789286400 at hour 0; 1789168620 vs 1789286400 at hour 9). At the other hours it passes, so the clamp or the routine's
  wake window decides the successor time differently depending on the hour. Not fixed (scheduling logic, not mine).

### Request item 3: `ai:sim` first use. It ran, and it exposed two bugs in the tooling (fixed, tooling only)
1. **Every sim crashed after 9 to 55 simulated minutes with `Table 'ogamex-sim.ai_work_items' doesn't exist`.** Cause: three callers share
   the one default database `ogamex-sim` and each starts by re-cloning it: a manual `sim`, the harness's simulated `prove`, and the
   harness's hourly simulated read. They dropped each other's tables mid-run. Fix: `prove` uses `ogamex-sim-prove` (scripts/ogamex
   line 348), the harness read uses `ogamex-sim-read` (scripts/harness-live.sh line 175), and a manual run sets `SIM_DB=ogamex-sim-verify`.
   Give every new caller its own `SIM_DB`; the default is a trap.
2. Nothing else threw; the clone takes about 40 s.

### Speedup (this is the finding to act on)
Measured on the 20-account smoke run: the simulated clock advanced 18 minutes in about 4.5 real minutes, so **about x4, not the
orders of magnitude the design assumes**. 24 simulated hours would take about 6 real hours and 48 hours about half a day. At that rate
the harness's per-proof 12 h simulation and per-hour 6 h simulated read are each ~10+ minutes of silence. Where the time goes is the
first thing to profile (per-jump cost of ProcessAiWork, the Rust battle engine, the scheduler maintenance commands each jump).
Until it is faster, lower `HARNESS_SIM_HOURS` and `HARNESS_READ_SIM_HOURS`, or the writer idles behind its own proofs.

### Harness fixes made this cycle (all committed)
- Writer call budget back to 250 (it had been set to 80): past it a writer hands the row to Claude. A spent budget on a routine row (FAST/RULE/
  STUCK/WIK/JEV rows and test merges like QUAL-DEDUP) now goes to the owner, never to Claude. QUAL-DEDUP had been handed to Claude after 80
  calls because its proof was `invariant:NAKED_BESIDE_WALLED`, which a test merge cannot move: its proof is now
  `test:NakedBesideWalledSituationTest situation:naked-beside-walled`.
- Harness page: "stopped" now means 30 minutes without a status publish (a simulated proof or read is silent for up to ~15).
- One agent in the tree at a time; Claude lane for hard code only (see State of play below).

### Failing proofs, invariants, crashes
- Invariants (live cohort, read 12:05 UTC, before the cloud code): LIFE_FIGHTS 9%, NAKED_BESIDE_WALLED, AUTH_UPTIME. Simulated numbers not available yet.
- QUAL-DEDUP: proof was failing on NAKED_BESIDE_WALLED player 80 (planet at zero defence beside a wall of 2,330 units); proof changed as above.
- Crashes: none in the queue worker, scheduler or app logs. The only exception seen was the sim table error above.

### Still to report (next push)
`sim --hours=24 --accounts=20` summary and speedup line, per-hour sessions for players 96-99, attacks per hour, LIFE_FIGHTS share, player 117
NAKED_BESIDE_WALLED rows, `ai:raid-rejected:*` counters, the full `sim --hours=48`, and the `prove LIFE-001` log with "simulating 12h" and a SIM: line.
