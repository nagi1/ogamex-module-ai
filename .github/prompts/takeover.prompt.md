---
description: Take over the AI module on the cohort machine — merge, restart, verify, then work rows on the north-star path and report
---

# Take over: merge, restart, work on the north star, report

You run on the machine that hosts OGameX and the AI cohorts. OGameX root: `/home/nagi/code/ogamex-next`.
Module: `Modules/AI`. Docker stacks: `local-docker-dev/` (`docker-compose.grand.yml`, `docker-compose.pve.yml`).
From `Modules/AI`, always `export OGAMEX_RUNNER=local-docker-dev`.

Before anything, read in full: `AGENTS.md`, `plan/HANDOFF.md`, `.github/skills/ai-task-execute/SKILL.md`.
They are the rules. When this prompt and those files disagree, those files win.

Record every command and its output in `plan/research/ogame/evidence/takeover-$(date -u +%Y%m%d-%H%M)/LOG.md`.
On any failure: record it verbatim and stop that part — never improvise a workaround that edits code
outside the row you hold, never `git add -A`, `stash`, `reset`, `clean` or force-push.

## Part A — merge and start (once)

1. Stop what writes to the checkout:
   `pkill -f harness-live.sh; pkill -f strategy-pipeline.py`. Stop the auto-commit watcher (`init-watcher`)
   or reconfigure it to commit only `plan/research/ogame/evidence/`. Record which you did.
2. Commit pending runtime state, if any: `git status --short`; if only `plan/tasks/*` or
   `plan/research/ogame/attempts/*` changed: `git add plan/tasks plan/research/ogame/attempts && git commit -m "harness: runtime state"`.
   Anything else modified: record it and stop.
3. Merge:
   ```
   git fetch origin
   git checkout main && git pull origin main
   git merge --ff-only origin/claude/friendly-hypatia-hve9xk || git merge origin/claude/friendly-hypatia-hve9xk
   ```
   If the only conflict is `plan/tasks/tasks.db`: `git checkout --theirs plan/tasks/tasks.db && git add plan/tasks/tasks.db && git commit --no-edit`.
   Any other conflict: record it and stop. Then `git push origin main`.
4. Load the code into the cohorts:
   `for u in grand pve; do (cd ../../local-docker-dev && docker compose -f docker-compose.$u.yml exec -T ogamex-app sh -lc "cd /var/www && php artisan queue:restart"); done`
   Record the UTC time.
5. Verify, all must pass before Part B:
   - `python3 scripts/strategy-pipeline.py --self-check`
   - `bash scripts/ogamex test` (the whole module suite, green)
   - `bash scripts/ogamex gate`
   - `python3 plan/tasks/task.py reap` and `python3 scripts/strategy-pipeline.py status` (record; it must list no row as OFF THE NORTH STAR)
   If a test fails, record it and stop: do not "fix" tests here.
6. Start the harness: `nohup bash scripts/harness-live.sh >/dev/null 2>&1 &` and confirm with
   `pgrep -af harness-live.sh` and the first lines of `/tmp/harness-live.log`.

## Part B — work rows (repeat until a stop condition)

Follow `.github/skills/ai-task-execute/SKILL.md` exactly, one row at a time, as assignee `copilot`:

1. `python3 plan/tasks/task.py next` → `claim CODE copilot` → `show CODE`.
2. Write the one-sentence north-star statement (which aspect, what an observer sees change). If you
   cannot, `unclaim` and take the next row.
3. `bash scripts/ogamex prove CODE` — see it fail and name the failing step.
4. Change only the files you hold (`task.py lock CODE <path>` for any extra file). The smallest change in
   the class that owns the decision; numbers in `resources/behavior/*.yaml`; no `else`; `app()` not `new`;
   a Feature test that drives the real path, added to the proof.
5. `bash scripts/ogamex test-one <YourTest>`, the tests naming classes you touched, `situation <name>` from
   the proof, `bash scripts/ogamex gate` — all green.
6. Commit only your files: `git add <files you hold> && git commit -m "<CODE>: <what the account now does>"`,
   then `git push origin main`.
7. Restart the cohort workers (step A4). Aspect steps need the cohort to play at least an hour on the
   change: move on to the next row and come back later with `bash scripts/ogamex prove CODE` and
   `python3 plan/tasks/task.py done CODE`. A row whose aspect still fails after the cohort played is not
   done: `task.py block CODE "<what the scorecard shows>"`.

Take rows in the order `task.py next` gives. Do not take a row with an empty `file_ref` (strong lane:
`ECON-001`, `ARB-001`, `SIM-001`, `AUTH-001`) — those wait for the reviewer, who works from your evidence.

Stop conditions: three rows in a row you had to block or unclaim; any harness or cohort error you cannot
attribute to your own row; or four hours of work. Then go to Part C.

## Part C — evidence and report

1. Run `.github/prompts/cohort-evidence.prompt.md` (wait the full hour in its step 8).
2. Append to your LOG.md: rows closed (with their `PROOF: PASS` lines), rows blocked and why, rows
   unclaimed and why, the scorecard before Part B vs after, anything surprising.
3. Commit only `plan/research/ogame/evidence/` and push. Reply with the commit hash and the paths of
   LOG.md and the evidence SUMMARY.md, then stop: the reviewer takes it from there.
