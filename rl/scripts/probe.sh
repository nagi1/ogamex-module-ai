#!/usr/bin/env bash
# Fast late-game loop: what every planner would do now on a saved snapshot, and why it refuses (seconds, not hours).
#   bash Modules/AI/rl/scripts/probe.sh <snapshot.sqlite | late seed> [player ...]     (from the repo root, on the host)
set -euo pipefail
SRC=$1; shift
[[ -f "$SRC" ]] || SRC=storage/rl/late/$SRC/end.sqlite
COPY=storage/rl/probe-$$.sqlite
cp "$SRC" "$COPY"
trap 'rm -f "$COPY"' EXIT
docker compose -f local-docker-dev/docker-compose.yml exec -T ogamex-app bash -c \
  "cd /var/www && DB_CONNECTION=sqlite DB_DATABASE=/var/www/$COPY CACHE_STORE=array BROADCAST_CONNECTION=log QUEUE_CONNECTION=sync timeout 600 php Modules/AI/rl/scripts/probe.php $*"
