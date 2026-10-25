#!/usr/bin/env bash
# Crawl one target with the Scrapy engine (Modules/AI/scrapers/.venv).
#
#   scrapers/crawl.sh forum board.de.ogame.gameforge.com
#   scrapers/crawl.sh reddit ogame comment
#   scrapers/crawl.sh archive board.us.ogame.gameforge.com
#
# The HTTP cache is shared across crawls, and it is the whole resumability story: a re-run serves
# the pages it already has from disk and only fetches what is missing, so no JOBDIR is used. (A
# JOBDIR persists the dupefilter too, which makes re-running a *finished* crawl a 3ms no-op.)
# Output appends to the same normalized JSONL the stdlib collectors use, and the writer drops items
# already on disk - so the two engines can hand a host back and forth without duplicating a post.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
CORPUS="$(cd "$HERE/../../.." && pwd)/storage/rl/external"
mkdir -p "$CORPUS/logs"

SPIDER="${1:-forum}"
TARGET="${2:-board.en.ogame.gameforge.com}"
KEY="${SPIDER}-${TARGET//./_}"
ARGS=()
case "$SPIDER" in
  forum)
    ARGS=(-a "host=$TARGET" -a "max_board_pages=${MAX_BOARD_PAGES:-6}"
          -a "max_threads=${MAX_THREADS:-300}" -a "max_thread_pages=${MAX_THREAD_PAGES:-6}")
    ;;
  reddit)
    ARGS=(-a "subreddit=$TARGET" -a "kind=${3:-comment}")
    ;;
  archive)
    ARGS=(-a "host=$TARGET" -a "max_threads=${MAX_THREADS:-400}")
    ;;
  *)
    echo "unknown spider: $SPIDER (forum|reddit|archive)" >&2
    exit 2
    ;;
esac

cd "$HERE" || exit 1
exec .venv/bin/scrapy crawl "$SPIDER" "${ARGS[@]}" \
    -s "HTTPCACHE_DIR=$CORPUS/.scrapy-httpcache" \
    -s "LOG_LEVEL=${LOG_LEVEL:-INFO}" \
    >>"$CORPUS/logs/$KEY.log" 2>&1
