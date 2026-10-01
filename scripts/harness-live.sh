#!/usr/bin/env bash
# Keeps the harness working until a peak window opens: no budget, no stopping after one pass.
#
# Each pass runs the whole chain, because a stage the loop skips is where work silently strands:
#   sweep   harvest the keep-set wiki pages this corpus does not hold yet
#   run     turn every ingested source without a passing plan into one
#   promote give every validated plan a task row, so the implement stage can see it
#   implement write the task's code against its plan and verify it with its own test
# A validated plan that is never promoted is invisible to the implement stage, and no amount of
# looping fixes that -- the loop has to call promote itself (found 28 Sep 2026: 122 validated,
# 98 promoted, 24 stranded). Only a peak window stops the loop. DEV TOOLING — never run in a universe.
#
# The two network-bound stages run as parallel shards, because off-peak hours are the scarce budget
# and one worker waits on the model for most of them: PLAN_WORKERS planning workers take disjoint
# slices of the same queue, IMPL_WORKERS implement disjoint slices of the todo list. Tune with
# PLAN_WORKERS / PLAN_BUDGET / IMPL_WORKERS; every worker keeps its own peak gate. Worker count is not
# a spend control -- MODEL_CONCURRENCY is, and it is enforced across processes by the pipeline.
#
# `-u` is not cosmetic: python buffers stdout when it is redirected to a file, so without it the log
# stays empty until the process exits and the watch page shows nothing happening.
set -uo pipefail

# Children die with the shell. A park process outliving its harness kept publishing "parked" over a
# harness that was working, so the dashboard reported a stopped run for a running one.
trap 'kill 0' EXIT

cd "$(dirname "$0")/.." || exit 1
LOG=/tmp/harness-live.log
# Tests in the dev stack, situations and scorecards in the cohorts: `scripts/ogamex prove` needs both.
export OGAMEX_RUNNER="${OGAMEX_RUNNER:-local-docker-dev}"
COMPOSE_DIR=../../local-docker-dev
# Only used when a whole pass produced nothing: there is no point hammering an empty queue, but
# there is also no point sleeping while there is work left.
IDLE_INTERVAL=60

{
  echo "=== harness started $(date -u '+%F %T') UTC ==="

  # A park left behind by an earlier harness would keep overwriting the status this one publishes.
  pkill -f 'strategy-pipeline.py wait-until-offpeak' 2>/dev/null || true

  # Boot the canary once, outside the pass: from here on a check is a few queries, not a universe.
  bash scripts/canary.sh up || echo "canary unavailable; the live gate will report it"

  while true; do
    # One gate for the whole pass, before anything runs: sweeping and promoting used to happen before
    # the first per-call gate, so a pass that began at 05:59 kept working into the window.
    python3 -u scripts/strategy-pipeline.py peak-gate
    if [ $? -eq 3 ]; then
      echo "=== parked: peak window $(date -u '+%F %T') UTC ==="
      # Nothing of ours runs during peak: the canary is stopped too, so the machine is quiet and the
      # dashboard cannot be mistaken for work in progress.
      bash scripts/canary.sh down >> "$LOG" 2>&1 || true
      # The park is free time for checks that make no provider call (HARNESS-004): drive every
      # situation on both cohorts once, so the next pass starts from a fresh read of what plays.
      for universe in grand pve; do
        echo "--- situations on $universe during the park $(date -u '+%F %T') UTC ---"
        PROVE_UNIVERSE=$universe bash scripts/ogamex situation all || true
      done
      python3 -u scripts/strategy-pipeline.py wait-until-offpeak
      bash scripts/canary.sh up >> "$LOG" 2>&1 || true
      echo "=== peak over, resuming $(date -u '+%F %T') UTC ==="
      continue
    fi
    # Claims a vanished agent or a killed worker left behind, before the queue is read.
    python3 plan/tasks/task.py reap
    proposals_before=$(ls plan/research/ogame/proposals | wc -l)
    markers_before=$(ls plan/research/ogame/implemented 2>/dev/null | wc -l)

    # Direction reset, 1 Oct 2026 (AGENTS.md): ingesting more wiki pages grew the backlog faster than
    # the accounts learned to play -- 120 open WIK rows while raids, fleet saves and social were dead.
    # Sweep, plan and promote run only when an operator asks for them.
    ingest=${HARNESS_INGEST:-0}
    [ "$ingest" = 1 ] && python3 -u scripts/strategy-pipeline.py sweep --max 12

    # Planning and implementation are network-bound: one source at a time waits on the model for tens
    # of seconds per call, so a sixty-source queue was an hour spent waiting. The queue is sharded
    # instead -- `run --shard k/N` takes every Nth pending source, so N workers cover it exactly once
    # with no lock file and no claim table to keep consistent. Each worker keeps its own peak gate, so
    # a window that opens mid-pass stops the workers rather than the pass.
    plan_workers=${PLAN_WORKERS:-4}
    plan_budget=${PLAN_BUDGET:-20}
    impl_workers=${IMPL_WORKERS:-3}
    # Every model call is paced through a shared slot file (strategy-pipeline.py), so the workers can
    # outnumber the cap without flooding the API: the cap is what decides how many calls are in flight.
    # DeepSeek counts concurrency, not requests per minute, and the cohorts share this key.
    export MODEL_CONCURRENCY="${MODEL_CONCURRENCY:-4}"

    # How many sources are actually waiting, before any worker is spawned. Six workers were being
    # started for an empty queue every pass, and each one is a python start plus a module import to
    # discover it has nothing to do.
    plan_queue_before=$(python3 - <<'PY'
import importlib.util

spec = importlib.util.spec_from_file_location('sp', 'scripts/strategy-pipeline.py')
pipeline = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pipeline)
print(len(pipeline.pending_sources()))
PY
)

    [ "$ingest" = 1 ] || plan_queue_before=0
    if [ "${plan_queue_before:-0}" -gt 0 ]; then
      [ "$plan_queue_before" -lt "$plan_workers" ] && plan_workers=$plan_queue_before
      echo "--- ${plan_queue_before} source(s) to plan across ${plan_workers} worker(s), ${impl_workers} implementing $(date -u '+%F %T') UTC ---"

      rm -f /tmp/harness-plan-rc.* /tmp/harness-impl-rc.*
      rm -f plan/research/ogame/workers/*.json
      for shard in $(seq 0 $((plan_workers - 1))); do
        (
          HARNESS_WORKER="plan-$shard" python3 -u scripts/strategy-pipeline.py run \
            --max "$plan_budget" --shard "$shard/$plan_workers"
          echo $? > "/tmp/harness-plan-rc.$shard"
        ) &
      done
      wait
    else
      echo "--- nothing to plan, going straight to implementation $(date -u '+%F %T') UTC ---"
      rm -f /tmp/harness-plan-rc.* /tmp/harness-impl-rc.*
    fi

    [ "$ingest" = 1 ] && python3 -u scripts/strategy-pipeline.py promote

    python3 - <<'PY' > /tmp/harness-queue.txt
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
    "select code, coalesce(file_ref, '') from ready_tasks where kind = 'impl' and priority in ('P0', 'P1', 'P2') order by priority, id"
)
print('\n'.join(code for code, file_ref in rows if code in proposals or file_ref))
PY

    # Known ceiling: two workers can in principle be handed two plans that name the same file, and the
    # loser would overwrite the winner's file without knowing. Slices almost always own their own
    # paths, and `implement` rolls its own files back when verification fails, so this is a stated
    # risk rather than a solved problem. Upgrade path: a per-path lock taken before writing.
    for shard in $(seq 0 $((impl_workers - 1))); do
      (
        rc=0
        while read -r code; do
          # The queue above already holds only attemptable codes: a planned row or a hand-written
          # one with a file to edit. The old "must have a proposal" gate lives there now.
          HARNESS_WORKER="impl-$shard" python3 -u scripts/strategy-pipeline.py implement "$code"
          # Exit 3 is the peak park. Sleeping out the window is what makes an unattended run come back
          # by itself; exiting would silently end the night's work at the first peak minute.
          [ $? -eq 3 ] && { rc=3; break; }
        done < <(awk -v k="$shard" -v n="$impl_workers" 'NR % n == k' /tmp/harness-queue.txt)
        echo "$rc" > "/tmp/harness-impl-rc.$shard"
      ) &
    done
    wait

    if grep -qs '^3$' /tmp/harness-plan-rc.* /tmp/harness-impl-rc.*; then
      echo "=== parked mid-pass: peak window $(date -u '+%F %T') UTC ==="
      bash scripts/canary.sh down >> "$LOG" 2>&1 || true
      python3 -u scripts/strategy-pipeline.py wait-until-offpeak
      bash scripts/canary.sh up >> "$LOG" 2>&1 || true
      echo "=== peak over, resuming $(date -u '+%F %T') UTC ==="
      continue
    fi

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
    for universe in grand pve; do
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
      sleep 120
      continue
    fi

    # Proof stage: a row the writer delivered closes only when its proof passes on the cohorts
    # (`task.py done` runs `scripts/ogamex prove`). The cohort workers keep classes in memory, so
    # they are restarted onto the code on disk first, or the proof would read the old behaviour.
    unproven=$(python3 -u scripts/strategy-pipeline.py status | sed -n 's/^UNPROVEN: //p')
    if [ -n "$unproven" ]; then
      for universe in grand pve; do
        (cd "$COMPOSE_DIR" && docker compose -f "docker-compose.$universe.yml" exec -T ogamex-app sh -lc "cd /var/www && php artisan queue:restart") || true
      done
      for code in $(printf '%s\n' $unproven | head -n 5); do
        echo "--- proving $code $(date -u '+%F %T') UTC ---"
        python3 plan/tasks/task.py done "$code" || echo "--- $code delivered, NOT proven yet $(date -u '+%F %T') UTC ---"
      done
    fi

    proposals_after=$(ls plan/research/ogame/proposals | wc -l)
    markers_after=$(ls plan/research/ogame/implemented 2>/dev/null | wc -l)

    # A queued source is spendable work whatever the last pass did with it, so the loop must not sit
    # out a minute with fifty-three sources waiting. Only an empty queue justifies waiting.
    plan_queue=$(python3 - <<'PY'
import importlib.util

spec = importlib.util.spec_from_file_location('sp', 'scripts/strategy-pipeline.py')
pipeline = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pipeline)
print(len(pipeline.pending_sources()))
PY
)

    # Off-peak time is the scarce resource, so a pass that got something done rolls straight into
    # the next one. So does a pass that still has sources to plan.
    if [ "$markers_after" -gt "$markers_before" ] || [ "$proposals_after" -gt "$proposals_before" ]; then
      echo "--- progress this pass, continuing immediately $(date -u '+%F %T') UTC ---"
      continue
    fi

    [ "$ingest" = 1 ] || plan_queue=0
    if [ "${plan_queue:-0}" -gt 0 ]; then
      echo "--- ${plan_queue} source(s) still to plan, continuing immediately $(date -u '+%F %T') UTC ---"
      continue
    fi

    echo "--- pass made no progress, checking what is actually queued $(date -u '+%F %T') UTC ---"
    queue_state=$(python3 -u scripts/strategy-pipeline.py status)
    printf '%s\n' "$queue_state"

    # Attemptable work is not a reason to sleep: the failures that produced this pass are had per
    # attempt, and the attempt budget already bounds the waste. A short pause keeps it from spinning
    # when every remaining task is blocked by another worker's claim.
    case "$queue_state" in
      *"READY: 0"*) echo "--- nothing attemptable, re-checking in ${IDLE_INTERVAL}s $(date -u '+%F %T') UTC ---"; sleep "$IDLE_INTERVAL" ;;
      *) echo "--- attemptable work still queued, going again in 10s $(date -u '+%F %T') UTC ---"; sleep 10 ;;
    esac
  done
} >> "$LOG" 2>&1
