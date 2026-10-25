#!/usr/bin/env bash
# Collect the whole OGame social / diplomacy / community corpus.
#
#   Reddit  : r/OGame submissions and every comment (PullPush archive API)
#   Forums  : every Gameforge language board's alliance / diplomacy / community / archive sections
#   Archive : the 2005-2012 "golden era" threads that only survive in the Wayback Machine
#
# The language boards are crawled in PARALLEL (`JOBS` at a time): each host is a separate
# community that owns its own raw/ and normalized/ directory, so parallel hosts never write the
# same file. Reddit and the Wayback archive hit different servers again and run alongside them.
# Every collector caches raw pages on disk, so re-running this script resumes instead of
# re-fetching; each step logs to storage/rl/external/logs/<step>.log.
#
#   bash Modules/AI/scripts/collect-social-corpus.sh                 # everything, until it finishes
#   JOBS=6 OGAMEX_PROXIES="host:port,..." bash Modules/AI/scripts/collect-social-corpus.sh
#   HOSTS="board.tr.ogame.gameforge.com" bash Modules/AI/scripts/collect-social-corpus.sh
#
# Set OGAMEX_PROXIES (or OGAMEX_PROXY_FILE) to rotate proxies as well as user agents; see
# scrapers/ogame_corpus/middlewares.py. Without it the crawl rotates user agents only.
#
# The collectors only touch public, unauthenticated pages and honour their own rate limits.
set -u
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT" || exit 1

LOGS="$ROOT/storage/rl/external/logs"
mkdir -p "$LOGS"
PY="${PY:-python3}"
REDDIT="Modules/AI/scripts/ogame-reddit-collect.py"
JOBS="${JOBS:-8}"
# The boards are crawled with Scrapy (scrapers/): AutoThrottle, RetryMiddleware, identity rotation
# and the HTTP cache replace the hand-rolled backoff. ENGINE=stdlib falls back to
# scripts/ogame-forum-collect.py for the boards.
ENGINE="${ENGINE:-scrapy}"
CRAWL="$ROOT/Modules/AI/scrapers/crawl.sh"
SUBREDDIT="${SUBREDDIT:-ogame}"
# The Reddit lane uses the stdlib collector, not Scrapy: the same walk via the Scrapy spider dies on
# an intermittent 422 from the API mirror (its compression path), while plain urllib walks 77 pages
# in two minutes without a hiccup.
REDDIT_DELAY="${REDDIT_DELAY:-1.0}"
# The legacy boards worth replaying from the archive (WBB2/WBB3 captures, 2005-2014).
ARCHIVE_HOSTS=(board.ogame.org board.ogame.de board.origin.ogame.gameforge.com
               board.en.ogame.gameforge.com board.fr.ogame.gameforge.com
               board.us.ogame.gameforge.com)
REDDIT_DELAY="${REDDIT_DELAY:-1.0}"
REDDIT_SOURCE="${REDDIT_SOURCE:-arctic}"

# Only the stdlib fallback path (ENGINE=stdlib) uses these.
FORUM="Modules/AI/scripts/ogame-forum-collect.py"
FORUM_HOSTS=(
  # DE first: the largest and most active community, and the one this corpus most needs.
  board.de.ogame.gameforge.com
  board.en.ogame.gameforge.com
  board.us.ogame.gameforge.com
  board.fr.ogame.gameforge.com
  board.it.ogame.gameforge.com
  board.tr.ogame.gameforge.com
  board.ru.ogame.gameforge.com
  board.hu.ogame.gameforge.com
  board.ro.ogame.gameforge.com
  forum.es.ogame.gameforge.com
  forum.pt.ogame.gameforge.com
  forum.ba.ogame.gameforge.com
  forum.origin.ogame.gameforge.com
)
# `HOSTS="a b c"` crawls only those, e.g. to finish the hosts a previous run left throttled.
if [ -n "${HOSTS:-}" ]; then read -r -a FORUM_HOSTS <<<"$HOSTS"; fi

# A step in the background, logged to the supervisor log.
run_bg() {
  local name="$1"
  shift
  printf '[%s] START %s\n' "$(date -u +%H:%M:%S)" "$name" >>"$LOGS/supervisor.log"
  ( "$@" >>"$LOGS/$name.log" 2>&1
    printf '[%s] DONE  %s (exit %s)\n' "$(date -u +%H:%M:%S)" "$name" "$?" >>"$LOGS/supervisor.log" ) &
}

# Hold at most JOBS background steps in flight.
throttle() {
  while [ "$(jobs -rp | wc -l)" -ge "$JOBS" ]; do wait -n; done
}

# How many pages a host's last run had to skip (throttled away). -1 means it never ran.
skips_for() {
  "$PY" -c 'import json,os,sys; p=sys.argv[1]; print(json.load(open(p))["record_counts"].get("skipped", 0) if os.path.exists(p) else -1)' \
      "storage/rl/external/sources/FORUM/${1//./_}/manifest.json"
}

# Pages the last Scrapy crawl lost to throttling - a "not yet". A 403/401 is deliberately NOT
# counted: it is a permission answer (the board is guest-restricted), which no retry, user agent or
# proxy changes, so counting it would make the host re-run forever for nothing.
scrapy_lost() {
  "$PY" -c 'import os,re,sys
p=sys.argv[1]
if not os.path.exists(p):
    print(1); sys.exit()
t=open(p, errors="replace").read()
if "Dumping Scrapy stats:" not in t:
    print(1); sys.exit()
last=t.rsplit("Dumping Scrapy stats:", 1)[-1]
pairs=re.findall(r"(?:retry/max_reached|httperror/response_ignored_status_count/(?:408|429|5\d\d)).:\s*(\d+)", last)
print(sum(int(v) for v in pairs))' "$LOGS/forum-${1//./_}.log"
}

# Pages the site refused to a guest (401/403). Reported, never retried.
scrapy_refused() {
  "$PY" -c 'import os,re,sys
p=sys.argv[1]
if not os.path.exists(p):
    print(0); sys.exit()
t=open(p, errors="replace").read()
last=t.rsplit("Dumping Scrapy stats:", 1)[-1] if "Dumping Scrapy stats:" in t else t
pairs=re.findall(r"httperror/response_ignored_status_count/(?:401|403).:\s*(\d+)", last)
print(sum(int(v) for v in pairs))' "$LOGS/forum-${1//./_}.log"
}

# Comment rows on disk, so a retry round can tell progress from a block.
comment_rows() {
  "$PY" -c 'import os; p="storage/rl/external/sources/REDDIT/ogame/normalized/comment.jsonl"; print(sum(1 for _ in open(p)) if os.path.exists(p) else 0)'
}

# Crawl one host, then re-run it until it stops skipping pages. The raw cache means a re-run only
# re-fetches what was throttled away, so "keep trying" costs almost nothing after the first pass.
forum_host() {
  local host="$1" key="forum-${1//./_}" pass skips lost refused
  for pass in $(seq 1 "${RETRY_PASSES:-6}"); do
    printf '[%s] START %s (pass %s, %s)\n' "$(date -u +%H:%M:%S)" "$key" "$pass" "$ENGINE" >>"$LOGS/supervisor.log"
    if [ "$ENGINE" = "scrapy" ]; then
      # One pass, one log: Scrapy omits a stat that is zero, so a stale "gave up" count from an
      # earlier pass would otherwise be read as the current one and the pass would never settle.
      : >"$LOGS/$key.log"
      MAX_BOARD_PAGES=6 MAX_THREADS=300 MAX_THREAD_PAGES=6 "$CRAWL" forum "$host"
      lost=$(scrapy_lost "$host")
      refused=$(scrapy_refused "$host")
      printf '[%s] DONE  %s pass %s (scrapy, %s lost, %s refused)\n' "$(date -u +%H:%M:%S)" "$key" "$pass" "$lost" "$refused" >>"$LOGS/supervisor.log"
      if [ "$refused" != "0" ]; then
        printf '[%s] GUEST-RESTRICTED %s: %s page(s) asked for a login - out of scope, not retried\n' \
               "$(date -u +%H:%M:%S)" "$key" "$refused" | tee -a "$LOGS/supervisor.log"
      fi
      [ "$lost" = "0" ] && return 0
      sleep "${RETRY_COOLDOWN:-120}"
      continue
    fi
    "$PY" "$FORUM" --host "$host" --delay 1.5 \
        --max-board-pages 6 --max-threads 300 --max-thread-pages 6 >>"$LOGS/$key.log" 2>&1
    skips=$(skips_for "$host")
    printf '[%s] DONE  %s pass %s (%s skipped)\n' "$(date -u +%H:%M:%S)" "$key" "$pass" "$skips" >>"$LOGS/supervisor.log"
    [ "$skips" = "0" ] && return 0
    sleep "${RETRY_COOLDOWN:-120}"
  done
  printf '[%s] STILL THROTTLED %s (left for the next run)\n' "$(date -u +%H:%M:%S)" "$key" | tee -a "$LOGS/supervisor.log"
}

# Reddit comments: PullPush throttles the whole client with 429, so this keeps going round after
# round. A round that added rows earns a short breather and another go; a round that added nothing
# means we are hard-blocked, so it waits out a long cool-down and tries again.
reddit_rounds() {
  local round before after
  for round in $(seq 1 "${REDDIT_ROUNDS:-10}"); do
    before=$(comment_rows)
    printf '[%s] START reddit-comment-%s (have %s)\n' "$(date -u +%H:%M:%S)" "$round" "$before" >>"$LOGS/supervisor.log"
    "$PY" "$REDDIT" --source "$REDDIT_SOURCE" --kind comment --max-pages 0 --delay "$REDDIT_DELAY" \
        >>"$LOGS/reddit-comment.log" 2>&1
    after=$(comment_rows)
    printf '[%s] DONE  reddit-comment-%s (+%s, have %s)\n' "$(date -u +%H:%M:%S)" "$round" "$((after - before))" "$after" >>"$LOGS/supervisor.log"
    if [ "$after" -gt "$before" ]; then
      sleep "${REDDIT_COOLDOWN_SHORT:-60}"
      continue
    fi
    sleep "${REDDIT_COOLDOWN:-900}"
  done
}

# 1. Everything that hits a different server than Gameforge starts now and finishes on its own
#    schedule, alongside the forum crawl.
reddit_rounds &
run_bg reddit-submission "$PY" "$REDDIT" --source "$REDDIT_SOURCE" --kind submission \
       --max-pages 0 --delay "$REDDIT_DELAY"
for host in "${ARCHIVE_HOSTS[@]}"; do
  run_bg "archive-${host//./_}" "$CRAWL" archive "$host"
  throttle
done

# 2. Every language board's alliance / diplomacy / community / archive sections, JOBS at a time.
#    Each host re-runs itself until it stops being throttled.
for host in "${FORUM_HOSTS[@]}"; do
  forum_host "$host" &
  throttle
done
wait

printf '[%s] ALL DONE\n' "$(date -u +%H:%M:%S)" | tee -a "$LOGS/supervisor.log"
