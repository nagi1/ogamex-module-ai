#!/usr/bin/env bash
# Keeps the harness working until a peak window opens: no budget, no stopping after one pass.
#
# One pass: pick the ready north-star rows, let IMPL_WORKERS writers implement disjoint slices of them
# (each verifies its own slice with its own tests), then, once HARNESS_BATCH rows are delivered or nothing
# is left to attempt, read the cohort, restart the canary and prove the whole batch live. Slow checks
# run per batch, never per row. Only a peak window stops the loop (HARNESS_IGNORE_PEAK=1 skips it).
# Worker count is not a spend control -- MODEL_CONCURRENCY is, enforced across processes by the pipeline.
# DEV TOOLING — never run in a universe.
#
# `-u` is not cosmetic: python buffers stdout when it is redirected to a file, so without it the log
# stays empty until the process exits and the watch page shows nothing happening.
set -uo pipefail

# Children die with the shell. A park process outliving its harness kept publishing "parked" over a
# harness that was working, so the dashboard reported a stopped run for a running one.
trap 'kill 0' EXIT

cd "$(dirname "$0")/.." || exit 1
LOG=/tmp/harness-live.log
# Keep the output: the watch page shows the last hour from this, and /tmp does not survive a reboot.
pgrep -f 'scripts/harness-log.py' >/dev/null || nohup python3 -u scripts/harness-log.py "$LOG" >/dev/null 2>&1 &
# Tests in the dev stack, situations and scorecards in the cohorts: `scripts/ogamex prove` needs both.
export OGAMEX_RUNNER="${OGAMEX_RUNNER:-local-docker-dev}"
COMPOSE_DIR=../../local-docker-dev
# Poll interval of the verifier and of a writer with nothing to claim. A writer holding a row never waits.
IDLE_INTERVAL=${IDLE_INTERVAL:-20}

ready_rows() {
    python3 - <<'PY'
import glob
import os
import sqlite3

# Ordered the way the task DB's own usage doc says to work it: priority, then the order the tasks
# were written. Ordering by id alone buried every gameplay defect behind the wiki backlog (found
# 30 Sep 2026: 157 todo, 120 of them P3 slices, so a P1 was never reached while the shards ground
# through P3). The `ready_tasks` view also keeps a shard off work whose dependencies are unmet.
#
# A row the pipeline planned carries a proposal; a row written by hand (a cohort defect, an owner
# directive) carries the file it must edit in `file_ref` and its evidence in `notes`. Both are
# attemptable work. Gating on "a proposal exists" meant the attack, social, alliance and fleet rows
# raised from the cohort read were never picked up at all.
proposals = {os.path.basename(path)[:-3] for path in glob.glob('plan/research/ogame/proposals/*.md')}
rows = sqlite3.connect('plan/tasks/tasks.db').execute(
    # Only code rows: a doc or review row handed to the PHP writer can only be refused (DOC-8 spent
    # its attempts explaining it could not rewrite a truncated markdown register).
    "select code, coalesce(file_ref, '') from ready_tasks where kind = 'impl' and priority in ('P0', 'P1', 'P2') "
    # North star: only rows judged by what an account does (or by the loop that judges it).
    "and (proof like '%aspect:%' or proof like '%situation:%' or proof like '%invariant:%' or proof like '%harness:%') "
    # Within a priority: a row with a fast proof (a `test:` step) first, because its verdict takes
    # seconds and its slice can be delivered tonight; then the row that has failed least, so one hard
    # row does not hold a worker while easier value waits.
    "order by priority, proof like '%test:%' desc, id"
)
def attempts(code):
    path = f'plan/research/ogame/attempts/{code}.count'
    return int(open(path).read().strip() or 0) if os.path.exists(path) else 0
rows = sorted(rows, key=lambda row: attempts(row[0]))  # stable: keeps the priority/fast-proof order within ties
print('\n'.join(code for code, file_ref in rows if code in proposals or file_ref))
PY

}

# A writer takes the first ready row it can claim and keeps it until it is delivered: `implement` has no
# turn, time or attempt budget, so it returns only when the row is delivered, blocked by its own
# `give_up`, or paused by a provider error or a peak window (exit 3). Rows another writer holds are skipped.
writer() {
  while true; do
    # One agent in the tree at a time: while the Claude lane is running on a row, no DeepSeek writer starts
    # one (their edits and the lane's landed in the same files, FacilityChain and the planners).
    # One agent in the tree at a time: a writer waits only while a Claude run is actually going. Claude takes
    # a hard row now and then; the writers do the rest.
    while pgrep -f '^\S*python3? scripts/claude-lane\.py' >/dev/null; do sleep 20; done
    started=$(date +%s)
    for code in $(ready_rows); do
      HARNESS_WORKER="impl-$1" python3 -u scripts/strategy-pipeline.py implement "$code"
      if [ $? -eq 3 ]; then
        python3 -u scripts/strategy-pipeline.py wait-until-offpeak
        break
      fi
    done
    # Nothing claimable this round (all held, or the queue is empty): poll, do not spin.
    [ $(( $(date +%s) - started )) -lt 10 ] && sleep "$IDLE_INTERVAL"
  done
}

{
  echo "=== harness started $(date -u '+%F %T') UTC ==="

  # A park left behind by an earlier harness would keep overwriting the status this one publishes.
  pkill -f 'strategy-pipeline.py wait-until-offpeak' 2>/dev/null || true

  # Boot the canary once, outside the pass: from here on a check is a few queries, not a universe.
  bash scripts/canary.sh up || echo "canary unavailable; the live gate will report it"

  # One agent at a time, not a setting: the floor lock (scripts/agent_floor.py) keeps a second agent out
  # of the tree, so a larger IMPL_WORKERS or MODEL_CONCURRENCY would only start idle processes.
  export MODEL_CONCURRENCY=1
  writer 0 &

  # The verifier. It never waits on a writer: it proves delivered rows as they appear, once a batch is
  # ready, nothing is left to write, or HARNESS_VERIFY_EVERY seconds have passed. A failed proof reopens
  # the row and a writer picks it up again.
  while true; do
    python3 -u scripts/strategy-pipeline.py peak-gate
    if [ $? -eq 3 ]; then
      echo "=== parked: peak window $(date -u '+%F %T') UTC ==="
      bash scripts/canary.sh down >> "$LOG" 2>&1 || true
      for universe in ${HARNESS_UNIVERSES:-grand}; do
        echo "--- situations on $universe during the park $(date -u '+%F %T') UTC ---"
        PROVE_UNIVERSE=$universe bash scripts/ogamex situation all || true
      done
      python3 -u scripts/strategy-pipeline.py wait-until-offpeak
      bash scripts/canary.sh up >> "$LOG" 2>&1 || true
      echo "=== peak over, resuming $(date -u '+%F %T') UTC ==="
      continue
    fi
    # Claims a vanished agent or a killed worker left behind.
    python3 plan/tasks/task.py reap

    queue_state=$(python3 -u scripts/strategy-pipeline.py status)
    ready_now=$(printf '%s\n' "$queue_state" | sed -n 's/^READY: //p')
    unproven_now=$(printf '%s\n' "$queue_state" | sed -n 's/^UNPROVEN: //p' | wc -w)
    last_verify=$(cat /tmp/harness-last-verify 2>/dev/null || echo 0)
    since=$(( $(date +%s) - last_verify ))
    if [ "$unproven_now" -eq 0 ] && [ "$since" -lt "${HARNESS_READ_EVERY:-1800}" ]; then
      sleep "$IDLE_INTERVAL"; continue
    fi
    if [ "$unproven_now" -gt 0 ] && [ "${ready_now:-0}" -gt 0 ] && [ "$unproven_now" -lt "${HARNESS_BATCH:-10}" ] \
       && [ "$since" -lt "${HARNESS_VERIFY_EVERY:-900}" ]; then
      sleep "$IDLE_INTERVAL"; continue
    fi
    date +%s > /tmp/harness-last-verify

    # The behaviour board: every Situation-kit story against the code as it stands, in seconds. Its
    # summary line is the cheapest "is the account playing better than last pass" signal there is.
    echo "--- behaviour board $(date -u '+%F %T') UTC ---"
    python3 scripts/stories.py 2>&1 | grep -E "stories pass|^FAIL" || echo "behaviour board did not run"
    # The cohort's last 15 minutes: what reached the host, what was refused, and the worker backlog that
    # says whether a live verdict this pass reads the code or the workers' lag.
    bash scripts/ogamex pulse 15 2>&1 | head -12 || echo "pulse did not run"

    # The half that scenarios cannot prove: that a real account actually plays. A persistent canary
    # universe (never grand or pve) has been working while this pass wrote code; it is reloaded onto
    # that code, then asked what it did since the last check. Booting and sleeping out a window inside
    # every pass made the verification the bottleneck, which is why it is incremental now. The check
    # itself runs at the end of the pass, after the cohort read (see below).

    # The cohorts are grand and pve — the dev stack is not a cohort at all, and measuring there
    # says nothing about a live universe (learned 28 Sep 2026).
    #
    # This runs BEFORE the canary, because the canary's failure parks the pass: while a canary reads
    # red the cohort verdict used to stop being taken at all, and the quality report the operator
    # reads went stale for as long as the canary stayed unhappy (found 30 Sep 2026: 45 minutes of
    # stale verdicts, and 100 QUALITY FAILED lines that no longer matched the last cohort read).
    # Reading the cohorts is free and read-only, so it is never the thing to skip.
    for universe in ${HARNESS_UNIVERSES:-grand}; do
      echo "--- live cohort verification: $universe $(date -u '+%F %T') UTC ---"
      HARNESS_WORKER=pass python3 -u scripts/strategy-pipeline.py publish "reading the $universe cohort" || true
      # Captured rather than piped straight through, so the quality verdict can be read as well as
      # printed. Liveness counters alone called a cohort healthy while it walled one planet and left
      # 125 naked, so the verdict gets its own line in the log.
      cohort_output=$( (cd "$COMPOSE_DIR" && docker compose -f "docker-compose.$universe.yml" exec -T ogamex-app sh -lc \
        "cd /var/www && php artisan tinker --execute=\"require '/var/www/Modules/AI/scripts/verify-cohorts.php';\"") 2>&1 )
      # The scorecard is the other half of the read: invariants say what is shaped wrong, aspects say
      # what a player does that these accounts never do. Both verdicts go to the same quality file.
      cohort_output="$cohort_output
$(PROVE_UNIVERSE=$universe bash scripts/ogamex scorecard --hours=6 2>&1)"
      # Primary read: a short simulation on a copy of the cohort, so the verdict judges the current code over real
      # play-hours instead of whatever the live cohort managed since the last restart. Its QUALITY/PLAY lines come
      # first, which is the verdict `quality` reads; the live read stays below as the secondary line.
      # HARNESS_READ_SIM_HOURS=0 turns this off.
      if [ "${HARNESS_READ_SIM_HOURS:-6}" -gt 0 ]; then
        sim_read=$(SIM_DB=ogamex-sim-read OGAMEX_RUNNER=local-docker-dev PROVE_UNIVERSE=$universe bash scripts/ogamex sim \
          --hours="${HARNESS_READ_SIM_HOURS:-6}" --accounts="${HARNESS_READ_SIM_ACCOUNTS:-30}" --max-wall="${HARNESS_READ_SIM_WALL:-600}" 2>&1 || true)
        cohort_output="=== simulated read (${HARNESS_READ_SIM_HOURS:-6}h on a copy) ===
$sim_read
=== live read ===
$cohort_output"
      fi
      printf '%s\n' "$cohort_output"
      printf '%s\n' "$cohort_output" > "/tmp/harness-quality-$universe.txt"
      case "$cohort_output" in
        *"QUALITY: FAIL"* | *"PLAY: FAIL"*)
          echo "=== QUALITY FAILED on $universe — the accounts play, but badly; violations above $(date -u '+%F %T') UTC ==="
          # Not a dead end: a failing invariant becomes a task row, once per invariant, so the next
          # pass reports it as already tracked instead of the verdict circling in the log forever.
          python3 -u scripts/strategy-pipeline.py quality "/tmp/harness-quality-$universe.txt" || true
          ;;
      esac
      case "$cohort_output" in
        "" | *"unavailable"*) echo "$universe unavailable (stack down?)" ;;
      esac
    done

    # Only now the canary: it is the expensive, stateful one, and its failure parks the pass. A red
    # live run means the code on disk does not play, so no further slice should be written on top of
    # it — but the cohort verdict above has already been taken by then, which is what the operator
    # reads and what raises the missing-invariant task rows.
    echo "--- live canary verification $(date -u '+%F %T') UTC ---"
    HARNESS_WORKER=pass python3 -u scripts/strategy-pipeline.py publish live-verification || true
    bash scripts/canary.sh restart >> "$LOG" 2>&1 || true
    if bash scripts/canary.sh check >> "$LOG" 2>&1; then
      echo "--- live verification passed $(date -u '+%F %T') UTC ---"
    else
      # Loud and repeated, because the code on disk is what the cohorts run: a red live run means the
      # module does not play, and no number of further slices fixes that on its own.
      echo "=== LIVE VERIFICATION FAILED $(date -u '+%F %T') UTC — the accounts did not play; block above ==="
      # Not a `continue`: implementation happens at the top of the next pass either way, so skipping
      # here only skipped the proof stage, and nothing could be proven while the canary stayed red.
    fi

    # Proof stage: a row the writer delivered closes only when its proof passes on the cohorts
    # (`task.py done` runs `scripts/ogamex prove`). The cohort workers keep classes in memory, so
    # they are restarted onto the code on disk first, or the proof would read the old behaviour.
    unproven=$(python3 -u scripts/strategy-pipeline.py status | sed -n 's/^UNPROVEN: //p')
    # A delivered row is proved when its code changed since the last proof, or every 20 minutes (a live
    # aspect needs time to move). Proving all of them every pass printed the same verdict dozens of times
    # and restarted the cohort's workers each time, which added the backlog the verdict then read.
    due=""
    for code in $unproven; do
      log="plan/research/ogame/proofs/$code.log"; marker="plan/research/ogame/implemented/$code.md"
      if [ ! -f "$log" ] || [ "$marker" -nt "$log" ] || [ $(( $(date +%s) - $(stat -c %Y "$log") )) -gt 1200 ]; then
        due="$due $code"
      fi
    done
    if [ -n "$due" ]; then
      # Workers are restarted onto new code only when a row was delivered since the last restart; a re-proof
      # of the same code every 20 minutes restarted them for nothing and added to the backlog it then read.
      restart=0
      for code in $due; do
        [ ! -f /tmp/harness-last-restart ] || [ "plan/research/ogame/implemented/$code.md" -nt /tmp/harness-last-restart ] && restart=1
      done
      if [ "$restart" = 1 ]; then
        touch /tmp/harness-last-restart
        for universe in ${HARNESS_UNIVERSES:-grand}; do
          (cd "$COMPOSE_DIR" && docker compose -f "docker-compose.$universe.yml" exec -T ogamex-app sh -lc "cd /var/www && php artisan queue:restart") || true
        done
      fi
      for code in $(printf '%s\n' $due | head -n "${HARNESS_BATCH:-10}"); do
        echo "--- proving $code $(date -u '+%F %T') UTC ---"
        if ! python3 plan/tasks/task.py done "$code"; then
          echo "--- $code delivered, NOT proven yet $(date -u '+%F %T') UTC ---"
          # A failing live proof is the writer's next input, not a verdict to wait out.
          python3 scripts/strategy-pipeline.py reopen "$code"
        fi
      done
    fi
  done
} >> "$LOG" 2>&1
