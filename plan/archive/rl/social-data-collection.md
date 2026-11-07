# Social / diplomacy / community corpus (Modules/AI)

Why: the accounts must sound like ordinary players doing ordinary social life — applying, recruiting,
declaring war, trading, chatting (scorecard aspects `alliance`, `social`, `chat`). That vocabulary
lives on the community boards and Reddit, in every language, and much of the best material is the
2005–2012 "golden era" that only the Wayback Machine still holds. This is training-corpus collection,
not runtime code: nothing here runs inside a session, job or request.

## Collector

`bash Modules/AI/scripts/collect-social-corpus.sh` runs the whole pipeline and resumes on re-run
(every collector caches raw pages). The 13 language hosts are crawled **in parallel** (`JOBS=8` by
default) because each host is a separate community that owns its own `normalized/` directory, so
parallel workers never write the same file; Reddit and the Wayback archive hit different servers
again and run alongside them. Logs: `storage/rl/external/logs/` (one per host).
Read the result with `python3 Modules/AI/scripts/social-corpus-stats.py`.

`HOSTS="a b c"` crawls only those hosts (e.g. to finish the ones a previous run left throttled).

## Scrapy engine (`scrapers/`)
The boards are crawled with **Scrapy** (`ENGINE=scrapy`, the default): it is the right tool for
thousands of pages across 13 communities, and it brings out of the box what the stdlib collector
had to hand-roll - `AutoThrottle` (delay per domain tuned from observed latency and status codes),
`RetryMiddleware` (backs off on 429/5xx and keeps going), `CONCURRENT_REQUESTS` (many communities
at once) against `CONCURRENT_REQUESTS_PER_DOMAIN` (polite per board), `DOWNLOAD_DELAY_JITTER`, and
`HttpCacheMiddleware` as the raw-page cache so a re-run re-parses instead of re-fetching.

    scrapers/.venv/bin/scrapy crawl forum -a host=board.de.ogame.gameforge.com   # or:
    MAX_THREADS=300 ./scrapers/crawl.sh forum board.de.ogame.gameforge.com

Parsing is not duplicated: `ogame_corpus/parsers.py` loads the tested extractors from
`scripts/ogame-*.py`, so both engines share one WoltLab parser. Output is written to the same
`FORUM/<host>/normalized/*.jsonl`, and the pipeline is **idempotent** (it loads the identity keys
already on disk and drops items it has seen), so re-running a crawl - or running Scrapy over a host
the stdlib collector already did - cannot double-write a post.

`ENGINE=stdlib` puts the boards back on `scripts/ogame-forum-collect.py`. The Scrapy project also
has `reddit` and `archive` spiders; the supervisor still drives those two with the stdlib scripts.

| Step | Tool | Source |
| --- | --- | --- |
| Reddit | `scripts/ogame-reddit-collect.py` | PullPush archive API — `r/OGame` submissions and every comment |
| Forums | `scripts/ogame-forum-collect.py` | every Gameforge language board's alliance / diplomacy / community / archive sections |
| Archive | `scripts/ogame-archive-collect.py` | the 2005–2012 threads replayed from the Wayback Machine |

### Reddit (`ogame-reddit-collect.py`)
Pages `https://api.pullpush.io/reddit/search/{comment,submission}` backwards by `before=<created_utc>`
(PullPush's Pushshift successor; public, unauthenticated). 100 records/page, resumable from the cached
page files, 429-aware backoff. Comments keep `link_id`/`parent_id`, so the reply tree is reconstructable
and `thread.jsonl` joins a comment to the submission it answers. r/OGame holds ~2,019 submissions
(back to 2010) and a much larger comment history.

### Forums (`ogame-forum-collect.py`)
Every Gameforge board is one WoltLab Suite template, so one parser serves all of them. Alliance and
diplomacy threads sit **inside the universe boards** (DE "Uni 1", EN "Galactic Hub", FR "Commerce" /
"Taverne Galactique", HU "Diplomácia és kereskedelem", TR "İttifak", RU "Альянсы", US "Diplomacy"), so
the collector walks the whole board tree and then selects by name with a multilingual regex
(`DEFAULT_SECTIONS`), writing every board it found to `normalized/board.jsonl`. Languages crawled:
EN, US, DE, FR, IT, TR, RU, HU, RO, ES/MX/AR, PT/BR, Balkans (BA/HR) and PTS. `--all` crawls every
board; `--thread-match RE` keeps only threads whose title matches; `--boards 2156,1356` pins ids.

### Archive (`ogame-archive-collect.py`)
Wayback CDX index (`threadid=` / `Thread/NNN-slug/` / `viewtopic` / `index.php?thread/`, 2005–2013),
negated against `addreply` pages (Wayback archived those as login walls). Each capture is replayed as
`web.archive.org/web/<ts>id_/<original>` (raw bytes; the `id_` suffix is required — without it Wayback
returns a 404 that reads like "never archived"). One `thread` record per page: title, date, text.

## Output
`storage/rl/external/sources/{REDDIT/<subreddit>,FORUM/<host_key>,ARCHIVE/<host_key>}/` each hold
`raw/*` (gzipped pages, the resumable cache), `normalized/*.jsonl` and `manifest.json`. Raw pages and
normalized records are large and public: **they stay out of git.** Normalized files are flushed per
board, so a multi-hour host crawl is readable and crash-safe mid-run.

## Privacy
Author handles are pseudonymized with a per-source HMAC salt, so a thread's turn structure survives
while the identity does not. Message text, titles and linkage are kept (that is the language data).
`--keep-authors` stores the public handle instead, only for a study that is about the handle itself.
## Scope note
Bulk corpora are not individual evidence sources, so they are **not** registered in
`SOURCE-REGISTRY.yaml`; the `ogame-source-ingest` skill registers single curated URLs (e.g. TP-023,
the ogame.life alliance guide) when they carry a claim. This collector feeds *language and social
behaviour*; strategy numbers still come from the WIK/`resources/behavior` path.
