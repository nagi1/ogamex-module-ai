#!/usr/bin/env bash
# Throughput on the training workstation: N concurrent in-memory universes, each a seeded ai:sim run.
# The source database is only read (--in-memory), so pointing it at a cohort copy is safe.
#
#   PHP="docker compose -f local-docker-dev/docker-compose.grand.yml exec -T ogamex-app php" \
#     bash Modules/AI/plan/rl/bench/xeon-benchmark.sh 6 2026-10-15T00:00:00Z "1 4 8 12 16 18"
#
# Prints, per concurrency level, the wall time and the sessions + orders per second summed over universes.
set -euo pipefail
# Parallel sims must not share the app's cache, queue or broadcaster (locks and budgets leak, runs diverge).
export BROADCAST_CONNECTION=log CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
PHP=${PHP:-php}
HOURS=${1:-6}
FROM=${2:?start instant, e.g. the SIM_NOW of the source database}
LEVELS=${3:-"1 4 8 12 16"}
OUT=$(mktemp -d)

for n in $LEVELS; do
  start=$(date +%s.%N)
  for i in $(seq 1 "$n"); do
    $PHP -d memory_limit=4G artisan ai:sim --in-memory --native-cognition --seed="$i" --hours="$HOURS" --from="$FROM" > "$OUT/run-$n-$i.txt" 2>&1 &
  done
  wait
  wall=$(echo "$(date +%s.%N) - $start" | bc)
  read -r work errors < <(cat "$OUT"/run-"$n"-*.txt | awk '/^SIM: .* played/ {for (i = 1; i <= NF; i++) {if ($i ~ /^session/) w += $(i - 1); if ($i == "other") w += $(i - 1); if ($i ~ /^error/) e += $(i - 1)}} END {print w + 0, e + 0}')
  printf 'universes=%-3s wall=%7.1fs work_items=%-7s per_second=%7.1f errors=%s\n' "$n" "$wall" "$work" "$(echo "$work / $wall" | bc -l)" "$errors"
done
echo "raw outputs: $OUT"
