"""Scrapy settings for the OGame social / diplomacy corpus crawl.

Why Scrapy: the corpus is ~13 Gameforge communities x thousands of threads that all share one
WoltLab template - exactly the shape Scrapy is built for. It brings, out of the box, the things
the stdlib collectors had to hand-roll:

  * AutoThrottle tunes each download slot's delay from the latency and status codes it actually
    sees (AUTOTHROTTLE_*), instead of a fixed sleep that is either rude or slow;
  * RetryMiddleware backs off on the throttling codes and keeps going (RETRY_*), which is the
    "throttling is a not-yet, not a no" behaviour, with a retry count per request;
  * DOWNLOAD_SLOTS / CONCURRENT_REQUESTS_PER_DOMAIN keep each community polite while many
    communities crawl at once (CONCURRENT_REQUESTS);
  * HttpCacheMiddleware is the raw-page cache (resumable, re-parsable offline), and JOBDIR
    resumes an interrupted crawl from the request queue.

Run from Modules/AI/scrapers (or use scrapers/crawl.sh):

    .venv/bin/scrapy crawl forum    -a host=board.us.ogame.gameforge.com
    .venv/bin/scrapy crawl reddit   -a subreddit=ogame -a kind=comment
    .venv/bin/scrapy crawl archive  -a host=board.us.ogame.gameforge.com

Parsing is NOT duplicated here: `ogame_corpus.parsers` loads the tested extractors from
`scripts/ogame-*.py`, so the same parser serves both engines.
"""

from pathlib import Path

# Modules/AI/scrapers/ogame_corpus/settings.py -> repo root is four parents up.
REPO = Path(__file__).resolve().parents[4]
CORPUS = REPO / "storage" / "rl" / "external"

BOT_NAME = "ogame_corpus"
SPIDER_MODULES = ["ogame_corpus.spiders"]
NEWSPIDER_MODULE = "ogame_corpus.spiders"

# --- citizenship, per domain ---------------------------------------------------------------
ROBOTSTXT_OBEY = True
CONCURRENT_REQUESTS = 24              # many different communities in one crawl
CONCURRENT_REQUESTS_PER_DOMAIN = 2    # ... but only a couple of sockets per board
DOWNLOAD_DELAY = 1.5
DOWNLOAD_DELAY_JITTER = 1.0           # spread bursts out; Scrapy >= 2.11
DOWNLOAD_TIMEOUT = 30
COOKIES_ENABLED = False
TELNETCONSOLE_ENABLED = False
USER_AGENT = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
              "(KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36")   # replaced per request by the rotator

# --- identity rotation ----------------------------------------------------------------------
# 560 puts this ahead of RetryMiddleware (550) on the response/exception path, so a blocked reply
# parks that proxy *before* the retry is built - and the retry then leaves through a different one.
DOWNLOADER_MIDDLEWARES = {"ogame_corpus.middlewares.RotateIdentityMiddleware": 560}
PROXY_COOLDOWN = 300.0   # seconds a proxy is parked after a block or a socket failure

# --- adaptive throttling: slow down when a site pushes back --------------------------------
AUTOTHROTTLE_ENABLED = True
AUTOTHROTTLE_START_DELAY = 1.5
AUTOTHROTTLE_MAX_DELAY = 60.0
AUTOTHROTTLE_TARGET_CONCURRENCY = 2.0

# --- throttling is a "not yet", not a "no" -------------------------------------------------
RETRY_ENABLED = True
# High on purpose: Reddit paging is a serial chain (page N+1 is only asked for once page N has
# answered), so a single page that exhausts its retries ends the whole walk mid-archive. Retrying
# hard is cheap next to losing the rest of the history.
RETRY_TIMES = 30
# The API mirror is one host carrying a serial walk, so it gets its own gentle slot rather than the
# board settings: one request at a time, a second apart. That is what keeps it from shedding load.
DOWNLOAD_SLOTS = {"arctic-shift.photon-reddit.com": {"concurrency": 1, "delay": 1.0, "jitter": 0.5}}
# 403 is in here on purpose: a busy WAF answers 403 while it is rate-limiting you, and it is the
# status board.tr returns under load. Without it the response is dropped and a whole board reads
# as "empty" instead of "blocked".
RETRY_HTTP_CODES = [400, 408, 422, 429, 500, 502, 503, 504, 522, 524]
# 422 is in there for Arctic Shift: it answers 422 intermittently while it is busy (the identical
# URL returns 200 a second later), which would otherwise end a Reddit walk mid-archive.
RETRY_GIVE_UP_LOG_LEVEL = "WARNING"
# 403/401 are NOT retried on purpose. Checked live: board.tr answers 403 "Erisim Reddedildi" to
# every user agent while its root page is public, so those boards are guest-restricted - a
# permission answer, not a throttle. Retrying it ten times per page is pure waste, and the HTTP
# cache does not store error responses, so a later run re-tries it naturally anyway.
# HTTPERROR_ALLOWED_CODES is left empty: by the time an error response reaches the spider,
# RetryMiddleware has had its goes, so it should be ignored and counted rather than parsed. Those
# counts are what tell the supervisor whether to run the host again.

# --- resumable, and re-parsable without re-fetching ----------------------------------------
HTTPCACHE_ENABLED = True
HTTPCACHE_DIR = str(CORPUS / ".scrapy-httpcache")
HTTPCACHE_EXPIRATION_SECS = 0
HTTPCACHE_IGNORE_HTTP_CODES = [400, 401, 403, 408, 422, 429, 500, 502, 503, 504]
HTTPCACHE_GZIP = True
# No JOBDIR: it persists the dupefilter, which turns re-running a finished crawl into a
# millisecond no-op that still reports `finish_reason: finished`. The HTTP cache above is what
# makes a re-run cheap (cached pages are re-parsed, not re-fetched).

# --- output --------------------------------------------------------------------------------
ITEM_PIPELINES = {"ogame_corpus.pipelines.CorpusWriter": 300}
FEED_EXPORT_ENCODING = "utf-8"
LOG_LEVEL = "INFO"
LOGSTATS_INTERVAL = 30
