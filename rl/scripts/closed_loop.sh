#!/usr/bin/env bash
# Closed-loop twin test: the same seeded universes played by the planner (A) and by a trained model (B), then
# compared account by account (ogrl.evaluate). Seeds here must not overlap the training seeds.
#
#   PHP=php bash Modules/AI/rl/scripts/closed_loop.sh <model.onnx> <out-dir> <first-seed> <universes> <days> <parallel> [learner-share]
#   PHP=php bash Modules/AI/rl/scripts/closed_loop.sh runs/bc/model.onnx storage/rl/eval 1001 30 30 16 0.25
set -euo pipefail
# Parallel sims must not share the app's cache, queue or broadcaster (locks and budgets leak, runs diverge).
export BROADCAST_CONNECTION=log CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
PHP=${PHP:-php}; PY=${PY:-python3}
MODEL=${1:?model.onnx}; OUT=${2:?out dir}; FIRST=${3:-1001}; N=${4:-30}; DAYS=${5:-30}; PAR=${6:-8}; SHARE=${7:-0.25}
AT=2026-10-05T00:00:00Z; SOCK=/tmp/ogrl-$$.sock
mkdir -p "$OUT/teacher" "$OUT/policy" "$OUT/universes"
$PY -m ogrl.serve --model "$MODEL" --socket "$SOCK" > "$OUT/serve.log" 2>&1 &
SERVER=$!; trap 'kill $SERVER 2>/dev/null; rm -f $SOCK' EXIT
sleep 3

run_pair() {
  local i=$1 universe="$OUT/universes/universe-$i.sqlite"
  [ -f "$universe" ] || $PHP artisan ai:rl-universe "$universe" --accounts=24 --seed="$i" --at="$AT" > /dev/null 2>&1
  for side in teacher policy; do
    local policy=teacher; [ "$side" = policy ] && policy=socket
    DB_CONNECTION=sqlite DB_DATABASE="$(realpath "$universe")" $PHP -d memory_limit=4G artisan ai:sim --in-memory --native-cognition \
      --seed="$i" --hours=$((DAYS * 24)) --from="$AT" --choice-policy=$policy --choice-socket="$SOCK" --learner-share="$SHARE" \
      --record-choices="$(realpath "$OUT")/$side/choices-$i.jsonl" > "$OUT/$side/sim-$i.log" 2>&1
  done
  echo "pair $i done"
}
export -f run_pair; export PHP OUT DAYS SHARE AT SOCK
seq "$FIRST" $((FIRST + N - 1)) | xargs -P "$PAR" -I{} bash -c 'run_pair {}'
$PY -m ogrl.evaluate --a "$OUT/teacher/*.jsonl" --b "$OUT/policy/*.jsonl" | tee "$OUT/report.json"
