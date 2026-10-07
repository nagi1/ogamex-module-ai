#!/usr/bin/env bash
# Fast late-game loop: accounts staged at a big resource budget, played a short while, then what they reached.
#   PHP=php bash Modules/AI/rl/scripts/late.sh <seed> <budget> <hours> [policy] [accounts] [speed]
# Run inside the container from /var/www; output under storage/rl/late/<seed>/.
set -euo pipefail
export BROADCAST_CONNECTION=log CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
SEED=${1:?seed}; BUDGET=${2:-2e11}; HOURS=${3:-24}; POLICY=${4:-teacher}; ACC=${5:-8}; SPEED=${6:-8}
AT=2026-10-05T00:00:00Z; OUT=storage/rl/late/$SEED; mkdir -p "$OUT"
rm -f "$OUT"/start.sqlite "$OUT"/end.sqlite "$OUT"/sim.log
php artisan ai:rl-universe "$OUT/start.sqlite" --accounts="$ACC" --seed="$SEED" --stage-budget="$BUDGET" --speed="$SPEED" --at="$AT" > "$OUT/universe.log" 2>&1
cp "$OUT/start.sqlite" "$OUT/run.sqlite"
DB_CONNECTION=sqlite DB_DATABASE="$(realpath "$OUT/run.sqlite")" nice -n 15 php -d memory_limit=4G artisan ai:sim --in-memory --native-cognition --full-play \
  --seed="$SEED" --hours="$HOURS" --from="$AT" --choice-policy="$POLICY" --save-sqlite="$(realpath "$OUT")/end.sqlite" > "$OUT/sim.log" 2>&1
rm -f "$OUT/run.sqlite"
python3 Modules/AI/rl/scripts/reach.py "$OUT/end.sqlite" "$OUT/start.sqlite" | tee "$OUT/reach.txt"
