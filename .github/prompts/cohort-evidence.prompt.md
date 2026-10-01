---
description: One-shot evidence run on the machine that hosts the cohorts — collect everything the reviewer needs, change no code, push the report
---

# Cohort evidence run — collect, do not fix

You are on the machine that runs OGameX and the AI cohorts. Your only job is to **collect evidence and
push it**. Do not fix, refactor or "improve" anything, even if you see a bug: report it verbatim. The
reviewer reads your report and makes the changes.

Paths: OGameX root is `/home/nagi/code/ogamex-next` (it contains `artisan`), the module is
`Modules/AI` inside it, the Docker stacks are in `local-docker-dev/` (`docker-compose.grand.yml`,
`docker-compose.pve.yml`). Run module commands from `Modules/AI` with `export OGAMEX_RUNNER=local-docker-dev`.

Record **every command and its full output** (stdout and stderr). When a command fails, record the error
and carry on with the next step; never stop the run on a failure, never retry with a "fixed" command.

## 0. Prepare

1. `cd Modules/AI && git status --short && git log --oneline -3` — record. If there are uncommitted
   changes, **do not discard or stash them**: record `git status` and `git diff --stat`, then continue.
2. Is the harness running? `pgrep -af harness-live.sh; pgrep -af strategy-pipeline.py` — record. If it
   is running, stop it: `pkill -f harness-live.sh; pkill -f strategy-pipeline.py` (the new branch
   changes the harness itself).
3. `git fetch origin claude/friendly-hypatia-hve9xk && git checkout claude/friendly-hypatia-hve9xk && git pull origin claude/friendly-hypatia-hve9xk`.
   If checkout refuses because of local changes, record the message and STOP the whole run there —
   report and push only the report (step 9) on a new branch `evidence/<date>`.
4. Create the report folder: `EVID=plan/research/ogame/evidence/$(date -u +%Y%m%d-%H%M) && mkdir -p $EVID`.
   Save each step's raw output as `$EVID/NN-name.txt`.

## 1. Before the new code reaches the cohorts (baseline)

The cohort workers still hold the old classes in memory, so this is the "before":

- `PROVE_UNIVERSE=grand bash scripts/ogamex scorecard --hours=24 --save=baseline` → `01-scorecard-grand-before.txt`
- `PROVE_UNIVERSE=pve bash scripts/ogamex scorecard --hours=24 --save=baseline` → `02-scorecard-pve-before.txt`
- For both universes, the cohort read:
  `cd ../../local-docker-dev && docker compose -f docker-compose.grand.yml exec -T ogamex-app sh -lc "cd /var/www && php artisan tinker --execute=\"require '/var/www/Modules/AI/scripts/verify-cohorts.php';\""`
  (and the same with `pve`) → `03-verify-grand.txt`, `04-verify-pve.txt`

## 2. Load the new code into the cohorts

For grand and pve: `docker compose -f docker-compose.<u>.yml exec -T ogamex-app sh -lc "cd /var/www && php artisan queue:restart"`.
Record the time (UTC) you did it — the reviewer dates every "after" from it.

## 3. Tests (dev stack)

- `bash scripts/ogamex test-one ExecuteIntentTest` → `05-test-execute-intent.txt`
- `bash scripts/ogamex test-one AiCapabilityPublicationTest` → `06-test-capability.txt`
- `bash scripts/ogamex test` (the whole module, may take minutes) → `07-test-module.txt`
- `bash scripts/ogamex gate` → `08-gate.txt`
- `bash scripts/ogamex artisan --version` and `php -v` inside the app container → `09-versions.txt`

## 4. Situations (seconds each, they clean up after themselves)

- `PROVE_UNIVERSE=grand bash scripts/ogamex situation list` → `10-situations-list.txt`
- `PROVE_UNIVERSE=grand bash scripts/ogamex situation all` → `11-situations-grand.txt`
- `PROVE_UNIVERSE=pve bash scripts/ogamex situation all` → `12-situations-pve.txt`

## 5. Harness and DeepSeek

- `python3 plan/tasks/task.py next` and `python3 scripts/strategy-pipeline.py status` → `13-harness-status.txt`
- `python3 scripts/strategy-pipeline.py --self-check` → `14-self-check.txt`
- `python3 scripts/strategy-pipeline.py model-check` (one tiny paid call) → `15-model-check.txt`
- `tail -n 300 /tmp/harness-live.log` → `16-harness-log-tail.txt`
- `ls plan/research/ogame/attempts/*.log | wc -l` and, for the 10 newest attempt logs, the first 15 lines
  of each → `17-recent-attempts.txt`
- How do your own DeepSeek agents run? Record the command or config that starts them, how many run at
  once, and whether they use `python3 plan/tasks/task.py claim` → `18-agents.txt`

## 6. Host facts the reviewer cannot see (read-only)

From the OGameX root, record the exact source (file path + the relevant lines) for each:

1. `AllianceApplication` model: table name, `$fillable`/columns, status constants, and its migration.
2. How the host decides a player is **inactive** (the `i`/`I` markers): the threshold(s) and the column
   (e.g. `users.time`, `last_activity`). `grep -rn "inactive" app/ --include=*.php | head -40`.
3. `BuddyRequest` model: table and columns.
4. Mission type ids: for each class in `app/GameMissions/*Mission.php`, its `getTypeId()` value.
5. `fleet_missions`, `building_queues`, `research_queues`, `unit_queues`, `chat_messages`: run
   `SHOW CREATE TABLE <t>` in the grand database for each.
6. Highscore: the table and how often it is recalculated (scheduler entry).
7. `local-docker-dev/docker-compose.grand.yml`: the services list and how many queue workers run.

Save as `19-host-facts.md`.

## 7. Cohort size and activity (grand and pve)

In each cohort database: number of enabled `ai_profiles`, planets per account (min/avg/max),
`ai_work_items` grouped by `kind, state` for the last 24h, and the newest 20 rows of `ai_decision_traces`
(`player_id, selected_action, selected_reason, created_at`) → `20-cohort-sql-grand.txt`, `21-cohort-sql-pve.txt`.

## 8. After one hour (if you can wait)

Wait 60 minutes after step 2, then repeat step 1's scorecards without `--save`:
`... scorecard --hours=1 --compare=<the baseline snapshot file step 1 printed>` → `22-scorecard-grand-after.txt`,
`23-scorecard-pve-after.txt`, and `bash scripts/ogamex prove ECON-001` → `24-prove-econ-001.txt`.
If you cannot wait, write "skipped" in the summary.

## 9. Report and push

Write `$EVID/SUMMARY.md` with:
- the UTC time of every step, especially the queue restart in step 2;
- a table: step → PASS / FAIL / ERROR → one-line reason;
- every error message verbatim (first 30 lines each);
- anything you noticed but did not touch.

Then commit **only the evidence folder** and push to the same branch:

```
git add plan/research/ogame/evidence/
git commit -m "evidence: cohort run $(date -u +%Y-%m-%d)"
git push origin claude/friendly-hypatia-hve9xk
```

Never `git add -A`, never commit other files, never force-push. If the push is rejected, `git pull --no-rebase`
then push again; if that conflicts, push to a new branch `evidence/<date>` instead and say so.

Finally, restart the harness on the new code so the cohorts keep being worked and proven:
`nohup bash scripts/harness-live.sh >/dev/null 2>&1 &` — and record that you did.
