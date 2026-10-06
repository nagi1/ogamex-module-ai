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
docker update --cpus=10 --memory=18g --memory-swap=18g ogamex-local-docker-dev-ogamex-app-1 >/dev/null 2>&1  # owner 7 Oct: freezes continued at 14 CPUs with no OOM or kernel error in the guest log (the VM just stops), so 10 CPUs (42%); the 32 GB WSL freezes came from memory fragmentation (.wslconfig), so memory is capped too
cd "$ROOT"

pgrep -f "[r]l_status.py" >/dev/null || { setsid nohup bash "$RL/collector.sh" > "$RL/collector.log" 2>&1 < /dev/null & echo "collector started"; }

# A policy sim needs its model server, which dies with a reboot: start every server whose model exists and whose socket is gone.
VENV="$ROOT/.venv-rl/bin/python"
for model in "$RL"/bc-v*/model.onnx; do
  tag=$(basename "$(dirname "$model")"); sock="$RL/ogrl${tag#bc-v}.sock"; [ "$tag" = bc-v3 ] && sock="$RL/ogrl.sock"  # plan.sh names v3 ogrl.sock, later models ogrl<N>.sock
  pgrep -f "[o]grl.serve --model $model" >/dev/null && continue
  rm -f "$sock"; (cd "$ROOT/Modules/AI/rl" && setsid nohup "$VENV" -u -m ogrl.serve --model "$model" --socket "$sock" > "$RL/serve-$tag.log" 2>&1 < /dev/null &)
done

# A recording cut mid-write ends in half a line; keep the whole lines, the way the trainer expects them.
for f in "$RL"/bc*/choices-*.jsonl; do
  [ -f "$f" ] && ! grep -q '^SIM: .* played' "${f/choices-/sim-}" 2>/dev/null && continue
  [ -s "$f" ] && [ "$(tail -c1 "$f" | wc -l)" = 0 ] && { head -n -1 "$f" > "$f.tmp" && mv "$f.tmp" "$f"; echo "trimmed $f"; }
done

# The container has no pgrep, and a second run would delete the first one's recordings: ask /proc.
running() { docker exec "$APP" bash -c 'for p in /proc/[0-9]*; do tr "\0" " " < $p/cmdline 2>/dev/null | grep -q "^bash [A-Za-z/].*generate\\.sh" && exit 0; done; exit 1'; }
if running; then echo "generation already running"; exit 0; fi
docker exec -d -w /var/www "$APP" bash storage/rl/plan.sh && echo "generation resumed"
