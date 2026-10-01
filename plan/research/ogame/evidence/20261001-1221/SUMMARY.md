# Cohort evidence run 2 — 2026-10-01 (after the fix commit)

One-shot evidence collection on the machine that hosts the cohorts. Collect only: no code was fixed,
refactored or edited. Branch: `claude/friendly-hypatia-hve9xk` @ `9ce5a9c`
("fix what the first cohort evidence run found").

## Step times (UTC)

| Step | When (UTC) | What |
|------|-----------|------|
| 0    | 12:21      | prepare: pull 9ce5a9c, stop harness, EVID folder `plan/research/ogame/evidence/20261001-1221` |
| 1    | 12:22–12:24 | baseline scorecards (grand/pve) + cohort verify (grand/pve) |
| 2    | **12:24:14** | **queue:restart on grand and pve (the "after" clock starts here)** |
| 3    | 12:24–12:26 | test-one x2, full module test, gate, versions |
| 4    | 12:26–12:39 | situation list + situation all (grand, pve), per-scenario timings |
| 5    | 12:39–12:41 | harness status, self-check, model-check, log tail + canary, attempts, agents |
| 6    | 12:41       | host facts (read-only, unchanged from run 1) |
| 7    | 12:41       | cohort SQL (grand, pve) |
| 8    | 12:42       | after-scorecards + prove ECON-001 (owner said 10 min, not a full hour) |
| 9    | ~12:44      | SUMMARY.md, commit, push, restart harness |

## Step -> verdict

| Step | Verdict | One-line reason |
|------|---------|-----------------|
| 0    | PASS*   | pulled 9ce5a9c (tasks.db conflict resolved by backup+restore, nothing lost) |
| 1    | PASS    | scorecards run; cohort verify still FAILs |
| 2    | PASS    | queue restart broadcast on grand + pve |
| 3    | FAIL    | full module test collects now but 1 test fails (TurtleDefenceStaleThresholdsTest) |
| 4    | ERROR   | every-planet-builds still FAIL; debris-field aborts on a leftover row (both universes) |
| 5    | PASS    | all harness/DeepSeek commands ran; canary lines captured |
| 6    | PASS    | host facts collected (read-only) |
| 7    | PASS    | cohort SQL collected for grand + pve |
| 8    | FAIL    | prove ECON-001 still FAIL (every-planet-builds 0/12, IDLE_QUEUES) |
| 9    | PASS    | commit + push + restart harness |

\* step 0 "PASS*": the first `git pull` refused (local tasks.db modified); the local file was backed up
to `/tmp/tasks.db.local-harness-20261001-122124.bak` (sha256 85817c8e…), restored to HEAD, then the
pull fast-forwarded cleanly. Staged attempt files (ATK-001, FLEET-003, HARNESS-003) were left untouched.

## Errors verbatim

### Full module test (`bash scripts/ogamex test`) — 1 failure
```
No stale test sessions to reap.
{"tool":"pest","result":"failed","tests":79,"passed":78,"assertions":235,"duration_ms":3655,"failed":1,
 "failures":[{"test":"P\\Modules\\AI\\tests\\Feature\\Ai\\TurtleDefenceStaleThresholdsTest::__pest_evaluable_it_leaves_the_superseded_defence_tallies_out_of_the_module_source",
 "file":"/var/www/Modules/AI/tests/Feature/Ai/TurtleDefenceStaleThresholdsTest.php","line":26,
 "message":"Expecting [] not to be empty ."}]}
```

### `situation all` — debris-field aborts on a leftover row (grand and pve)
Grand:
```
In Connection.php line 857:
  SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-8-10'
  for key 'debris_fields.debris_fields_galaxy_system_planet_unique'
  (… insert into `debris_fields` … values (1, 8, 10, 400000, 200000, 0, …))
```
Pve:
```
In Connection.php line 857:
  SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-16-8'
  for key 'debris_fields.debris_fields_galaxy_system_planet_unique'
  (… insert into `debris_fields` … values (1, 16, 8, 400000, 200000, 0, …))
```
The `lootable-neighbour` scenario never ran (the run aborted on the exception above).

### First `git pull` refusal (step 0)
```
Updating e2a0a7f..9ce5a9c
error: Your local changes to the following files would be overwritten by merge:
        plan/tasks/tasks.db
Please commit your changes or stash them before you merge.
Aborting
```

### prove ECON-001 — aspect gate
```
--- aspect:economy
too early: the cohort has played under an hour on this change
```

## Step 4 — per-scenario timings and "reverted N row(s)" lines

grand (`situation all`):
- idle-planet        PASS 19s   — cleaned up: 0 planted row(s) reverted
- every-planet-builds FAIL 190s — cleaned up: 0; read-back: 0 of 12 planet(s) of account 12 building or ordered
- inbound-message    FAIL 183s  — cleaned up: 1 planted row(s) reverted; read-back: 0 exchange(s), 0 reply message(s)
- inbound-attack     PASS 86s   — cleaned up: 1 planted row(s) reverted; read-back: 1 fleet-save work item(s) (6)
- debris-field       ERROR (duplicate '1-8-10') — abort
- lootable-neighbour NOT RUN (aborted before it)

pve (`situation all`):
- idle-planet        PASS 24s   — cleaned up: 0
- every-planet-builds FAIL 192s — cleaned up: 0; read-back: 2 of 12 planet(s) of account 2 building or ordered
- inbound-message    PASS 2s    — cleaned up: 1; read-back: 1 exchange(s), 1 reply message(s)
- inbound-attack     PASS 48s   — cleaned up: 1; read-back: 1 fleet-save work item(s) (6)
- debris-field       ERROR (duplicate '1-16-8') — abort
- lootable-neighbour NOT RUN

No scenario exceeded 4 minutes, so no Ctrl-C was needed. No start-of-run
"reverted N row(s) a previous run planted…" line was printed in either universe.

## [canary] lines (from /tmp/harness-live.log, most recent first)

```
[canary] reloaded onto the code on disk
[canary] last 10 min: sessions 27, accepted 0, rejected 7, orders 0, failed 0
[canary] FAIL the accounts were refused or broke: 7 rejected, 0 failed
```
Full canary history (including the long run of `VERDICT passed` lines) is in `16-harness-log-tail.txt`.
The FAIL above is the state at the moment the harness was stopped in step 0.

## Key numbers (grand / pve)

- Baselines: grand **7/15** (FAIL espionage raids recycle fleet_save social chat alliance fleet_breadth);
  pve **9/15** (FAIL espionage raids fleet_save social chat alliance).
- After (~17 min): grand **7/15** (FAIL espionage raids recycle colonisation fleet_save social chat alliance);
  pve **10/15** (FAIL espionage raids recycle fleet_save alliance) — social + chat now PASS on pve.
- Cohort verify: grand FAIL (NAKED_BESIDE_WALLED WALL_CEILING ALLIANCE_SHARE IDLE_QUEUES);
  pve FAIL (NAKED_BESIDE_WALLED WALL_CEILING IDLE_QUEUES).
- `prove ECON-001`: **FAIL** — ExecuteIntentTest 16/16, AiCapabilityPublicationTest 17/17,
  situation `every-planet-builds` FAIL (0 of 12, 188s), aspect `economy` skipped (too early),
  invariant `IDLE_QUEUES` FAIL.
- Fleet save now works in situations (`inbound-attack` PASS on both) but the 1h scorecard still shows
  fleet_save 0: the situation-planted save is the only one; ordinary play does not fleet-save.
- Enabled profiles: grand 20, pve 19. Planets/account: grand 4..9.95..13, pve 1..9.16..14.
- Harness: 0 code rows proven; 19 ready; ECON-001 / HARNESS-002 / HARNESS-004 / QUAL-6 delivered but not proven.
- Attempt logs: 129. Recent failures include ATK-001 (AiQueueModuleTestCase not found), FLEET-003
  (InboundHostileFleetSaveTest), SOC-001 ("nothing owed" is not a valid AiSocialResponseReason),
  ALLY-001 (alliance share-limit test), IMPL-70 (alliance_life.php missing), etc.

## What was noticed but NOT touched

1. `every-planet-builds` still fails after 9ce5a9c: the "fix" did not make a login fill every free
   build queue (grand 0/12, pve 2/12).
2. The `debris-field` scenario is blocked by a pre-existing `debris_fields` row (unique key collision)
   at (1,8,10) in grand and (1,16,8) in pve — planted by an earlier harness/situation run and never
   cleaned up. It aborts `situation all` before `lootable-neighbour`.
3. `TurtleDefenceStaleThresholdsTest` now collects but its assertion "Expecting [] not to be empty"
   fails — the superseded-defence-tally scan returns empty.
4. Local `plan/tasks/tasks.db` (harness runtime churn) was backed up to
   `/tmp/tasks.db.local-harness-20261001-122124.bak` and restored to HEAD so the pull could
   fast-forward; nothing was discarded (see step 0 above).
5. Staged harness attempt files (ATK-001, FLEET-003, HARNESS-003) were left in the index, uncommitted,
   per "commit only plan/research/ogame/evidence/".

## Concurrent activity noticed during the run (not touched by me)

An auto-commit watcher (host process `init-watcher`, plus the running DeepSeek harness workers) was
active on this machine during the run and made two local commits on top of `9ce5a9c`:

- `10279b2` "Refactor raid decision logic for mid-phase accounts and enhance fleet save functionality"
  (12:24:05 UTC) — committed the staged harness attempt files (ATK-001, FLEET-003, HARNESS-003).
- `e96a5fc` "Add scorecards and verification reports for Ogamex cohort analysis" (12:24:22 UTC) —
  committed part of this run's evidence folder (files 00–04) plus the two baseline snapshot JSONs.

Consequence: the code under measurement changed mid-run (raid + fleet-save code landed at 12:24:05,
seconds before the 12:24:14 queue restart), and this branch is 2 commits ahead of `origin` before
my own evidence commit. Recorded for the reviewer; nothing here was edited or reverted by me.
