# Harness recovery: stop the loop circling, get to a verdict fast

Written 1 October 2026 after a full read of the evidence runs (`evidence/20261001-1042`, `-1221`,
`-1324`), the proof and attempt logs, the scorecards and the harness code, and after the cohort was
reset (below). `AGENTS.md` still binds. This file is the work order until every exit criterion at the
bottom is met; it overrides the work order in `plan/HANDOFF.md`.

## The diagnosis

Nothing has been proven since the north-star gate went in (`strategy-pipeline.py status`: 3 proven,
141 closed before proofs existed). The planners are not the main reason. Four things stack up:

1. **The world was broken.** Both cohorts ran at **90,000×** speed instead of the 1000× grand-test
   protocol (`local-docker-dev/capacity-run.sh`). A day of real time was centuries of game. Planets
   filled their fields and banked 14 billion metal, the yard piled up 54 million defence units, and
   every human-timescale measure (reaction latency, uptime shape, a raid's flight) stopped meaning
   anything. **Fixed on the cohort machine, see "Already done".**
2. **The world had no prey.** The only inactive players were nine abandoned test accounts with no
   mines, galaxies away from the AI accounts. Nothing was worth raiding, so `raids` read zero for a
   reason that was not the AI's. **Fixed on the cohort machine.**
3. **The judge is wrong.** ECON-001's proof fails on `IDLE_QUEUES`. The per-planet read-out shows
   every "idle" planet refused for a legal reason (`no free field`, `lab already taken`).
   `IDLE_QUEUES` counts "no open building row" and never asks whether anything was buildable, so it
   cannot pass on a saturated planet. The harness re-ran the same `PROOF: FAIL` nine times.
4. **The harness burns budget in circles.** It gets three attempts, then a 12h cool-off that
   *deletes the counter* and starts over ("there is no terminal state"). It has no memory of what
   the failure was, and it does not check whether the proof can pass before paying for code. The
   implement call has no `max_tokens`; 96% of output tokens were reasoning, and 22 of 62 calls did
   not finish cleanly. The rows it could take (DEF-35..38, IMPL-67..71) move no failing aspect.

## Already done on the cohort machine (1 Oct 2026, by the reviewer)

- Harness stopped. It stays stopped until W1 and W2 are merged and pulled.
- **pve stopped** (containers stopped, database kept). One honest cohort beats two broken ones.
- **grand backed up** to `~/cohort-backups/ogamex-grand-90000x-20261001.sql.gz` (cohort machine), then
  dropped and reseeded with the grand-test protocol: speeds 1000×, one human first account,
  `ai:seed-grand-test --players=20` (AI accounts at 1:1–1:9), and 12 inactive neighbours at 1:9–1:14
  from the new `local-docker-dev/seed-inactive-neighbours.php`. These are accounts registered through
  the host path, with ordinary mine levels (metal 12–17), small storage, a token defence on every
  other one, and `users.time` 10 days old.
- Grand's Redis keys flushed; the scheduler and Horizon workers restarted on the fresh database.
- Every scorecard, proof log and State-table number from before 1 Oct 15:39 UTC is void. Do not
  compare against them.

## Evidence (so a fresh agent does not have to re-derive it)

| Finding | Where |
| --- | --- |
| Speeds were `90000` in `settings` for grand and pve | the cohort databases (now reset) |
| ECON-001 proof: the same `PROOF: FAIL` line nine times, no step named | `plan/research/ogame/proofs/ECON-001.log` |
| every-planet-builds 0/12, each planet refused by `no free field` / `lab already taken` / `price plus reserve` with 0 deuterium | `plan/research/ogame/evidence/20261001-1324/11-situations-grand.txt` |
| `IDLE_QUEUES` = "no unprocessed building row", never "something was buildable" | `scripts/verify-cohorts.php:240-258` |
| `WALL_CEILING` / `NAKED_BESIDE_WALLED` on 19 of 20 accounts | `evidence/20261001-1324/03-verify-grand.txt` |
| ALLY-001, FLEET-002, DEF-35: failing tests ×3. FLEET-003: unwired YAML ×3. IMPL-67: no test ×3 | `plan/research/ogame/attempts/*.log` |
| `MAX_ATTEMPTS = 3`, `COOLOFF_SECONDS = 12h`, and `cooling_off()` deletes the counter when the cool-off ends | `scripts/strategy-pipeline.py:54-55, 879-913` |
| Implement payload has no `max_tokens` | `scripts/strategy-pipeline.py:2271` |
| `prove_row` runs every step after a failure, and the log keeps only the summary line | `scripts/ogamex:187-230` |
| Module suite red: `AiActivityMarkerTest` "2 records were found" (113/114) | `evidence/20261001-1324/07-test-module.txt` |
| `tasks.db` (binary) committed from two machines; root-owned scorecards blocked a merge; a root `init-watcher` auto-commits | `evidence/takeover-20261001-1304/LOG.md` |

## Rules for whoever works this file

- **Lanes.** The cloud agent works from code only and has no cohort, no Docker and no live database.
  It does W1, W2 and the code parts of W3/W4, and pushes a branch. The cohort machine pulls, runs
  the tool chain and the proofs, and restarts the harness. Anything that needs a live cohort to
  verify is marked **[verify on pull]**.
- **Ledger.** Register each item as a row before working it (`python3 plan/tasks/task.py add`, codes
  `HARNESS-005` onward for tooling). Tooling rows carry a `harness:` proof step; module code rows
  carry `aspect:`, `situation:` or `invariant:` as usual. If `tasks.db` is not usable where you are,
  list the rows you would add at the end of your PR description instead; the cohort machine adds
  them.
- **Gate 2 applies to tooling too.** One function per mechanism. Extend `strategy-pipeline.py`,
  `scripts/ogamex`, `task.py` and `verify-cohorts.php`; never add a fourth orchestrator, a config
  file for a constant, or a class with one caller.
- **Module code** follows `AGENTS.md` → Code and Tests in full: `app()`/`makeWith()`, no `else`, Pest
  Feature tests on real models, no Mockery, and the three gates.
- Commit named files only. Never commit `plan/tasks/tasks.db`, `plan/research/ogame/attempts/*`,
  `proofs/*` or `scorecards/*`: they are runtime state of the cohort machine.

---

## W1: Stop the loop circling (tooling, cloud agent)

### W1.1 Failure signature and a terminal `stuck` state
`scripts/strategy-pipeline.py` (`record_failure`, `cooling_off`, `status`), `plan/tasks/task.py`

- Normalise each failure reason: strip digits, durations, timestamps, absolute paths and hex or uuid
  ids, and collapse whitespace. Hash the result (sha1, first 12 characters). Append the hash to
  `attempts/<CODE>.sig`.
- When a row's last two signatures are equal, stop. Write `attempts/<CODE>.stuck` holding the
  normalised reason and the first 40 lines of the raw one. Set the row to `blocked` with note
  `stuck: same failure twice: <first line>`.
- `cooling_off` treats a stuck row as never attemptable, and the cool-off no longer deletes the
  counter of a stuck row. Rewrite the docstring "there is no terminal state": a repeat failure is
  the terminal state.
- `task.py unstick CODE`: a new subcommand that deletes `.stuck`, `.sig` and `.count` and sets the
  row back to `todo`.
- `strategy-pipeline.py status` prints a `STUCK:` line listing them.

Verify offline: `implement CODE --answer <file>` already replays a saved answer without a model
call. Replay `attempts/DEF-35.answer.md` twice against a scratch copy of `tasks.db`; the second
replay must leave the row `blocked` and print it under `STUCK`. Where Pest cannot run, fake the test
runner's failure with a stub answer that fails a pre-test validator (for example a file with no
test, which fails with the fixed "no test" reason).

### W1.2 Red-first baseline before any paid attempt
`scripts/strategy-pipeline.py` (`implement`)

- Before the first model call for a row (no `.count` yet), run `bash scripts/ogamex prove CODE --json`
  (W2.1) and save it as `attempts/<CODE>.baseline.json`.
- If the baseline passes, make no model call. Run `task.py done CODE` instead.
- If every failing step in the baseline is tagged `suspect` (W2.3), make no model call. Block the
  row with `proof suspect`.
- After an attempt's own tests pass, run the proof again. Accept the attempt only if the proof
  passes, or at least one step went from FAIL to PASS and none went from PASS to FAIL. Otherwise the
  attempt failed with reason `proof unchanged: <step> <first line>`, which feeds W1.1, so a second
  identical miss makes the row stuck.

### W1.3 Proof-suspect detector
`scripts/strategy-pipeline.py`

- When a row's proof fails with the same step signature on three deliveries (code changed each time,
  per the `implemented/<CODE>.md` marker), tag that step `suspect` (W2.3) and block the row as
  `proof suspect`, for the reviewer. ECON-001 is the worked example: the planner was changed three
  times and `IDLE_QUEUES` read the same.

### W1.4 Cap the writer's cost
`scripts/strategy-pipeline.py` (implement payload, `model_call`)

- Add `max_tokens` to the implement payload. Set it to the largest answer in
  `attempts/*.answer.md` (measure the bytes, convert at ~3.5 bytes per token) plus 50%. Write the
  measurement in a one-line comment.
- Look up whether the provider's chat-completions API for `deepseek-flash` accepts a reasoning or
  thinking budget parameter. If it does, set it and cite the documentation in the PR. If it does
  not, say so in the PR. Do not invent a field.
- Count a `finish_reason` other than `stop` as a failure with the fixed reason
  `unfinished: <finish_reason>`, so W1.1 stops a row that keeps truncating, instead of a
  `SystemExit` that is not recorded.

### W1.5 Spend only on rows that can move a failing aspect
`scripts/strategy-pipeline.py` (the attemptable queue in `status`)

- A row is attemptable only when one of its proof's `aspect:` or `invariant:` steps currently fails.
  For an aspect, read the newest `plan/research/ogame/scorecards/*.json` that is not older than the
  cohort reset (1 Oct 2026 15:39 UTC). For an invariant, read the newest `verify` output saved by the
  evidence run. With no scorecard newer than the reset, nothing waits on this rule. A row whose
  steps all pass waits, and `status` says why.
- Set DEF-35, DEF-36, DEF-37, DEF-38 and IMPL-67..IMPL-71 to `deferred`, reason "moves no failing
  aspect (harness recovery 1 Oct 2026)". IMPL-67's scenario is `"status": "unverified"` and proves
  nothing.

---

## W2: Make the verdict fast and readable

### W2.1 `prove` names the failing step, bails, and speaks JSON (tooling, cloud agent)
`scripts/ogamex` (`prove_row`)

- Run `test:` steps first and stop at the first failure. Run the live steps (`situation`,
  `invariant`, `aspect`) only when every test step passed.
- Per step, record `PASS|FAIL`, seconds, and the first failing line (pest's first `FAILED` line, the
  situation's `read-back`, the invariant's first `[NAME]` row, the aspect's count against its floor).
- `proofs/<CODE>.log` is overwritten per run with one line per step and the summary line. No more
  appended duplicates.
- `prove CODE --json` prints `{"code":…,"pass":bool,"steps":[{"step":…,"pass":bool,"seconds":…,"line":…}]}`
  on stdout as the last line. W1 consumes only this.
- Keep `bash` + `python3` only; no new dependency. **[verify on pull]** for the live steps.

### W2.2 Situation tests: a verdict in seconds, not hours (module code, cloud agent)
New Pest Feature tests under `tests/Feature/Situations/`

Each situation that guards a failing aspect gets a Feature test that plants the same situation on an
`IsolatedAccountTestCase` account with an ordinary economy (not billions) and asserts what the
account's planner or action chooses. Use real host models and services, no Mockery, and read the
live situation in `scripts/cohort-scenario.php` plus a neighbouring Feature test first, and follow
their shape.

- `EveryPlanetBuildsSituationTest`: three planets with free fields and stock for their next mine.
  `QueueableBuildingPlanner::steps()` returns one step per planet, and the session queues all three.
- `InboundAttackSituationTest`: a hostile fleet due inside the reaction lead. The account saves.
- `DebrisBesidePlanetSituationTest`: a debris field in reach and a recycler on hand. The account
  sends it.
- `InactiveNeighbourSituationTest`: a neighbour whose `users.time` is 8 days old and whose stock
  beats the trip's cost. The raid planner picks it (ATK-001's situation; also add the live
  `inactive-neighbour` situation to `cohort-scenario.php`).
- `AllianceApplicationSituationTest`: an `alliance_applications` row (alliance_id, user_id,
  application_message, status 0) for an AI-led alliance. It is decided (SIM-001's situation; also add
  the live one).

A test that fails today because the behaviour is missing is still the deliverable. Mark it
`->todo()` with the row code, so the suite stays green and the row has its fast proof waiting. Then
give each matching row the proof order: own test → situation test → live `situation:` →
`invariant:`/`aspect:`. Change `task.py done` so that a row whose test steps pass becomes `delivered`
and only becomes `done` when its live steps pass on an evidence run. Today the live steps hold
everything hostage to the cohort clock. **[verify on pull]** (Pest needs the host and MySQL).

### W2.3 Invariants that measure the defect, not the symptom (cloud agent)
`scripts/verify-cohorts.php`, `plan/tasks/task.py`

- **`IDLE_QUEUES`**: a planet is idle only when `QueueableBuildingPlanner::steps()` returns a step for
  it **and** it has no building in progress. A planet whose candidates are all refused by
  `refusal()` is not idle. Print those as `SATURATED player P: N planet(s) with every candidate
  refused (no free field: a, lab busy: b, price plus reserve: c)`. This is information, not a
  violation: it is the signal for W4's expansion sinks. Resolve the planner with `app()` and reuse
  `refusal()`; restate no gate.
- **`UNIVERSE_SPEED`**: a new violation when `economy_speed` or any fleet speed in `settings` is above
  1000. That is the guard against the cause of this whole recovery. 1000 is the grand-test protocol
  in `capacity-run.sh`; state it once.
- `task.py`: a proof step may carry a trailing `?` (`invariant:IDLE_QUEUES?`), meaning **suspect**.
  `prove` runs and reports it but never fails the row on it, and W1.2 never pays for a row whose only
  failures are suspect. `task.py proof CODE` accepts and prints the marker.
- Take `?` off ECON-001's `invariant:IDLE_QUEUES` once the rewrite lands. **[verify on pull]**

### W2.4 Fix the red module suite (cloud agent, best effort)
- `tests/Feature/AiActivityMarkerTest.php:85` expects one row and finds two. Read the code under test
  and decide whether production writes a duplicate marker (fix the code) or the test depends on
  order or leftover rows (fix the test). Say which in the PR. **[verify on pull]**

---

## W3: The world

### W3.1 Cohort protocol (done; keep it true)
- Grand is the only evidence cohort. pve stays stopped until W1–W2 are merged and one clean evidence
  run on grand has been read.
- Speeds stay at 1000× (W2.3 `UNIVERSE_SPEED` enforces it). Never raise a cohort's speed to make it
  grow faster. Reseed instead. Reseed recipe (cohort machine only):
  back up the database → drop and create it → `up -d ogamex-app` and wait for the full migration
  count → `capacity-prepare.php 20` → `ai:seed-grand-test --players=20 --confirm` →
  `seed-inactive-neighbours.php 12` → flush `*ogamex_grand*` Redis keys → `--profile queue up -d`.
- Reseed when `SATURATED` covers most planets of most accounts. That is the end of a run, not a
  defect to tune away.

### W3.2 The yard must have a stopping rule (ARB-001 + QUAL-003, cloud agent after W1–W2)
- The yard wins ~70% of sessions from the fixed score table in `CandidateActionFactory::features`.
  Feed in what the planners already compute (loot per hour of flight, debris value, expedition
  yield), as ARB-001 says.
- Defence on a planet stops growing at a level an experienced player names (Gate 3): "the wall makes a
  raid on this planet unprofitable for an attacker of my size", priced from host data and the
  planet's own production (Gate 1), with no unit named. Prove it with `invariant:WALL_CEILING`,
  `invariant:NAKED_BESIDE_WALLED` and a situation test. **[verify on pull]**

### W3.3 Verify the Terraformer field rule (cloud agent, research only)
`QueueableBuildingPlanner::refusal()` says a terraformer consumes a field, so a full planet can never
build one. Check against original OGame (wiki, the game's own text) and report the answer with
sources in the PR. If it is wrong, the fix is the host object's `consumesPlanetField`, in a separate
host PR, not in the module.

---

## W4: The aspects that are actually dead (after W1–W2)

Order: **ATK-001** (raid ladder; the fresh cohort now has inactive neighbours) → **colonisation**
(why does an account with astrophysics headroom not colonise; read the `SATURATED` output) →
**FLEET-003** proactive save (wire `resources/behavior/fleet-save-reaction.yaml` into the planner
that owns the save, or delete it) → **FLEET-002** recycle → **SIM-001 / ALLY-001**. Read each row's
`attempts/<CODE>.log` before starting it. Each starts from its W2.2 situation test.

---

## W5: Owner actions on the cohort machine (not for the cloud agent)

- `tasks.db` out of git: keep `seed.sql` as the shared record (`python3 plan/tasks/dump_seed.py`),
  `.gitignore` the binary, `git rm --cached` it.
- `init-watcher` (root, PID 3): stop it, or limit it to `plan/research/ogame/evidence/`.
- Root-owned scorecard files: run the writer with the host UID, or `chown` after writing.
- `HARNESS-003`: `schedule:work` in the host entrypoint.

## After the pull (cohort machine)

1. `git pull`; run the tool chain (`bash scripts/ogamex gate`, `test-one` per touched test, then
   `bash scripts/ogamex test`), and fix what is red before anything else.
2. Add the ledger rows listed in the PR, remove the `->todo()` marks the code now satisfies, and run
   `prove` on ECON-001 and the W2.2 rows.
3. Restart the harness (`bash scripts/harness-live.sh`). Watch one pass: no row attempted twice on one
   signature, and `STUCK` and `deferred` doing their job.
4. After one hour of fresh-cohort play, run the evidence prompt and rewrite the State table in
   `HANDOFF.md`.

## Exit criteria

The recovery is done when, over one unattended day on the fresh grand:
1. no row is attempted twice on one failure signature;
2. at least one row closes through a fast proof (test + situation test);
3. `UNIVERSE_SPEED` is clean and `IDLE_QUEUES` reports only real misses;
4. the hourly scorecard shows `raids` above its floor, and at least one more of the dead aspects
   (recycle, colonisation, fleet save, alliance) is above zero.
