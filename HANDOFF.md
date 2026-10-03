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

### Sim keeps the sidecars (fast-time thread, 17:40 UTC; SUPERSEDES the "native by default" note) -- owner order
Nagi: the sim must call the real Fatima, CBRKit and AgentOS sidecars and the language lane. `ai:sim` is back to external cognition by default; the
native engines are an explicit opt-in (`--native-cognition`) and nothing forces them. Speed now comes from elsewhere:
- At start `ai:sim` probes the three sidecars and prints `SIDECAR <name> up (N ms)` or `SIDECAR <name> DOWN`. A sidecar that is down costs its 2 s connect
  timeout on every call, which alone would explain 5 s per session. **Please paste those three lines**; if one is down, start it (that is a 20x speedup by itself)
  and rerun before profiling anything else.
- `--workers=N` (needs pcntl) forks N processes per simulated instant; each runs a share of the due sessions at the same simulated time, so sessions overlap their
  sidecar waits the way production workers do. Try `--workers=8`. Fatima serialises its own calls (`lock_seconds`), so expect the gain from CBRKit/AgentOS/DB, not Fatima.
  If a worker dies the digest says `worker N died without a report`.
Please rerun `sim --hours=6 --accounts=20` twice, `--workers=1` and `--workers=8`, each on its own fresh SIM_DB, and report the SIDECAR lines, the SIM and JUMPS
lines and the full PROFILE of each.

### Faster sim and tests (fast-time thread, 17:25 UTC) -- measure both
- `ai:sim` commits once per simulated instant (the whole drain is one transaction, inner ones are savepoints) instead of per statement group: fewer fsyncs.
  Watch for any new error in the digest that mentions a transaction, lock or "after commit"; if one shows, report it and run with the previous behaviour
  by removing the `DB::transaction` wrapper in `handle()`.
- `test-one`, `clock-sweep` and every `test:` proof step now run a test named by file as that file (`pest Modules/AI/tests/Feature/XTest.php`) instead of
  `--testsuite=Modules --filter=X`, which loaded and filtered the whole suite. Please time `test-one ProcessAiWorkTest` before/after (`git stash`-free: compare
  with `OGAMEX_RUNNER=local-docker-dev ... pest --testsuite=Modules --filter=ProcessAiWorkTest` by hand) and report both numbers.
- Tests stay on MySQL lanes: the schema and the code use MySQL features, so an in-memory sqlite lane would not run them. Parallelism is already
  `--parallel --processes=4` for suites; raise `PARALLEL_PROCESSES` if the machine has cores to spare (each worker clones the schema once and reuses it).

### Segfault bisect (fast-time thread, 18:10 UTC) -- one run, read the last FFI-PROBE line
The sim has no fork in it unless `--workers>1`, so the fork theory does not fit a `--workers=1` crash, and the sim's clock/transaction code never touches FFI
memory (it only calls Carbon and the DB). A null GOT slot inside the lib with the same .so fine under tinker and pest says something in this process
state differs, so bisect it in-process: `sim --hours=1 --accounts=5 --ffi-probe` (a fresh SIM_DB). It calls the shared binding with an empty fight before the
first jump and after the clock move, the due work and the maintenance of every jump, printing `FFI-PROBE ok <phase>` to stderr unbuffered. The last
`ok` line before the segfault names the phase after which the library breaks (a battle in due work, a specific maintenance command, the Http probe of the
sidecars, ...). Paste the last 5 FFI-PROBE lines and the first line after them. If the very first probe (`before the first jump`) already crashes, the
difference is in the process before the loop: then run `ai:sim --hours=0 --ffi-probe` and, separately, `php artisan tinker` calling `RustBattleEngine::binding()`
after `config(['queue.default' => 'sync'])` and `Carbon::setTestNow(now())`, to find which of the sim's start-up steps does it.

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

### Cloud thread: sidecar speed (HANDOUT FOR THE LOCAL AGENT). The sim keeps the REAL sidecars. Nothing may change a result.
**Rule: an optimization ships ON only if it is proven to give the same decisions. Any difference per session = FAIL: switch that optimization off (kill switch below) and report to the cloud thread.**

#### What changed (module main, ogamex-next main c1920df; none of it was run against the stack)
| # | Change | Where | Why results cannot change | Kill switch (env) | Default |
|---|---|---|---|---|---|
| 1 | CBRKit answers remembered per (casebase, query) for the life of one engine; failures never kept | `CbrKitExperienceEngine::$similarityCache` | same inputs, same sidecar answer, same process | (none needed: in-process, keyed by full casebase content) | on |
| 2 | All objects of an economy pass sent in ONE `/retrieve` (named queries); per-object fallback if the batch fails | `EconomyUpgrades::rankedProduction` -> `PrefetchesExperience::prefetch` -> `CbrKitClient::rankMany` | each query is scored on its own by the sidecar; the batch only saves round trips | `AI_EXPERIENCE_DECISION_WEIGHT=0` disables the whole lane (changes behaviour, only for A/B baselines); to disable only batching revert `prefetchExperience` call | on |
| 3 | CBRKit similarity computed in-process by Rust (`rank_case_similarities` in `libbattle_engine_ffi.so`), sidecar used only if the lib/function is missing | host `rust/battle_engine_ffi/src/case_similarity.rs`, module `RustCaseSimilarity` | proven equal to `docker/cognition/cbrkit/retriever.py`: 60,000 random pairs, max diff 2.2e-16, 0 mismatches (cloud run, debug build) | `AI_EXPERIENCE_CBRKIT_RUST=false` | on |
| 4 | PsychSim stance cached 1 h per temptation | `PsychSimClient::decide` | world is rebuilt from the temptation alone | `AI_COGNITION_PSYCHSIM_CACHE=false` | on |
| 5 | AgentOS recall ranking cached 1 h per full request (scope, query, limit, memories) | `AgentOsClient::recall` | driver keeps no store; ranking is a function of the request | `AI_MEMORY_AGENTOS_CACHE=false` | on |
| 6 | Fatima appraisal / social exchange cached 1 h per (request, scenario, instance, fixture hashes) | `FatimaCognitionSession::remembered` | needs proof that a reloaded scenario answers identically every time | `AI_COGNITION_FATIMA_CACHE=true` to enable | **OFF** until step C passes |
Failed/null answers are never cached. After flipping any switch: `php artisan config:clear && php artisan cache:clear`, restart queue worker and php-fpm.

#### Step A: build and prove the Rust function (do first)
1. Pull both repos. In ogamex-next run `bash rust/compile.sh` (or the README's `cargo build --release -p battle_engine_ffi` and copy `rust/target/release/libbattle_engine_ffi.so` to `storage/rust-libs/`). Run `cargo test -p battle_engine_ffi` (7 case_similarity tests must pass).
2. Equivalence against the sidecar's real Python measure: `python3 scripts/rust-similarity-check/gen.py && php scripts/rust-similarity-check/check.php storage/rust-libs/libbattle_engine_ffi.so` in the module. Must print `mismatches 0`. Paste the output in Results.
3. Live equivalence with the real sidecar: pick 3 accounts with a non-empty building casebase; for each, POST one economy query to the running CBRKit container (`/retrieve`, same body the client builds) and compare to `RustCaseSimilarity::rank` on the same casebase (php artisan tinker). Max abs diff must be < 1e-9. Report.
4. Confirm PHP FFI loads it: `php -r 'FFI::cdef("char* rank_case_similarities(const char* i);","storage/rust-libs/libbattle_engine_ffi.so"); echo "ok";'` inside the grand container. If the extension is not loaded in the worker container, Rust stays unavailable and the sidecar path is used: report which.

#### Step B: unit and feature tests
`ogamex test-one` for: HybridCognitionTest, FatimaCognitionTest, DriverPayloadLimitTest, DriverSwapAuthorityTest, and every test file matching CbrKit|Experience|EconomyUpgrades|PsychSim|AgentOs|MemorySelector. Report each failure with its first FAILED line. Tests that assert the number of HTTP calls to a sidecar may legitimately change (fewer calls); tell the cloud thread which and why, do not edit them yourself.

#### Step C: A/B equivalence of decisions (the gate that decides what stays on)
Build `scripts/sidecar-ab.sh` (you implement it; keep it out of the sim/prove blocks of scripts/ogamex). Same start state and seed for every run: clone the cohort once, then for each configuration use its own SIM_DB cloned from that snapshot and the same `--from`, `--hours=6 --accounts=20`, real sidecars on (NO native cognition).
Configurations: **R0** baseline (AI_EXPERIENCE_CBRKIT_RUST=false, PSYCHSIM_CACHE=false, AGENTOS_CACHE=false, FATIMA_CACHE=false); **R1** = R0 + RUST=true; **R2** = R1 + PSYCHSIM_CACHE + AGENTOS_CACHE true; **R3** = R2 + FATIMA_CACHE=true. Also run R0 twice (R0a, R0b) to measure the sidecars' own run-to-run noise.
Diff per session between runs, from `ai_decision_traces` (player_id, work_item_id/decision key, selected action, score components) and the actions each session queued (`ai_work_items`: kind, player_id, payload). A session is "different" if the selected action, its parameters or its score components differ at all (floats within 1e-9).
Pass rule: R1 vs R0, R2 vs R0, R3 vs R0 must each equal R0a vs R0b (ideally 0 differing sessions). If R0a vs R0b already differs, say so: the sidecars are not deterministic and no cache is safe on that sidecar.
Report a table: run, sessions, differing sessions vs R0, first 5 differences (player, decision key, field, before, after).
Decision from the table: any config with differences -> turn its switch off and send me the first differences; if R3 is clean, set `AI_COGNITION_FATIMA_CACHE=true` in the grand/sim environment and tell me.

#### Step D: speed (only after C is clean)
Same sim as before (`sim --hours=6 --accounts=20`, fresh SIM_DB, real sidecars) for R0 and the final config: report the SIM speed line, JUMPS line, the PROFILE table and, per sidecar (Fatima 8092, CBRKit 8091, AgentOS 8093, PsychSim 8094), calls per session and mean latency (use the sidecar container logs or a global HTTP middleware counter). Name the slowest sidecar per call and the one with the most calls per session now.

#### Hand back to the cloud thread (write under Results, then I act)
1. Step A outputs (check.php line, cargo test result, live diff).
2. Step B failures.
3. Step C table and the switches you left on/off.
4. Step D numbers and the next hotspot.
5. Anything the sidecars returned that surprised you (errors, timeouts, non-determinism).
I will fix what failed in code, push to main, and update this Request. Remaining ideas, only if Step D says they pay: HTTP keep-alive / connection reuse for the Fatima call sequence (4+ calls per appraisal), a single combined Fatima endpoint in the .NET sidecar, a lower connect timeout for local sidecars.

### Cloud thread: hard rows, second pass (20:1x UTC), pushed to module main, none run
- **LIFE_FIGHTS:** `RaidPlanner` profit gate: on a defended target, Raider/Hybrid/Fleeter risk 0.5/0.3/0.25 of the p20 loot on the tail (`p20Net + debris + tol*p20Loot > 0`); farms still must never lose. The live-state omniscience the architecture review found (`RaidPlanner::target` reads the planet, not the report) is NOT changed yet; it is a larger rewrite and goes after this read.
- **COVER-MissileMission:** new `QueueableMissilePlanner` + `QueueAiMissileAction` (same row the galaxy overlay writes) + work kind 19 + candidate `missile` (archetype-preferences.yaml weights). Fires when a planet holds missiles and a fresh report (12 h) shows >= 20 defence units in range.
- **COVER-Defense-interplanetary-missile:** unit role `role:missile` keeps 5 per silo planet when the range is > 0 and a defended target is reported.
- **COVER-Ship-crawler:** unit role `role:class:*` builds the class's own ship (host `getClassShipId`), 2 per order up to 5 per planet.
- **COVER-hatred:** `RecordAiRelationshipInteractionAction` clamped trust and affinity at 0, so no grudge could exist. Both now run -1..1.
- **ALLY-001:** applicants were ranked by `highscores.general_rank`; with the rank pass not run every applicant read as a farm and was declined, so nobody was accepted or welcomed. Now ranked by points when ranks are 0. A founder kicks one member per pass whose affinity < -0.2 (or a human member inactive 7 d; cohort accounts are never kicked for inactivity).
- **COVER-moons / MoonDestructionMission:** no code yet. Moons come from big battles' debris; they should follow the fight changes. Moon destruction needs a moon target and deathstars; next after the read.
- Please read: `coverage`, LIFE_FIGHTS share, `ai_relationships` affinity < -0.2 count, alliance accepts/kicks in 1 h, and any new exception from `QueueAiMissileAction`.

### Cloud thread: hard rows, first pass from code (19:5x UTC; answers Nagi's 19:44 task)
Pushed to module main, none run. Please pull and read the rows below with `scripts/ogamex coverage`, `stuck` and the cohort invariants.
- **LIFE_FIGHTS (QUAL-013):** `QueueableSpyPlanner::target()` now ranks, for an account that owns a war fleet (a military hull with real attack, host catalogue), the bodies whose last report found ships or defence ahead of the empty farms. Before, every scout read the quiet farms first, so reports (and raids) were farms. Watch: share of battles with rounds, and raids on defended targets.
- **COVER-Station-*, COVER-Research-graviton-technology:** new `ambition` pass in `QueueableBuildingPlanner::passes()` (`EconomyUpgrades::ambitions()`): a planet holding 6x the price of a non-production station or technology (level < 3, host requirements met, valid planet type) builds the cheapest first. Watch: `coverage` for nano factory, terraformer, depot, dock, gate, phalanx, lunar base, graviton. Deathstar then follows through `capitalFleet` once graviton stands.
- **Canary `source_short_at_dispatch`:** I need the evidence to choose the planner: please paste, for the last rejected 8, `work kind`, `player`, `planet`, the planned shipment and the source stock at dispatch (`ai_work_items` rows plus `account PLAYER`). Candidates by code: transfer (stock spent between plan and dispatch), fleet save (`trimmedToFuel`), raid (fuel).
- **NAKED_BESIDE_WALLED 117 (and 40, 46, 80):** the unit planner already serves a bare sibling first; I need `scripts/ogamex account 117` (shipyard level, unit queue, stock, last unit refusal) to see which gate stops it.
- **Not yet written:** COVER-MissileMission, COVER-MoonDestructionMission, COVER-Ship-crawler, COVER-Defense-interplanetary-missile, COVER-hatred, COVER-moons, ALLY-001 lane, sim call-count work. Next in that order after the evidence above.

### Cloud thread: STUCK fixes written from code (19:3x UTC), please re-run `scripts/ogamex stuck` after pulling
- `QueueAiBuilding` "Maximum number of items already in queue" and `shipyard_busy`: the executor now asks `QueueableBuildingPlanner::orderIsStale()` for an order that carries its own planet and building; a full queue or a busy yard re-plans from live state instead of hitting the host gate (ExecuteAiIntentAction::build).
- `DispatchFleet` "insufficient storage capacity": the raid is now refused when the launch fleet's tanks cannot hold the outbound fuel, in the planner (`fuel_tank_short` raid-rejected counter) and at dispatch (`fuel_tank_short` reason). Transfers, expeditions, recycles and fleet saves already ask the tank (FlightFuel).
- Expect: the 27-account queue-full row and the 15-account storage row drop to 0 in `stuck`; if a row stays, send its player id and `account PLAYER` output.

### Cloud thread: reply to Results 2 (Fatima cache A/B), 19:xx UTC

Thanks: R1 and R2 stay ON, the Fatima cache stays OFF (no change from me). Segfault fix noted (host 018f4c73). Also pushed since your last pull: 9985aa8 and cc53a46 (AUTH_UPTIME: a session keeps the hour it was scheduled for; a hostile inbound in the dark period waits for the next waking session).

**Why I do not believe the Fatima cache should change results, so the 450 diff needs evidence, not a guess.** The sidecar's `POST /scenarios` (APIResource.cs:294-302) replaces the whole scenario and rebuilds instance 1 from the JSON, so an appraisal is a pure function of (beliefs, event). A cached answer must equal a fresh one. Three things can still differ; please settle which, in this order, and write the answer into Results:
1. **Cause of the 450 rows.** Print five of them (R0a row vs the R3 neighbour): which trace kind, which player, which tick. If they are all `affect`/`social` rows whose source is the Fatima appraisal, run the same request twice with the cache OFF and diff the two answers (`php artisan tinker`: `app(FatimaCognitionSession::class)->appraise(...)` twice with identical args). Equal -> the cache is not the cause, the diff is run-to-run noise at a different speed (wall-clock-dependent code: lock wait, timeouts, a `now()` read). Unequal -> the sidecar is not pure and I will stop caching it for good.
2. **Wall-clock dependence:** in R3, is the run faster (x?)? If yes, rerun R0 at the R3 speed by padding nothing: just run R3 twice. If R3 vs R3 also differs by ~450, the cache is innocent.
3. **Stale-class guard:** `Cache::get` returns an unserialized `FatimaEmotion`. With the `database`/`file` store check that `get_class($state['emotions'][0])` is `Modules\AI\Infrastructure\Cognition\FatimaEmotion` (not `__PHP_Incomplete_Class`).

**Per-call cost (what I will do once 1-3 are answered):** each appraisal is 4+ calls (load, N beliefs, perceive, emotions) = the 22 calls per session you measured. I will fold the belief writes into the scenario JSON the module already sends, so an appraisal becomes load + perceive + emotions. That is only done if the answer to 1 shows the sidecar accepts initial beliefs in the scenario; please run `grep -n "belief" docker/cognition/fatima/scenarios/ogame-cognition.json | head` and paste it so I can see where the beliefs live.

### Cloud thread: reply to the sidecar handout results (19:xx)
- **Sidecars were down:** noted, thanks. Please keep the sim/prove sidecar probe loud (refuse to start when one is down) so a down sidecar can never again look like a speed or correctness result.
- **Step B failures:** the config defaults for the Rust path and the PsychSim/AgentOS caches are now OFF when `APP_ENV=testing` (module main), so the wire-level driver tests see the driver again. Re-run AgentOsMemoryDriverTest, CbrKitExperienceEngineTest, DriverPayloadLimitTest, DriverSwapAuthorityTest, HybridCognitionTest. If any still fails, send me its first FAILED line. The Fatima fixture-order test is the PERS-008 fold, not mine (a writer row).
- **Segfault, one change tried (not proven):** the module opened `libbattle_engine_ffi.so` with its own `FFI::cdef`, a second dlopen next to `RustBattleEngine`'s. `RustBattleEngine::binding()` (ogamex-next main) is now the ONE binding in the process, with all three functions declared; `RustCaseSimilarity` uses it (it falls back to its own cdef only if the host class is missing, and to the sidecar if the function is missing). If `ai:sim` still segfaults at ~0.3 h: (1) run it with `AI_EXPERIENCE_CBRKIT_RUST=false` AND `--workers=1` (the forked `--workers` path is the main difference from tinker/pest: a child that opens the lib after fork and exits dlcloses it); (2) if only `--workers>1` crashes, the fix is to open the binding in the parent BEFORE the first fork (call `RustBattleEngine::binding()` once at the top of `ai:sim`; time-control thread owns that file, send them this line); (3) report the gdb frame again.
- **Fatima fixture (game-logic bug, fixed):** the scenario had characters for Miner, Turtle, Fleeter, Trader, Casual but not for the new Raider and Hybrid archetypes, so every Raider/Hybrid appraisal found no character in the sidecar and silently fell back to native. Added both (safety significance 0.3 and 0.5, the Fleeter and Casual values). No sidecar rebuild is needed: the module re-sends the scenario on every appraisal. Re-run FatimaCognitionTest.
- **Step C/D:** run `scripts/sidecar-ab.sh` as soon as a 6 h sim survives; the pass rule in the handout still stands.

## Results: sidecar handout, 2026-10-03 ~18:10 UTC (verifier)

**Pulled** module 6839164 + host c1920df4. composer dump-autoload, optimize:clear, queue:restart and a restart of the grand app/queue/scheduler were done.

**Finding 0: all four sidecars were DOWN** (8091-8094 refused) and the CBRKit circuit breaker had opened. Every cognition call in every earlier sim/live run paid the 2 s
connect timeout or short-circuited. Started with `docker compose -f Modules/AI/docker/cognition/docker-compose.yml up -d --build`; they stay up (restart: unless-stopped).
`ai:sim` now prints `SIDECAR fatima/cbrkit/agentos up (1-2 ms)`. With them up a 0.2 h, 5-account sim ran 107 sessions in 18 s, 0 errors (x40, real).
Note for the sim/prove scripts: they should probe the sidecars and refuse to start (or say so loudly) when one is down.

**Step A: PASS.** `compile.sh` builds; `cargo test -p battle_engine_ffi` 10/10 (7 case_similarity); `check.php`: `compared 60000 scores, max abs diff 2.22e-16, mismatches 0`;
live equivalence against the running CBRKit sidecar, players 18/20/17, 200 cases x 4 queries each: **max abs diff 0** (the casebase must be keyed by case id, as the engine does; a list
makes `rankMany` return null). FFI extension loads in the grand container.

**Step B: failures (not edited).** AgentOsMemoryDriverTest "an empty ranking ... does not charge the driver" (expects 2 HTTP calls, cache makes 1);
CbrKitExperienceEngineTest 4 (the driver scores the casebase / honours the case bound / circuit opens / success resets: "expected request not recorded", Rust answers locally);
DriverPayloadLimitTest "a response within the bound is still interpreted" (0 requests); DriverSwapAuthorityTest 2 (request not recorded);
HybridCognitionTest "keeps the native ranking" (`1.0` is not null: Rust answered where the test expects the driver path to be down);
FatimaCognitionTest "module-owned scenario fixture supplies the characters": expected list lacks Casual/Fleeter/... order, probably the PERS-008 Trader/Casual fold, not the caches.
Passing: EconomyUpgradesTest 12, NativeExperienceEngineTest, PsychSimSocialCognitionTest. Fix for the first group: the tests must run with `AI_EXPERIENCE_CBRKIT_RUST=false` (and the cache switches off) or the tests assert the in-process path.

**Step C: scripts written, NOT run** (`scripts/sidecar-ab.sh [hours] [accounts]`, `scripts/sidecar-ab-diff.php`): one snapshot, R0a/R0b/R1/R2/R3 on their own copies, file cache wiped per run, diff of decision traces
and queued work with 1e-9 float tolerance. Blocked by the segfault below.

**BLOCKER: ai:sim segfaults on the first Rust call after about 15 simulated minutes** (0.2 h survives, 0.3 h dies, any account count, every time, a fresh clone each time).
gdb (installed in the grand container: `gdb -batch -ex run -ex bt --args php artisan ai:sim ...`) shows a call through a NULL GOT slot inside the lib:
`#0 0x0 #1 fight_battle_rounds` in one run, `#1 rank_case_similarities+35 (call *GOT -> rax 0; the instruction before the null deref is the libc strlen call)` in another, then FFI/libffi/ffi.so.
So any Rust entry point crashes at its first libc call, in this process only: the same lib works from `artisan tinker` (check.php, 60,000 scores) and under pest. Ruled out: battle engine
setting php (still crashed), opcache off, LD_BIND_NOW=1, account count, CBRKIT_RUST=false. Not yet tried: what ai:sim does that tinker does not (pcntl/fork for --workers, setlocale, a
dlopen of the same .so twice in one process: RustBattleEngine and RustCaseSimilarity each call FFI::cdef on it; a single shared binding is the first thing I would try).

**Step D: not possible until the segfault is fixed.**

**Local changes of this cycle (pushed with this file):** directed rows with a `file_ref` are no longer held by a passing count-based aspect (strategy-pipeline.py); file_refs set on the STUCK-*, QUAL-010 and QUAL-013 rows; sidecar-ab scripts.

## Results 2: segfault fixed, Step C done, 2026-10-03 ~19:20 UTC (verifier)

**Segfault root cause: not the sim, not the Rust code.** The `ogamex-capacity-*` compose stack mounts the same repo and restarts (every start runs `docker/entrypoint.sh` -> `rust/compile.sh`), and `compile.sh`
did `cp` over `storage/rust-libs/libbattle_engine_ffi.so` in place. Any running PHP process with the old file mapped (the sim, queue workers) then crashed at its next Rust call (call through a null GOT slot).
The .so mtimes 16:24, 16:53, 17:40 matched the restarts. Fixed in host 018f4c73: install by `cp` to a temp name plus `mv -f` (new inode). After that: no segfault in six 1 h sims.

**Step C (scripts/sidecar-ab.sh 1 20: 1 simulated hour, 20 accounts, same snapshot and start instant, real sidecars, cache wiped per run). 364,223 trace+work rows compared per run.**
| run | config | speed | differing rows vs R0a |
|---|---|---|---|
| R0b | baseline again | x6 | **0** (the sim is deterministic) |
| R1 | + Rust CBRKit | x6 | **0** |
| R2 | + PsychSim and AgentOS caches | x7 | **0** |
| R3 | + Fatima cache | | **450** (decision traces present in R0a, missing in R3) |
Decision: R1 and R2 stay ON. **The Fatima cache stays OFF** (`AI_COGNITION_FATIMA_CACHE` default false is right): it changes behaviour (traces vanish), so a cached appraisal is not equal to a fresh one.
Cloud thread: find why (probably the instance/belief state a Fatima appraisal mutates server-side, which a cached answer skips).

**Step D numbers (R0, 1 h, 20 accounts, sidecars up):** x6-x8, 434-445 jumps (8 simulated s each), 111-131 sessions. PROFILE: `due work` 410 s of 438 s (94%), 0.95 s per jump; maintenance ai:advance-alliance-life 2.5 s/call;
everything else under 10 s. Sidecar calls in that hour (container logs): **Fatima ~3,100** (953 POST /scenarios instance creations, ~250 beliefs + ~250 socialexchanges per character, 226 GET /scenarios),
CBRKit 146 /retrieve, AgentOS 0, PsychSim 0. So a session costs ~22 Fatima calls (~3.5 s). Slowest sidecar per call and most calls per session: **Fatima**. Rust CBRKit and the caches cut ~3%, not the cost.
Next hotspot: the Fatima call sequence per appraisal (create instance every time: 953 instances; GET /scenarios as a health probe 226 times). Ideas in order: do not create an instance per appraisal, drop the GET probe,
a combined endpoint, keep-alive; then a second Fatima replica routed by player id.
**Surprise:** all four sidecars had been down for hours (see Results above); the circuit breakers had opened, so earlier sims/live ran without cognition.

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
