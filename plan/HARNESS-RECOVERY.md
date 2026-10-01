# Harness recovery: stop the loop circling, get to a verdict fast

Written 1 October 2026 after a full read of the evidence runs (`evidence/20261001-1042`, `-1221`,
`-1324`), the proof and attempt logs, the scorecards and the harness code. `AGENTS.md` and
`plan/HANDOFF.md` still bind; this file is the work order for fixing the loop itself. Work it top to
bottom: each workstream unblocks the one after it.

## The diagnosis in one paragraph

Nothing has been proven since the north-star gate went in (`strategy-pipeline.py status`: 3 proven,
141 closed before proofs existed). The cause is not that the planners cannot play. Three things
stack up. **(a) The judge is wrong.** ECON-001's proof fails on `IDLE_QUEUES`, but the per-planet
read-out shows every "idle" planet refused for a legal reason (`no free field`, `lab already taken`).
The planets are full and hold billions of resources. **(b) The world is degenerate.** The grand
cohort holds 54 million defence units (51M rocket launchers, 11M on one planet) and 14 billion metal
on single planets. The yard wins about 70% of sessions, and the expansion sinks (colonisation,
raids, recycle) are at zero, so no economy read means anything. **(c) The harness burns budget in
circles.** It retries the same failure three times and comes back after the cool-off. 96% of its
output tokens are reasoning, 22 of 62 calls did not finish cleanly, and the rows it can take are low
value, while the P0 rows sit in the strong lane.

## Evidence (so a fresh agent does not have to re-derive it)

| Finding | Where |
| --- | --- |
| ECON-001 proof: the same `PROOF: FAIL` line nine times, no step named | `plan/research/ogame/proofs/ECON-001.log` |
| every-planet-builds 0/12: each planet refused by `no free field` / `lab already taken`; three planets also hit `price plus reserve` with 0 deuterium beside 14B metal | `evidence/20261001-1324/11-situations-grand.txt` |
| `IDLE_QUEUES` counts "no unprocessed building row" and never asks whether anything was buildable | `scripts/verify-cohorts.php:240-258` |
| 54,187,390 defence units held, `WALL_CEILING` and `NAKED_BESIDE_WALLED` on 19 of 20 accounts | `evidence/20261001-1324/03-verify-grand.txt` |
| Hourly scorecards: espionage, raids, recycle, colonisation, social, chat and alliance all 0 | `plan/research/ogame/scorecards/ECON-001-ogamex-grand-*.json` |
| ALLY-001, FLEET-002, DEF-35: failing tests ×3. FLEET-003: unwired YAML ×3. IMPL-67: no test ×3 | `plan/research/ogame/attempts/*.log` |
| Retry policy: `MAX_ATTEMPTS = 3`, then a 12h cool-off that **resets the counter** and tries again; "there is no terminal state" | `scripts/strategy-pipeline.py:54-55, 879-913` |
| Implement call sends no `max_tokens` and no reasoning limit | `scripts/strategy-pipeline.py:2271` |
| `prove_row` runs every step even after one fails, and the log keeps only the summary line | `scripts/ogamex:187-230` |
| Module suite red: `AiActivityMarkerTest` "2 records were found" (113/114) | `evidence/20261001-1324/07-test-module.txt` |
| `tasks.db` (binary) committed by both sides; root-owned scorecard files blocked a merge; `init-watcher` (root, PID 3) auto-commits and cannot be stopped from the dev user | `evidence/takeover-20261001-1304/LOG.md` |

## Rules for whoever works this file

- Every change to `scripts/` or `plan/tasks/` is dev tooling. Register each workstream item as a row
  (`task.py add`, codes `HARNESS-005` onward) with a `harness:` proof step, so the north-star gate
  accepts it. Module code changes (W3, W4) need an `aspect:`, `situation:` or `invariant:` step as
  usual.
- Gate 2 applies to tooling too: one function per mechanism, no framework and no config for values
  that never vary. Extend `strategy-pipeline.py`, `scripts/ogamex` and `task.py`; do not add a
  fourth orchestrator.
- Stop the harness (`pkill -f harness-live.sh`) before editing `strategy-pipeline.py`. Restart it only
  after W1 lands.
- Commit named files only (`git add <files>`).

---

## W1: Stop the loop circling (do first, about half a day)

### W1.1 Failure signature and a terminal `stuck` state
`scripts/strategy-pipeline.py` (`record_failure`, `cooling_off`)

- Normalise each failure reason: strip numbers, timestamps, durations, paths under `/var/www` and
  hex ids. Hash the result. Store the last signatures beside the count:
  `attempts/<CODE>.sig`, one hash per line.
- When the same signature appears **twice in a row**, stop: write `attempts/<CODE>.stuck` holding the
  reason, and set the row to `blocked` via `task.py` with the note
  `stuck: same failure twice — <first line>`. Do not count down to a cool-off, and do not let the
  cool-off delete the counter of a stuck row.
- `cooling_off` returns "never" for a stuck row. Only a human or the reviewer clears it with
  `task.py unstick CODE` (a new subcommand), which deletes `.stuck`, `.sig` and `.count`.
- Replace the docstring's "there is no terminal state": a repeat failure *is* the terminal state.

Done when: a row that fails twice with the same pest error is `blocked` and never re-sent to the
model; `strategy-pipeline.py status` lists it under `STUCK`.

### W1.2 Red-first baseline before any paid attempt
`scripts/strategy-pipeline.py` (`implement`), `scripts/ogamex` (`prove_row`)

- Before the first model call for a row, run `ogamex prove CODE` once and save the per-step results to
  `attempts/<CODE>.baseline` (see W2.1 for the per-step format).
- If the baseline **passes**, do not implement: close the row through `task.py done`.
- If the baseline fails only on a step marked `suspect` (W2.3), do not implement: block the row as
  `proof suspect`.
- After an attempt, accept it only if the proof passes, or at least one step moved from FAIL to PASS
  and none moved from PASS to FAIL. An attempt whose proof read-out has the same signature as the
  baseline is a failure, even when its own tests pass.

Done when: running the harness on ECON-001 as it stands makes **zero** model calls and blocks the row
with the baseline attached.

### W1.3 Proof-suspect detector
`scripts/strategy-pipeline.py`

- If a row's proof has failed with the same signature after code changes on **three** separate
  attempts or deliveries, mark it `proof suspect` and assign it to the strong lane. The proof, not
  the code, is the likely defect (ECON-001 is the worked example).

### W1.4 Cap the writer's cost
`scripts/strategy-pipeline.py:2271` and `model_call`

- Send `max_tokens` on the implement payload. Size it from the longest accepted answer in
  `attempts/*.answer.md` plus 50%; measure it, do not guess.
- If the provider exposes a reasoning or thinking budget for `deepseek-flash`, set it, and confirm the
  field name against the provider's API documentation. If it does not, record that in the row.
- A `finish_reason` other than `stop` already refuses the answer. Also count it as a failure with
  signature `unfinished`, so W1.1 stops a row that keeps truncating.

Done when: `strategy-pipeline.py status` over a day shows reasoning below 70% of output and fewer
than 5% of calls unfinished. Re-measure, then tune.

### W1.5 Only spend on rows that can move a failing aspect
`scripts/strategy-pipeline.py` (`status` / the attemptable queue)

- A row is attemptable only if its proof names an aspect or invariant that is **currently failing**
  in the latest scorecard or verify run. Rows whose aspect already passes wait.
- Park the current P2 tail that moves no failing aspect (DEF-35..38, IMPL-67..71) as `deferred` with
  that reason. IMPL-67's scenario has `"status": "unverified"`, so it cannot prove anything anyway.

---

## W2: Make the verdict fast and readable (about one day)

### W2.1 `prove` names the failing step and bails
`scripts/ogamex:187-230`

- Run `test:` steps first and stop on the first failure; there is no point planting a situation for
  code whose own test is red. Run the live steps (`situation`, `invariant`, `aspect`) only after
  every test step passes.
- Write one line per step to `proofs/<CODE>.log`, as `STEP <step> PASS|FAIL <seconds>s <first
  failing line>`, then the summary line. Overwrite the per-run block instead of appending identical
  summaries.
- Add `--json` that prints the same data as one JSON object. W1.2 and W1.1 consume this, never
  scraped text.

### W2.2 Isolated situation proofs: seconds, not hours
New Pest Feature tests per situation, under `tests/Feature/Situations/`

The live `cohort-scenario.php` run takes about 217s, depends on live workers and on a saturated
cohort, and its aspect steps need an hour of play. Give each situation a Feature test that plants the
same situation on a fresh `IsolatedAccountTestCase` account with an ordinary economy (not billions)
and asserts on the planner's or action's output. Use real models and services, Pest native, no
Mockery, following `AGENTS.md` → Tests.

- Start with the situations behind failing aspects: `every-planet-builds`, `inbound-attack` (fleet
  save), `debris-beside-planet` (recycle), `inactive-neighbour` (raid; the situation does not exist
  yet and is listed under ATK-001), and `alliance-application`.
- Name the test file after the situation (`EveryPlanetBuildsSituationTest`), so a proof step
  `test:EveryPlanetBuildsSituationTest` is the fast gate and `situation:every-planet-builds` stays as
  the live confirmation.
- Proof order becomes: own test → situation test (seconds) → live situation → invariant/aspect. The
  harness verdict is the first two. The live steps run on the evidence cadence, not on every attempt.
- Change `task.py done` to close on the fast steps and mark the row `delivered` until the live steps
  pass on the next evidence run. Today the live steps hold `done` hostage to the cohort clock.

Done when: `bash scripts/ogamex prove ECON-001` reaches a verdict on its test steps in under 60s, and
each failing aspect above has a situation test.

### W2.3 Invariants that measure the defect, not the symptom
`scripts/verify-cohorts.php`

- **`IDLE_QUEUES`**: a planet counts as idle only when `QueueableBuildingPlanner::steps()` returns a
  step for it **and** it has no building in progress. A planet whose every candidate is refused (by
  `refusal()`) is not idle; print it separately as `SATURATED` (planet full, lab busy), because that
  is a different, real gap: the account should expand (W4). Reuse the planner and `refusal()`; do not
  restate any gate in the script.
- Allow a proof step to be tagged `suspect` in `task.py` (`task.py proof CODE invariant:IDLE_QUEUES?`
  or a column). W1.2 then knows not to spend on it.

Done when: on the current cohort, `IDLE_QUEUES` reports only planets the planner could have built on,
and the ECON-001 proof either passes or names a real planner miss.

### W2.4 Fix the red module suite
- `tests/Feature/AiActivityMarkerTest.php:85` expects one row and finds two. Find out whether the code
  writes a duplicate marker (a bug) or the test is order-dependent, and fix the cause. No merge to
  `main` until `bash scripts/ogamex test` is green.

---

## W3: A world worth measuring (strong lane, about one day)

### W3.1 Reset the cohort's state
The grand cohort's numbers (54M defence, 14B metal, full planets) come from fast-forwarded speed
changes plus a yard with no sink. Measuring the economy on it measures nothing.

- Owner decision: re-seed grand from a fresh universe, or start a second small cohort (3–5 accounts)
  for evidence and leave grand as a soak test. The second is cheaper and keeps history.
- Record the choice and the seed date in `HANDOFF.md`, so scorecards before and after are never
  compared.

### W3.2 The yard must have a stopping rule (ARB-001 + QUAL-003)
- The shipyard wins about 70% of sessions from the fixed score table in
  `CandidateActionFactory::features`. Feed in what the planners already compute (loot per hour of
  flight, debris value, expedition yield), as ARB-001 says.
- Defence on a planet stops growing at a level an experienced player would name (Gate 3), for
  example "the wall makes a raid on this planet unprofitable". Derive it from host prices and the
  planet's own production (Gate 1); never name a unit. Prove it with `invariant:WALL_CEILING` and
  `invariant:NAKED_BESIDE_WALLED` on the fresh cohort.

### W3.3 Verify the Terraformer field rule
`QueueableBuildingPlanner::refusal()` says a terraformer consumes a field, so a full planet can never
build one. Check this against original OGame before relying on it (`AGENTS.md`: game accuracy). If
it is wrong, the host's `consumesPlanetField` for the terraformer is the fix, not the module.

---

## W4: Expansion sinks: the aspects that are actually dead (strong lane)

A saturated account with full planets and banked resources does what a human does: it colonises,
raids, recycles and expands its fleet. These are the north-star rows; take them after W1–W3, in
this order:

1. **ATK-001**: the `inactive-neighbour` situation (host rule: `users.time` older than 7 days), then
   the decided raid-ladder change. Proof: `aspect:raids`, `situation:inactive-neighbour`.
2. **Colonisation**: hourly scorecards show 0. Find out why with the `SATURATED` read-out from
   W2.3. An account with astrophysics headroom and full planets should colonise.
3. **FLEET-002 recycle** and **FLEET-003 proactive save**. FLEET-003 failed three times on an unwired
   `resources/behavior/fleet-save-reaction.yaml`: wire it into the planner that owns the save, or
   drop it.
4. **SIM-001 / ALLY-001**: the `alliance-application` situation, then the ceiling. ALLY-001's tests
   failed three times; read `attempts/ALLY-001.log` before trying again.

Each gets a situation test (W2.2) before any code, so the harness can work it once it is unblocked.

---

## W5: Loop plumbing (owner actions, can run in parallel)

- **`tasks.db` out of git.** Keep `seed.sql` as the shared record (`python3
  plan/tasks/dump_seed.py`), add `tasks.db` to `.gitignore`, `git rm --cached` it.
- **`init-watcher`.** Root-owned, it cannot be stopped from the dev user. Owner: stop it, or limit it
  to `plan/research/ogame/evidence/`. Until then, unverified harness writes can be committed.
- **Root-owned outputs.** The scorecard writer runs as root in the container and leaves files the dev
  user cannot unlink. Run it with the host UID (`docker compose exec -u $(id -u):$(id -g)`), or
  `chown` in the script after writing.
- **`HARNESS-003`**: `schedule:work` in the host entrypoint (already in the handoff).
- **Proof logs.** After W2.1, delete the duplicate summary lines in old `proofs/*.log`, or leave them
  but stop appending duplicates.

---

## Order and exit criteria

| Step | Owner | Exit |
| --- | --- | --- |
| W1.1–W1.5 | any agent | ECON-001 blocked with zero spend; no row attempted twice on one signature; reasoning share measured |
| W2.1, W2.3, W2.4 | any agent | `prove` names the failing step; `IDLE_QUEUES` reads only real misses; suite green |
| W2.2 | any agent, one situation per row | each failing aspect has a test-speed situation proof |
| W3 | owner + strong lane | a cohort worth measuring; yard sessions under half; Terraformer rule verified |
| W4 | strong lane, then harness | raids, recycle, colonisation, fleet save and alliance each non-zero on the hourly scorecard |
| W5 | owner | no binary conflicts, no unverified auto-commits |

The whole plan is done when the harness, left unattended for a day, (1) makes no repeat-signature
attempt, (2) closes at least one row through a fast proof, and (3) the next evidence run shows an
aspect that was at 0 above its floor. Update the State table in `plan/HANDOFF.md` after that run.
