---
name: ai-task-execute
description: Take the next Modules/AI task, change the code that owns the behaviour, and close it only when its proof passes on the cohorts. Use for every implementation task.
---

# One task, proven

A task is **done only when `task.py done` accepts it**, and that command runs the task's proof
itself. A test passing is not done. A file existing is not done. If the proof fails, the task stays
open and you say why.

Run every command from the module root (`Modules/AI`) after `export OGAMEX_RUNNER=local-docker-dev`.
The commands are the whole workflow; do not invent other ways to check your work.

## 1. Pick and read

```
python3 plan/tasks/task.py next                 # the one task to take
python3 plan/tasks/task.py claim CODE yourname  # locks the row AND its files; "NOT claimed" = take another
python3 plan/tasks/task.py show CODE            # notes = evidence and decisions; proof = how it is judged
```

Read the row's `notes` fully: they hold the measurement, the cause when it is known, and any decision
already made. Do not re-decide a decided question. Read the files in `file_ref`. Read nothing else
unless an error sends you there.

Then write one sentence before anything else: **which aspect of play this row moves, and what an
observer would see the account do differently afterwards** ("the account raids an inactive neighbour
it scouted", "every planet's build queue is busy after a login"). If you cannot write it, the row is
not north-star work: `task.py unclaim CODE` and say why.

## 2. See it fail first

Run the proof before you change anything. It must fail, and you must be able to say which step fails
and why in one sentence:

```
bash scripts/ogamex prove CODE
```

If it already passes, the task is stale: run `task.py done CODE` and stop.

To see *why* it fails, ask the tools before reading more code. Each answers in seconds:

```
bash scripts/ogamex stories                    # every Situation-kit story, PASS/FAIL, with the kit's diagnosis
bash scripts/ogamex why subject|PLAYER_ID      # live: what the next session sees, what is (not) offered, the decision
bash scripts/ogamex pulse 30                   # live: what the cohort chose, did and had refused in 30 minutes
bash scripts/ogamex account PLAYER_ID          # live: one account's planets, queues, fleets, work, refusals
bash scripts/ogamex economy PLAYER_ID          # per planet: building, would queue X, or the gate refusing it
bash scripts/ogamex situation NAME             # plant it on the cohort and drive the account in-process
bash scripts/ogamex scorecard                  # every aspect, its floor, and the file that owns it
```

Read a failure the same way everywhere: *refused* (an executor gate said no), *not offered / not
published* (no planner produced it: fix the planner), *ranked but outscored* (fix the comparison).

When the kit test passes but live fails, the kit is missing something the live world has: plant it
(`crowd(n)` for a universe of players, `stockEveryPlanet`, `colony`) until the kit fails like live,
then fix the code.

## 3. Change the owner, smallest change

- Edit the class that already makes this decision (the `file_ref`). Never add a class beside it.
- Numbers that decide behaviour go in a YAML file under `resources/behavior/` that the class loads by name.
- Objects, prices and requirements come from the host (`ObjectService`, the planet and player services).
  Never write a building, ship or tech name or id as a rule.
- No `else`/`elseif`; early returns or `match`. Resolve module classes with `app()`, never `new`.
- Prove behaviour with the Situation kit (`tests/Support/Situation.php`, examples in
  `tests/Feature/Situations/`): plant, `session()`, expect. Add or change a Pest test in `tests/Feature`
  that drives the real path. Add it to the proof:
  `python3 plan/tasks/task.py proof CODE test:YourTest <the existing steps>`.

## 4. Check fast, in this order

```
bash scripts/ogamex test-one YourTest          # seconds: your test
bash scripts/ogamex test-one ExecuteIntentTest # any test that names a class you touched
bash scripts/ogamex situation NAME             # the situation from the proof, on the cohort
bash scripts/ogamex gate                       # over-engineering gate, must be clean
```

Fix what fails. Do not move on with a red step.

## 5. Prove and close

Aspect steps need the cohort to play on your change for at least an hour. Commit, let it play, then:

```
bash scripts/ogamex prove CODE
python3 plan/tasks/task.py done CODE           # runs the proof again; only closes if it passes
```

If `aspect:` still fails after the cohort played, your change did not change what accounts do. Say so
in the row (`task.py block CODE "<what the scorecard shows>"`), do not close it.

## Never get in another agent's way

Several agents and the harness work this checkout at once. These rules are what keeps them apart:

- **Edit only files you hold.** `claim` locked the row's files. Need another file (a test, a YAML file)?
  `python3 plan/tasks/task.py lock CODE path/to/file` first. "NOT locked" means another agent is in it:
  stop and take a different row, never edit it anyway.
- **Run tests only through `scripts/ogamex`** (`test-one`, `test`, `prove`). They wait for the shared
  test-database lane; a raw `pest` call collides with the harness and both fail for no reason.
- **Commit only your files:** `git add <the files you hold>` then `git commit`. Never `git add -A`,
  `git add .`, `git commit -a`, `git stash`, `git checkout -- <file>`, `git reset` or `git clean`: each one
  takes or destroys another agent's unfinished work.
- **Give the row back when you stop:** `task.py done CODE`, `task.py block CODE "<why>"` or
  `task.py unclaim CODE`. Each releases your file locks. A claim left for 6 hours is reaped.
- **Never touch** `plan/research/ogame/claims/`, `model-slots/` or a row someone else holds.

## Proof steps

| Step | What it runs | Passes when |
| --- | --- | --- |
| `test:Name` | `./vendor/bin/pest --filter=Name` in the dev stack | the test file is green |
| `situation:name` | `scripts/cohort-scenario.php run name` on the cohort | the planted situation got the expected work |
| `aspect:name` | `scripts/play-scorecard.php --aspect=name` on the cohort | the aspect meets its floor since the change |
| `invariant:NAME` | `scripts/verify-cohorts.php` on the cohort | the invariant is not violated |
| `harness:self-check` | `scripts/strategy-pipeline.py --self-check` | the harness checks pass |

A code row's proof needs at least one `situation:`, `aspect:` or `invariant:` step (`harness:` only for
rows that keep the measuring loop sound); `test:` steps are added on top, never alone.
`bash scripts/ogamex situation list` names every situation; `bash scripts/ogamex scorecard` shows every
aspect with its floor and the file that owns it.

## Handoff (exactly this)

- Row code, and the last `PROOF:` line verbatim.
- Files changed.
- The failing step before, the passing step after.
- Anything left open, and why.
