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

### Speedup fix (fast-time thread, 13:15 UTC) -- please report the new speedup
The x4 had no profile, so `ai:sim` now prints `JUMPS:` and a `PROFILE:` table (real seconds per phase: fleet arrivals, due work, each
maintenance command, next-instant queries) at the end of every run. Likely causes fixed blind: the three highscore generators ran every
15 simulated minutes and walk every player (now once per `--highscore-every=3600` s); `process-fleet-arrivals` ran on every jump (now only when
a fleet is overdue); an item the worker refuses (night claim, admission) kept the clock on one-minute jumps all night (now five minutes).
Please rerun `sim --hours=6 --accounts=20` with its own SIM_DB and report: the SIM line (speedup), the JUMPS line and the whole PROFILE table. If
one phase still dominates, say which: that is the next fix. If due work dominates, report sessions per real second; the cost is then the session itself.

### OWNER ORDER (Nagi, 2026-10-03 ~17:00 UTC): the cloud agent owns the simulator and the ledger cleanup
1. **Make `ai:sim` fast and reliable. This is yours, not the verifier's.** Rerun on the fixed build (segfault and TraderPolicy errors are gone after
   container restarts): `sim --hours=6 --accounts=20`, fresh SIM_DB, 0 errors, 181 sessions, but only **x6**: 1.5 simulated hours in 908 s wall,
   699 jumps averaging 7 simulated seconds each. The verifier's grep cut the per-phase `PROFILE:` lines, so profile it yourself and fix what dominates.
   Target: a 12 h, 100-account proof inside the 300 s wall (x150), so simulated proofs can become the default again. Do the architectural
   work (batched jumps, skipping idle gaps, fewer maintenance commands per jump, in-process sessions) without waiting for approval.
2. **You may edit the ledger directly.** Nagi gives you permission to delete or mark done any `deferred` row that is not real work: all 120 `WIK-*`,
   `PIPE-*`, `JEV-*`, `LOOP-*`, `DISC-14`, `DOC-8`, `CAMPAIGN-001`, and any row whose note says it is covered by an existing class. Edit `plan/tasks/tasks.db`
   (back it up first) and regenerate `plan/tasks/seed.sql` with `dump_seed.py`. Keep only rows that are real unfinished work. The verifier's classifier
   blocked it from doing this itself, so it did none of it.
3. Ask Nagi (via this file) only for decisions the code cannot settle; take the rest.

### Segfault and speed (fast-time thread, 14:15 UTC) -- rerun please
- Segfault: host `RustBattleEngine` called `FFI::cdef` (a fresh dlopen) for every fight, so a process that fights thousands of battles (the sim, a
  long queue worker) piled up bindings and crashed when PHP tore them down. It is now one binding per process (ogamex-next main). Restart the
  app/queue/sim containers after pulling. If a sim still segfaults, report the last line printed before the crash and whether `ai:sim --hours=0.01` (no battles) survives; that splits "FFI" from "everything else".
- `ai:sim` now aborts with `SIM ABORTED` when 300 errors pile up and no session has run, so a broken build can no longer print a fake "x40".
- Speed: no profile has come back yet. Run `sim --hours=6 --accounts=20` on a fresh `SIM_DB` and paste the `SIM:`, `JUMPS:` and `PROFILE:` lines.
  Parallel session workers are not built: one process per account shard would sit at different simulated instants over one database, so battles and
  fleet arrivals could resolve out of order. If the PROFILE shows sessions dominate, the answer is `--accounts=K` runs, not shards.

### Sim speed root cause (fast-time thread, 17:15 UTC) -- rerun please
181 sessions in 908 s = 5 s per session, and 699 tiny jumps cost far less than that: the cost is the session, and the session's cost is waiting on
HTTP. In hybrid cognition mode every session calls the Fatima, CBRKit and AgentOS sidecars (config/cognition.php, 2 s connect / 5 s read timeouts) and the
conversation lane calls the language provider. `ai:sim` now plays on the native engines by default (`ai.cognition.mode=native`, native memory and
experience drivers, conversation and language off) and `Http::preventStrayRequests()` makes any escaped request throw at once, so it shows in the error
digest instead of hanging. `--external-cognition` restores the old behaviour. Expect sessions near the speed of a test (well under a second).
Please rerun `sim --hours=6 --accounts=20` on a fresh SIM_DB and report the SIM, JUMPS and full PROFILE lines (do not grep them), then the 12 h, 100-account
run with `--max-wall=300`. Any `Attempted request to` rows in the digest name a remaining HTTP caller: paste them.

### Faster sim and tests (fast-time thread, 17:25 UTC) -- measure both
- `ai:sim` commits once per simulated instant (the whole drain is one transaction, inner ones are savepoints) instead of per statement group: fewer fsyncs.
  Watch for any new error in the digest that mentions a transaction, lock or "after commit"; if one shows, report it and run with the previous behaviour
  by removing the `DB::transaction` wrapper in `handle()`.
- `test-one`, `clock-sweep` and every `test:` proof step now run a test named by file as that file (`pest Modules/AI/tests/Feature/XTest.php`) instead of
  `--testsuite=Modules --filter=X`, which loaded and filtered the whole suite. Please time `test-one ProcessAiWorkTest` before/after (`git stash`-free: compare
  with `OGAMEX_RUNNER=local-docker-dev ... pest --testsuite=Modules --filter=ProcessAiWorkTest` by hand) and report both numbers.
- Tests stay on MySQL lanes: the schema and the code use MySQL features, so an in-memory sqlite lane would not run them. Parallelism is already
  `--parallel --processes=4` for suites; raise `PARALLEL_PROCESSES` if the machine has cores to spare (each worker clones the schema once and reuses it).

### Still open from the 12:05 request (answer them through `sim`, not by waiting)
- AUTH_UPTIME: per-hour session counts for players 96-99 over the simulated window.
- LIFE_FIGHTS: attacks per hour and combat-rounds share of battles created in the simulated window.
- NAKED_BESIDE_WALLED player 117: per planet defence units, unit queue, shipyard level, stock, last 3 `QueueUnits` outcomes.

### Cloud thread (handoff fixes), 13:10 UTC
- DeterministicSessionLoopTest "a long absence is clamped" (failed at UTC hours 0, 9, 18): the test, not the clamp. Since 5da78a5 a successor that would land in the dark period takes the routine wake, so a ten-day accelerated wait only reached the clamp when it happened to land awake. The test now aims the wait at the profile's local noon. Please re-run the clock-sweep for DeterministicSessionLoopTest.
- Speedup finding (x4): noted, not touched by this thread; it belongs to the time-control thread.

### Cloud thread, answers to the FINAL addendum
- (2) `ExecuteAiIntentAction::PAYLOAD_PLANET_ID` was missing: the relocation and trade lanes read it and would fatal on every such intent. The constant is added (module main). Pull and restart the queue worker.
- (3) TraderPolicy: nothing in the module source names it (the provider tags only Miner, Turtle, Fleeter, Raider, Hybrid), so the includes come from a process holding stale state from before ead6e2c. Run `composer dump-autoload -o`, `php artisan optimize:clear`, `php artisan queue:restart` and restart php-fpm and every long-running worker in the dev, grand and sim containers, then re-run a 1h sim.
- (4) DeterministicSessionLoopTest: fixed at 25fc305, re-run the clock-sweep.
- (1) segfault and (5) speed: time-control thread. Not touched here.
- NAKED_BESIDE_WALLED players 40, 46, 80, 117 and AUTH_UPTIME 24/24 hours for players 35+: unproven whether the 12:xx code was live when the run read them. Re-read both after the restart above, then report for one of the players: planets with defence units, yard queue, shipyard level, resources, and the last 3 `QueueUnits` outcomes.

### Cloud thread: the 160 deferred rows (Nagi asked why)
About 120 are WIK rows, annotated in an earlier sweep: roughly 60 are already covered by existing classes (each row's note names the class), about 40 are documentation-only with no numbers to build, and the missiles/moonshot ones were excluded by DISC-14. The rest are PIPE, hosted-AI (DEF-005, REV, JEV) or closed rows with a stale `deferred` label. They stay `deferred` only because `task.py` closes a row through its proof, and these have none.
Request: for each deferred row whose note says it is covered by an existing class, run `python3 plan/tasks/task.py done <ROW>` (with the proof the note names if there is one, otherwise the closest passing test), so the count drops. Report any row whose note does not hold up instead of closing it. Leave PIPE, JEV, DEF-005 and REV-8/9 deferred.

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

### FINAL addendum, 2026-10-03 16:50 local (supersedes the interim "Still to report")
Pull: module main with local commits through ead6e2c (PERS-008); all cloud commits merged.

**48h sim (`sim --hours=48 --max-wall=1200`, SIM_DB=ogamex-sim-verify): INVALID, do not read the scorecard from it.**
- It played 13.3 simulated hours in 1201 s ("x40") with **0 sessions and 50,969 errors**: 50,859 were
  `include(.../Policies/TraderPolicy.php): Failed to open stream` (commit ead6e2c deleted that class; nothing in source, config,
  vendor/composer or bootstrap/cache names it any more, and no ai_* column in the sim copy contains the string, so something loaded it
  from a stale source: suspect a PHP-FPM/CLI opcache or a pre-deletion autoload in the process that started the run) and 110 were
  `Undefined constant ExecuteAiIntentAction::PAYLOAD_PLANET_ID` (still unfixed; it also hits the live cohort). The "x40" is errors failing fast; the honest speedup is still about x5.
  Errors fell to 0 by sim 00:00 because the due backlog was exhausted, not because anything recovered.
- The scorecard it printed (15 of 15 PASS) is the clone's 48h window of **pre-existing** history, not new play. Ignore it.
- The run ended with `Segmentation fault (core dumped)` after the table. Every later sim (`sim --hours=1`, and the prove below) now segfaults
  before the first jump: `SIM: failed, no SIM_NOW printed`. Cause not found; first suspect is the PHP process after the container's code changed under it (restart the app container and re-run `composer dump-autoload`), second is the Rust battle-engine FFI.
- Live invariants after the 48h run (cohort verification at the end of that run): NAKED_BESIDE_WALLED players 40, 46, 80, 117; AUTH_UPTIME for players 35 onward (active 24 of 24 hours).

**`PROVE_SIM_HOURS=12 PROVE_SIM_WALL=300 prove LIFE-001`:** exit 1. The log shows `--- simulating 12h on a copy of the cohort`, then
`SIM: ... (12.0 h)`, `Segmentation fault`, `SIM: failed, no SIM_NOW printed`, `the live steps below judge the live cohort`. Live verdict:
QUALITY FAIL LIFE_FIGHTS NAKED_BESIDE_WALLED AUTH_UPTIME; AUTHENTICITY AUTH_UPTIME FAIL, AUTH_REPETITION / AUTH_SAVE / AUTH_CONTACT / AUTH_GROWTH PASS.

**Not obtainable** (the valid sim never ran): per-hour sessions of players 96-99, attacks per hour, LIFE_FIGHTS share inside a sim window,
`ai:raid-rejected:*` counters. Player 117 still has one planet at zero defence beside one holding 3,078 units.

**For the cloud model, in order:** (1) find and stop the sim segfault; (2) fix `PAYLOAD_PLANET_ID`; (3) find what still loads TraderPolicy;
(4) fix the DeterministicSessionLoopTest clock-sweep failure at UTC 0/9/18; (5) the simulator is x5, profile it before relying on it.
Sims stay opt-in (`HARNESS_SIM_HOURS`, `HARNESS_READ_SIM_HOURS` default 0).
