#!/usr/bin/env bash
# Behaviour-cloning data: N fresh seeded universes, each played P days by ai:sim in its own process with every
# economy choice recorded. Run from the OGameX root (the host checkout with Modules/AI).
#
#   PHP=php bash Modules/AI/rl/scripts/generate.sh <out-dir> <universes> <days> <parallel> [policy] [epsilon] [accounts]
#   PHP=php bash Modules/AI/rl/scripts/generate.sh storage/rl/bc 32 30 16 epsilon 0.1 24
#
# policy: teacher (pure imitation data) or epsilon (teacher + random legal choices, recorded as such).
set -euo pipefail
# Parallel sims must not share the app's cache, queue or broadcaster (locks and budgets leak, runs diverge).
export BROADCAST_CONNECTION=log CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
PHP=${PHP:-php}
OUT=${1:?output directory}; N=${2:-16}; DAYS=${3:-30}; PAR=${4:-8}; POLICY=${5:-teacher}; EPS=${6:-0.1}; ACCOUNTS=${7:-24}
AT=2026-10-05T00:00:00Z
mkdir -p "$OUT"

run_one() {
  local i=$1
  local universe="$OUT/universe-$i.sqlite"
  # A seed that already played to the end is kept, so a rerun with a larger N only adds universes.
  if grep -q '^SIM: .* played' "$OUT/sim-$i.log" 2>/dev/null; then echo "universe $i: kept"; return; fi
  rm -f "$OUT/choices-$i.jsonl" "$OUT/choices-$i.jsonl.schema.json"
  [ -f "$universe" ] || $PHP artisan ai:rl-universe "$universe" --accounts="$ACCOUNTS" --seed="$i" --at="$AT" > "$OUT/universe-$i.log" 2>&1
  DB_CONNECTION=sqlite DB_DATABASE="$(realpath "$universe")" $PHP -d memory_limit=4G artisan ai:sim --in-memory --native-cognition \
    --seed="$i" --hours=$((DAYS * 24)) --from="$AT" --choice-policy="$POLICY" --choice-epsilon="$EPS" \
    --record-choices="$(realpath "$OUT")/choices-$i.jsonl" > "$OUT/sim-$i.log" 2>&1
  echo "universe $i: $(grep -h '^SIM: .* played' "$OUT/sim-$i.log" || echo FAILED)"
}
export -f run_one; export PHP OUT DAYS POLICY EPS ACCOUNTS AT
seq 1 "$N" | xargs -P "$PAR" -I{} bash -c 'run_one {}'
echo "choice points: $(cat "$OUT"/choices-*.jsonl | wc -l) in $OUT"
