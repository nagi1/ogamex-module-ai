#!/usr/bin/env bash
# One command after a freeze or restart: brings the container, the status collector and the data run back, losing only
# the universes that were mid-simulation (every finished one is on disk and is kept). Safe to run any time; it starts
# only what is not already running.
#
#   bash Modules/AI/rl/scripts/resume.sh        (from the OGameX root)
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../../.." && pwd)
RL="$ROOT/storage/rl"
APP=ogamex-local-docker-dev-ogamex-app-1
cd "$ROOT/local-docker-dev" && docker compose up -d --no-deps ogamex-app >/dev/null 2>&1
docker update --cpus=17 ogamex-local-docker-dev-ogamex-app-1 >/dev/null 2>&1  # hard cap: 17 of the 24 CPUs WSL has = 71%, leaving room for host-side training under the 80% limit
cd "$ROOT"

pgrep -f "[r]l_status.py" >/dev/null || { setsid nohup bash "$RL/collector.sh" > "$RL/collector.log" 2>&1 < /dev/null & echo "collector started"; }

# A recording cut mid-write ends in half a line; keep the whole lines, the way the trainer expects them.
for f in "$RL"/bc*/choices-*.jsonl; do
  [ -f "$f" ] && ! grep -q '^SIM: .* played' "${f/choices-/sim-}" 2>/dev/null && continue
  [ -s "$f" ] && [ "$(tail -c1 "$f" | wc -l)" = 0 ] && { head -n -1 "$f" > "$f.tmp" && mv "$f.tmp" "$f"; echo "trimmed $f"; }
done

# The container has no pgrep, and a second run would delete the first one's recordings: ask /proc.
running() { docker exec "$APP" bash -c 'for p in /proc/[0-9]*; do tr "\0" " " < $p/cmdline 2>/dev/null | grep -q "^bash [A-Za-z/].*generate\\.sh" && exit 0; done; exit 1'; }
if running; then echo "generation already running"; exit 0; fi
docker exec -d -w /var/www "$APP" bash storage/rl/plan.sh && echo "generation resumed"
