#!/usr/bin/env python3
"""Collect the OGame "golden era" (2005-2012) community forum threads from the Wayback Machine.

Why this exists: the accounts have to sound like players, and the social/diplomacy behaviour the
scorecard measures (recruitment, war and peace, trade, aid, tavern talk) lived on the old OGame
community boards - board.ogame.org, board.ogame.de and the early Gameforge boards. Those boards are
gone; only the Wayback Machine still holds their threads. This collector replays the archived
captures into normalized text records so the social/diplomacy language model can learn the register
of the era.

Pipeline: the CDX index lists archived thread URLs per host (2005-2013, thread-shaped URLs only),
each capture is replayed with the `id_` raw-bytes suffix (dropping it yields a Wayback 404 that
looks like "never archived"), and every replayed page becomes ONE normalized `thread` record with
its title, a best-effort posted date and the visible post text. Unknown markup is not an error:
pages whose post containers are not recognised fall back to the whole body text - the fallback is
the point, the old boards used several templates.

Access: ordinary GETs with a browser user agent against a public, permitted archive. The crawl is
bounded (--max-threads pages per run), polite (--delay) and resumable (a saved page is never
re-fetched, so a re-run re-parses the cache with no network for those pages).

Usage:
    scripts/ogame-archive-collect.py --host board.ogame.org --max-threads 5
    scripts/ogame-archive-collect.py --cdx-limit 400 --max-threads 100 --delay 1.0
    scripts/ogame-archive-collect.py --self-check

Raw pages are large and public: they live under host `storage/rl/external/` and stay out of git.
"""

import argparse
import datetime
import gzip
import hashlib
import html
import html.parser
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

UA = "Mozilla/5.0 (compatible; OGameX-AI-research/1.0)"
PARSER_VERSION = "1"
CDX_BASE = "http://web.archive.org/cdx/search/cdx"
REPLAY_BASE = "https://web.archive.org/web/"
# An archived thread page's last path segment starts with its numeric thread id, e.g.
# `board174-news/board175-the-game/10707-who-is-your-game-operator/` (WBB3) or
# `board1372-.../1003599-...` - board indexes and `board.php?boardid=` pages do not match, so
# nothing but threads and reply/hub pages (dropped by the negated filter in `cdx_url`) comes back.
THREAD_FILTER = r"original:.*/[0-9]{3,}[^/]*/?$"
DEFAULT_HOSTS = ("board.ogame.org", "board.ogame.de", "board.origin.ogame.gameforge.com",
                 "board.en.ogame.gameforge.com", "board.fr.ogame.gameforge.com",
                 "board.us.ogame.gameforge.com")
PROGRESS_EVERY = 10

TITLE = re.compile(r"<title[^>]*>(.*?)</title>", re.S | re.I)
# Post containers of the three template families the era used; a page with none of them falls back
# to the whole body. `post`/`message`/`postbody`/`content` are the WBB2 + WBB3 + phpBB class names.
POST_CONTAINER = re.compile(r'<div[^>]*class="[^"]*\b(post|message|postbody|content)\b[^"]*"', re.I)
DIV_TOKEN = re.compile(r"<div\b|</div>", re.I)
NAV = re.compile(r"<nav\b[^>]*>.*?</nav>", re.S | re.I)
BODY = re.compile(r"<body[^>]*>(.*?)</body>", re.S | re.I)
DATE_PATTERNS = (
    re.compile(r'datetime="([^"]+)"'),
    re.compile(r"(\d{1,2}\.\d{1,2}\.\d{4}(?:\s+\d{1,2}:\d{2})?)"),
    re.compile(r"([A-Z][a-z]{2,8}\s+\d{1,2},\s+\d{4})"),
    re.compile(r"(\d{4}-\d{2}-\d{2})"),
)


def now():
    return datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def get(url):
    last = None
    for attempt in range(5):
        try:
            request = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(request, timeout=45) as response:
                return response.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
            last = error
            # Wayback throttles hard and 503s under load; wait it out rather than losing the capture.
            if error.code in (429, 502, 503, 504):
                time.sleep(15 * (attempt + 1))
                continue
            time.sleep(2 ** attempt)
        except (urllib.error.URLError, TimeoutError, OSError) as error:
            last = error
            time.sleep(2 ** attempt)
    raise RuntimeError(f"GET {url} failed after retries: {last}")


def fetch(url):
    """A page, or None on failure: one dead capture must never abort the run."""
    try:
        return get(url)
    except RuntimeError as error:
        print(f"  SKIP {url}: {error}", file=sys.stderr, flush=True)
        return None


def save_text(path, text):
    tmp = path + ".tmp"
    with gzip.open(tmp, "wb") as handle:
        handle.write(text.encode())
    os.replace(tmp, path)


def read_text(path):
    opener = gzip.open if path.endswith(".gz") else open
    with opener(path, "rb") as handle:
        return handle.read().decode("utf-8", "replace")


def write_jsonl(path, records):
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as handle:
        for record in records:
            handle.write(json.dumps(record, ensure_ascii=False, sort_keys=True) + "\n")
    os.replace(tmp, path)


def write_manifest(out_dir, manifest):
    with open(os.path.join(out_dir, "manifest.json"), "w", encoding="utf-8") as handle:
        json.dump(manifest, handle, indent=2, sort_keys=True)


def host_key(host):
    return host.replace(".", "_")


def cdx_url(host, limit):
    """The CDX index query for one host's archived thread URLs (2005-2013).

    A second, negated filter drops the capture that dominates these hosts: `addreply.php` reply
    forms, which Wayback archived as login walls ("Access denied") and which carry no post text.
    """
    params = [("url", host), ("matchType", "domain"), ("filter", THREAD_FILTER),
              ("filter", r"!original:.*(addreply|formmail|\.css|\.js|\.png|\.gif|\.jpg).*"),
              ("collapse", "urlkey"), ("fl", "original,timestamp,statuscode"),
              ("from", "2005"), ("to", "2015"), ("limit", limit)]
    return f"{CDX_BASE}?{urllib.parse.urlencode(params)}"


def replay_url(timestamp, original):
    """A capture replayed as raw original bytes: the `id_` suffix is what makes it the page itself,
    not Wayback's banner-wrapped rendering."""
    return f"{REPLAY_BASE}{timestamp}id_/{original}"


def raw_filename(original_url, timestamp):
    return f"{hashlib.sha1(original_url.encode('utf-8')).hexdigest()}-{timestamp}.html.gz"


def parse_cdx(text):
    """The CDX text response as [{original, timestamp, statuscode}], header and blanks dropped."""
    rows = []
    for line in text.splitlines():
        stripped = line.strip()
        if not stripped or stripped.lower().startswith("original"):
            continue
        parts = stripped.split()
        if len(parts) < 2:
            continue
        rows.append({"original": parts[0], "timestamp": parts[1], "statuscode": parts[2] if len(parts) > 2 else ""})
    return rows


class TextExtractor(html.parser.HTMLParser):
    """Old-board HTML to text: script/style and sidebar chrome are dropped, block tags become
    line breaks.

    The sidebar is the reason this is not a one-liner: a WBB post carries the author's post count,
    location and occupation beside the message, and keeping it would teach the model that a forum
    post is mostly "Posts: 2,441 Location: In a Vegemite Jar".
    """

    BLOCK = {"p", "br", "div", "li", "ul", "ol", "tr", "h1", "h2", "h3", "h4", "h5", "h6",
             "blockquote", "pre", "hr"}
    CHROME = re.compile(r"userInfo|userCredits|messageSidebar|skiplink|breadcrumb|pageNavigation"
                        r"|userAvatar|wbbFooter|messageFooter", re.I)

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.parts, self.skip, self.skipping = [], 0, []

    def _chrome(self, attrs):
        return any(name in ("class", "id") and value and self.CHROME.search(value) for name, value in attrs)

    def handle_starttag(self, tag, attrs):
        if tag in ("script", "style") or self._chrome(attrs):
            self.skip += 1
            self.skipping.append(tag)
        if tag in self.BLOCK:
            self.parts.append("\n")

    def handle_endtag(self, tag):
        if self.skipping and self.skipping[-1] == tag:
            self.skipping.pop()
            self.skip -= 1
        if tag in self.BLOCK:
            self.parts.append("\n")

    def handle_data(self, data):
        if not self.skip:
            self.parts.append(data)

    def text(self):
        merged = re.sub(r"[ \t]+", " ", "".join(self.parts))
        merged = re.sub(r"\n\s*\n\s*", "\n\n", merged)
        return "\n".join(line.strip() for line in merged.splitlines()).strip()


def html_to_text(markup):
    extractor = TextExtractor()
    extractor.feed(markup)
    extractor.close()
    return extractor.text()


def balanced_div(document, start):
    """The inner HTML after a <div> opening at `start`, and the index where it closes."""
    depth = 1
    for token in DIV_TOKEN.finditer(document, start):
        depth += -1 if token.group(0).startswith("</") else 1
        if not depth:
            return document[start:token.start()], token.start()
    return document[start:], len(document)


def page_title(document):
    """<title> with the obvious forum wrappers stripped (prefixes like "View topic", suffixes
    like " - OGame Forum")."""
    match = TITLE.search(document)
    if not match:
        return ""
    title = re.sub(r"\s+", " ", html.unescape(match.group(1))).strip()
    title = re.sub(r"^(View topic|Viewing topic|Thema)\s*[-\u2013:|]\s*", "", title, flags=re.I)
    return re.sub(r"\s*[-\u2013|:]\s*(OGame.*|WoltLab.*|phpBB.*|Forum.*|Thema.*)$", "", title, flags=re.I).strip()


def find_posted_at(document):
    """A date if one is plainly findable in the page, else None - never invented."""
    for pattern in DATE_PATTERNS:
        match = pattern.search(document)
        if match:
            return match.group(1).strip()
    return None


def page_text(document):
    """The joined text of recognised post containers, else the whole body as a fallback."""
    parts, consumed = [], 0
    for match in POST_CONTAINER.finditer(document):
        if match.start() < consumed:
            continue  # nested in a container already taken; taking it again would duplicate text
        inner, consumed = balanced_div(document, match.end())
        text = html_to_text(inner)
        if text:
            parts.append(text)
    if parts:
        return "\n\n".join(parts)
    body = BODY.search(document)
    return html_to_text(NAV.sub(" ", body.group(1) if body else document))


def parse_page(document, host, timestamp, original_url, captured):
    return {"source_id": "ARCHIVE", "type": "thread", "host": host, "timestamp": timestamp,
            "original_url": original_url, "title": page_title(document),
            "posted_at": find_posted_at(document), "text": page_text(document),
            "captured_at": captured, "parser_version": PARSER_VERSION}


def run_host(host, args, captured, budget):
    out_root = os.path.join(args.out, "sources", "ARCHIVE", host_key(host))
    raw_root = os.path.join(out_root, "raw")
    normalized = os.path.join(out_root, "normalized")
    os.makedirs(raw_root, exist_ok=True)
    os.makedirs(normalized, exist_ok=True)

    index = fetch(cdx_url(host, args.cdx_limit))
    if index is None:
        return {"thread": 0}
    rows = [row for row in parse_cdx(index) if row["statuscode"] in ("200", "")]
    print(f"ARCHIVE {host}: {len(rows)} archived thread URL(s) in the CDX index", flush=True)

    records, fetched = [], 0
    for row in rows:
        if budget["left"] <= 0:
            break
        path = os.path.join(raw_root, raw_filename(row["original"], row["timestamp"]))
        if os.path.exists(path):
            records.append(parse_page(read_text(path), host, row["timestamp"], row["original"], captured))
            continue  # resumable: a saved page is re-parsed, never re-fetched
        document = fetch(replay_url(row["timestamp"], row["original"]))
        if document is None:
            continue
        save_text(path, document)
        records.append(parse_page(document, host, row["timestamp"], row["original"], captured))
        fetched += 1
        budget["left"] -= 1
        time.sleep(args.delay)
        if fetched % PROGRESS_EVERY == 0:
            print(f"ARCHIVE {host}: {fetched} page(s) fetched", flush=True)

    write_jsonl(os.path.join(normalized, "thread.jsonl"), records)
    counts = {"thread": len(records)}
    write_manifest(out_root, {"source_id": "ARCHIVE", "hosts": [host], "captured_at": captured,
                              "parser_version": PARSER_VERSION, "record_counts": counts})
    print(f"ARCHIVE {host}: {counts}")
    return counts


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--out", default="storage/rl/external", help="output root (default: storage/rl/external)")
    parser.add_argument("--host", action="append", help="a legacy host to collect (repeatable; default all)")
    parser.add_argument("--max-threads", type=int, default=50, help="hard cap on pages fetched per run (default 50)")
    parser.add_argument("--delay", type=float, default=1.0, help="seconds between requests (default 1.0)")
    parser.add_argument("--cdx-limit", type=int, default=200, help="CDX rows per host (default 200)")
    parser.add_argument("--self-check", action="store_true")
    args = parser.parse_args(argv)

    if args.self_check:
        self_check()
        return 0

    captured = now()
    budget = {"left": args.max_threads}
    counts = [run_host(host, args, captured, budget) for host in (args.host or list(DEFAULT_HOSTS))]
    total = {"thread": sum(count.get("thread", 0) for count in counts)}
    print(f"ARCHIVE: {total}")
    return 0


# --self-check: inline fixtures of the real templates. The live path drives these same functions,
# so a break here fails first.
WBB3 = """<html><head><title>Diplomacy: NAP offer - OGame Forum</title></head><body>
<ul class="breadcrumbs"><li>Home</li></ul>
<div class="post" id="post1"><div class="content"><p>Hello, we propose a NAP.</p><p>Contact me in game.</p></div></div>
<div class="post" id="post2"><div class="content"><p>Accepted. Deal.</p></div></div>
</body></html>"""
UNKNOWN = """<html><head><title>View topic - Old trade thread</title></head><body>
<nav><a href="/">Board index</a></nav>
<table><tr><td>Selling deuterium at 3:2:1, no markup of a known template here.</td></tr></table>
</body></html>"""


def self_check():
    wbb3 = parse_page(WBB3, "board.ogame.org", "20070101120000", "http://board.ogame.org/threadid=5", "2026-10-11T00:00:00Z")
    assert wbb3["title"] == "Diplomacy: NAP offer", wbb3["title"]
    assert "propose a NAP" in wbb3["text"] and "Accepted. Deal." in wbb3["text"], wbb3["text"]
    assert wbb3["text"].count("propose a NAP") == 1, "a nested container is not counted twice"
    unknown = parse_page(UNKNOWN, "board.ogame.org", "20080101120000", "http://x/viewtopic.php?t=9", "2026-10-11T00:00:00Z")
    assert unknown["title"] == "Old trade thread", unknown["title"]
    assert "Selling deuterium" in unknown["text"] and "Board index" not in unknown["text"], unknown["text"]
    assert replay_url("20070101120000", "http://board.ogame.org/threadid=5") == \
        "https://web.archive.org/web/20070101120000id_/http://board.ogame.org/threadid=5"
    assert cdx_url("board.ogame.org", 200) == cdx_url("board.ogame.org", 200), "CDX url is deterministic"
    assert cdx_url("board.ogame.org", 200).startswith(f"{CDX_BASE}?url=board.ogame.org&matchType=domain")
    assert raw_filename("http://x/y", "20070101120000") == \
        f"{hashlib.sha1(b'http://x/y').hexdigest()}-20070101120000.html.gz"
    assert parse_cdx("original timestamp statuscode\nhttp://x/t id 200\n\n") == \
        [{"original": "http://x/t", "timestamp": "id", "statuscode": "200"}]
    print("self-check ok (WBB3 posts, unknown-markup fallback, title, CDX/replay/raw builders)")


if __name__ == "__main__":
    sys.exit(main())
