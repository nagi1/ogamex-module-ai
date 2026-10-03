#!/usr/bin/env bash
# A/B equivalence of the sidecar speed-ups: the same cohort snapshot, the same start instant and real
# sidecars for every run; each configuration plays HOURS simulated hours on its own copy and its decisions
# are diffed against R0a. R0a vs R0b is the sidecars' own run-to-run noise.
#   OGAMEX_RUNNER=local-docker-dev bash scripts/sidecar-ab.sh [hours] [accounts]
set -uo pipefail
cd "$(dirname "$0")/.."
HOURS="${1:-6}"; ACCOUNTS="${2:-20}"
COMPOSE=(docker compose -f ../../local-docker-dev/docker-compose.grand.yml exec -T)
SNAP=ogamex-sim-ab-snap
FROM="$(date -u +%Y-%m-%dT%H:%M:00Z)"
off="AI_EXPERIENCE_CBRKIT_RUST=false AI_COGNITION_PSYCHSIM_CACHE=false AI_MEMORY_AGENTOS_CACHE=false AI_COGNITION_FATIMA_CACHE=false"
declare -A CONFIG=(
  [R0a]="$off" [R0b]="$off"
  [R1]="AI_EXPERIENCE_CBRKIT_RUST=true AI_COGNITION_PSYCHSIM_CACHE=false AI_MEMORY_AGENTOS_CACHE=false AI_COGNITION_FATIMA_CACHE=false"
  [R2]="AI_EXPERIENCE_CBRKIT_RUST=true AI_COGNITION_PSYCHSIM_CACHE=true AI_MEMORY_AGENTOS_CACHE=true AI_COGNITION_FATIMA_CACHE=false"
  [R3]="AI_EXPERIENCE_CBRKIT_RUST=true AI_COGNITION_PSYCHSIM_CACHE=true AI_MEMORY_AGENTOS_CACHE=true AI_COGNITION_FATIMA_CACHE=true"
)

app() { "${COMPOSE[@]}" ogamex-app sh -lc "cd /var/www && $*"; }

echo "snapshot -> $SNAP (start $FROM)"
app "php Modules/AI/scripts/sim-clone.php $SNAP"
for run in R0a R0b R1 R2 R3; do
  db="ogamex-sim-ab-${run,,}"
  echo "=== $run: ${CONFIG[$run]}"
  app "DB_DATABASE=$SNAP php Modules/AI/scripts/sim-clone.php $db"
  # The caches are files in the container: one run's answers must never feed the next.
  app "rm -rf storage/framework/cache/data/* && env DB_DATABASE=$db CACHE_STORE=file CACHE_DRIVER=file QUEUE_CONNECTION=sync ${CONFIG[$run]} php artisan ai:sim --from=$FROM --hours=$HOURS --accounts=$ACCOUNTS 2>&1 | grep -E '^(SIM|JUMPS|SIDECAR)|died|ABORTED'"
done
for run in R0b R1 R2 R3; do
  app "DB_DATABASE=$SNAP php Modules/AI/scripts/sidecar-ab-diff.php ogamex-sim-ab-r0a ogamex-sim-ab-${run,,}"
done
