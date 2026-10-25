#!/usr/bin/env python3
"""Collect the public OGame WoltLab boards into normalized board/thread/post records.

Why this exists: alliances, diplomacy and the social side are first-class player behaviour
(scorecard aspects: alliance, social, chat), and the official boards are where players
actually do it - recruitment posts, applications, war and peace declarations, trade offers,
roleplay and idle tavern talk. That is the "alliance language" the accounts have to sound
like, and it exists in every language community, not only English. Every Gameforge board
runs the same WoltLab Suite template, so one parser serves all of them; the alliance and
diplomacy boards sit inside each universe board, so the tree is walked to find them.

Languages crawled by default (the ones that resolve): EN, DE, FR, ES/MX/AR, PT/BR, IT, TR,
US, RU, HU, RO, Balkans (BA/HR) and the PTS board. A language with no host of its own is a
sub-board of board.en (OGame NL/DK/CZ/GR there), reached by the same walk.

Access: ordinary GETs with a browser user agent. No host serves a robots.txt (it 301s to the
homepage), so this is a permitted public page per the ETL plan's method 3. The crawl is slow
(--delay), resumable (a saved page is never re-fetched, so re-running the same command
re-parses the cache with no network) and bounded by explicit caps.

Privacy: author handles become a stable pseudonym per host (a thread's turn structure
survives, the identity does not). Post text, titles and thread linkage are kept. Pass
--keep-authors only where the public handle itself is the subject of study.

Usage:
    scripts/ogame-forum-collect.py --discover                  # board tree of every language, no threads
    scripts/ogame-forum-collect.py                             # default: the alliance/social sections, all languages
    scripts/ogame-forum-collect.py --host board.us.ogame.gameforge.com --boards 1356
    scripts/ogame-forum-collect.py --all --max-threads 300      # every board of every language
    scripts/ogame-forum-collect.py --self-check

Raw pages are large and public: they live under host `storage/rl/external/` and stay out of git.
"""

import argparse
import datetime
import gzip
import hashlib
import hmac
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

# host -> the page that lists the board tree. Every Gameforge language board that resolves
# (a country with no host, e.g. NL/DK/CZ/GR, is a sub-board of board.en and is reached there).
HOSTS = {
    "board.en.ogame.gameforge.com": "https://board.en.ogame.gameforge.com/",
    "board.us.ogame.gameforge.com": "https://board.us.ogame.gameforge.com/",
    "board.de.ogame.gameforge.com": "https://board.de.ogame.gameforge.com/",
    "board.fr.ogame.gameforge.com": "https://board.fr.ogame.gameforge.com/",
    "board.it.ogame.gameforge.com": "https://board.it.ogame.gameforge.com/",
    "board.tr.ogame.gameforge.com": "https://board.tr.ogame.gameforge.com/",
    "board.ru.ogame.gameforge.com": "https://board.ru.ogame.gameforge.com/",
    "board.hu.ogame.gameforge.com": "https://board.hu.ogame.gameforge.com/",
    "board.ro.ogame.gameforge.com": "https://board.ro.ogame.gameforge.com/",
    "forum.es.ogame.gameforge.com": "https://forum.es.ogame.gameforge.com/",
    "forum.pt.ogame.gameforge.com": "https://forum.pt.ogame.gameforge.com/forum/",
    "forum.ba.ogame.gameforge.com": "https://forum.ba.ogame.gameforge.com/",
    "forum.origin.ogame.gameforge.com": "https://forum.origin.ogame.gameforge.com/forum/",
}
# The sections a good player's social/diplomatic life lives in, named as each community names
# them. "Galactic Hub" (EN) and the per-universe boards ("Diplomácia és kereskedelem",
# "Commerce"/"Taverne Galactique", "İttifak", "Альянсы", "Bündnis", "Diplomacy") carry the
# recruitment, war, peace and trade posts. Case-insensitive; --boards or --all override it.
DEFAULT_SECTIONS = (r"galactic hub|allian|aliance|alianz|alleanza|alian[çc]a|ittifak|альянс|союз|szövetség"
                    r"|bündnis|diploma|diplomaci|diplomácia|dyplomac|\bwar\b|savaş|войн|guerra|guerre|krieg"
                    r"|batalla|pakt|pact|trade|handel|commerce|comercio|торговля|kereskedelem|market|marktplatz"
                    r"|communit|gemeinschaft|сообществ|közösség|comunitate|comunidad|comunaut|comunità|comunidade"
                    r"|social|tavern|taverne|tabern|tasca|spam|off.?topic|role|rollenspiel|racconti"
                    r"|clan|guilde|gilde|gilda|guild|introduc|presenta|vorstell|ingresso|raduni|forumske|\bbar\b"
                    # universe boards: the alliance/diplomacy threads live inside them (e.g. DE "Uni 1")
                    r"|\buni\b|schreibwerkstatt|bibliothek|abenteuerspielplatz|stammtisch|allgemein|abenteuer"
                    # archives: the 2005-2012 golden-era threads a board still hosts (e.g. EN "Archive v3")
                    r"|archiv|archive|historie|history|sonstiges|muellhalde|müllhalde|oldschool")

BOARD_ANCHOR = re.compile(r'<a[^>]*href="([^"]*?board/(\d+)-[^"/]*/?)"[^>]*>(.*?)</a>', re.S | re.I)
TOPIC_ANCHOR = re.compile(r'<a[^>]*class="[^"]*\bwbbTopicLink\b[^"]*"[^>]*>(.*?)</a>', re.S)
HREF = re.compile(r'href="([^"]+)"')
POST_OPEN = re.compile(r'<article[^>]*class="[^"]*\bwbbPost\b[^"]*"[^>]*>', re.S)
PAGINATION = re.compile(r'<nav[^>]*class="[^"]*\bpagination\b[^"]*"[^>]*>', re.S)
TITLE = re.compile(r"<title>(.*?)</title>", re.S)
DIV_TOKEN = re.compile(r"<div\b|</div>", re.I)


def now():
    return datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def get(url):
    last = None
    for attempt in range(5):
        try:
            request = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(request, timeout=25) as response:
                return response.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
            last = error
            # 429 and 5xx are the server telling us to slow down, not to stop. Wait it out; the
            # supervisor re-runs any host that still ends with skipped pages, so a lost page is
            # only ever deferred.
            if error.code in (429, 502, 503, 504):
                time.sleep(10 * (attempt + 1))
                continue
            time.sleep(2 ** attempt)
        except (urllib.error.URLError, TimeoutError, OSError) as error:
            last = error
            time.sleep(1 + attempt)
    raise RuntimeError(f"GET {url} failed after retries: {last}")


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


class TextExtractor(html.parser.HTMLParser):
    """WoltLab post HTML to text: block elements become line breaks, nothing else is kept."""

    BLOCK = {"p", "br", "div", "li", "ul", "ol", "tr", "h1", "h2", "h3", "h4", "h5", "h6",
             "blockquote", "pre", "hr"}

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.parts, self.skip = [], 0

    def handle_starttag(self, tag, attrs):
        if tag in ("script", "style"):
            self.skip += 1
        if tag in self.BLOCK:
            self.parts.append("\n")

    def handle_endtag(self, tag):
        if tag in ("script", "style") and self.skip:
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


def strip_tags(markup):
    return html.unescape(re.sub(r"<[^>]+>", "", markup)).strip()


def salt_for(host):
    return hashlib.sha256(f"FORUM:{host}".encode()).hexdigest()


def pseudo(salt, raw):
    if raw in (None, ""):
        return None
    return hmac.new(salt.encode(), f"author:{raw}".encode(), hashlib.sha256).hexdigest()[:16]


def pagination(document):
    """(page-url template, page count) from a WoltLab paginator, else (None, 1)."""
    nav = PAGINATION.search(document)
    if not nav:
        return None, 1
    tag = nav.group(0)
    link = re.search(r'data-link="([^"]+)"', tag)
    pages = re.search(r'data-pages="(\d+)"', tag)
    return (html.unescape(link.group(1)) if link else None,
            int(pages.group(1)) if pages else 1)


def board_links(document, base_url):
    """Every board named by a page, as {id: (name, absolute url)}."""
    found = {}
    for match in BOARD_ANCHOR.finditer(document):
        board_id = match.group(2)
        name = strip_tags(match.group(3))
        if not name or board_id in found:
            continue
        found[board_id] = (name, urllib.parse.urljoin(base_url, html.unescape(match.group(1))))
    return found


def thread_links(document, base_url):
    """Every thread a board page or thread page names, as {id: (title, absolute url)}."""
    found = {}
    for match in TOPIC_ANCHOR.finditer(document):
        href = HREF.search(match.group(0))
        if not href:
            continue
        thread_id = re.search(r"thread/(\d+)-", href.group(1))
        if not thread_id:
            continue
        found.setdefault(thread_id.group(1), (strip_tags(match.group(1)), urllib.parse.urljoin(base_url, html.unescape(href.group(1)))))
    return found


def balanced_div(document, start):
    """The inner HTML of the div opened before `start`, matching nested divs."""
    depth = 1
    for token in DIV_TOKEN.finditer(document[start:]):
        depth += -1 if token.group(0).startswith("</") else 1
        if depth == 0:
            return document[start:start + token.start()]
    return document[start:]


def parse_posts(document, thread, board, host, salt, captured, keep_authors):
    """One thread page's posts, with the author pseudonymized and the body kept."""
    posts = []
    opens = list(POST_OPEN.finditer(document))
    for index, open_tag in enumerate(opens):
        end = opens[index + 1].start() if index + 1 < len(opens) else len(document)
        slice_ = document[open_tag.start():end]
        tag = open_tag.group(0)
        post_id = re.search(r'data-post-id="(\d+)"', tag)
        user_id = re.search(r'data-user-id="(\d+)"', tag)
        author_link = re.search(r'<a[^>]*class="[^"]*\busername\b[^"]*"[^>]*>(.*?)</a>', slice_, re.S)
        stamp = re.search(r'<time[^>]*datetime="([^"]+)"', slice_)
        body = re.search(r'<div class="messageText"[^>]*>', slice_)
        if not post_id or not body:
            continue
        raw_author = strip_tags(author_link.group(1)) if author_link else None
        posts.append({
            "source_id": "FORUM",
            "type": "post",
            "host": host,
            "board_id": board["board_id"],
            "board_name": board["name"],
            "thread_id": thread["thread_id"],
            "thread_title": thread["title"],
            "post_id": post_id.group(1),
            "author": (raw_author if keep_authors else pseudo(salt, user_id.group(1) if user_id else raw_author)),
            "posted_at": stamp.group(1) if stamp else None,
            "text": html_to_text(balanced_div(slice_, body.end())),
            "captured_at": captured,
            "parser_version": PARSER_VERSION,
        })
    return posts


def thread_title(document, fallback):
    match = TITLE.search(document)
    if not match:
        return fallback
    title = html.unescape(match.group(1)).strip()
    return re.sub(r"\s*[-–]\s*(OGame Forum|PTS.*|Forum.*)$", "", title) or fallback


class Collector:
    """One host's crawl: cached fetches, board discovery and normalization."""

    def __init__(self, host, out_root, delay):
        self.host = host
        self.base = HOSTS[host]
        self.out_root = out_root
        self.raw_root = os.path.join(out_root, "raw")
        self.delay = delay
        self.salt = salt_for(host)
        self.skips = 0
        os.makedirs(self.raw_root, exist_ok=True)

    def fetch(self, url, key):
        """A page by stable key, cached on disk. A dead page returns None, never aborts a run."""
        path = os.path.join(self.raw_root, key + ".html.gz")
        if os.path.exists(path):
            return read_text(path)
        try:
            document = get(url)
        except RuntimeError as error:
            self.skips += 1
            print(f"  SKIP {key}: {error}", file=sys.stderr, flush=True)
            return None
        save_text(path, document)
        time.sleep(self.delay)
        return document

    def board_pages(self, board, max_pages):
        """Walk a board's thread-list pages, returning {thread_id: (title, url)}."""
        threads, page, url = {}, 1, board["url"]
        while True:
            document = self.fetch(url, f"board-{board['board_id']}-p{page}")
            if document is None:
                break
            for thread_id, entry in thread_links(document, url).items():
                threads.setdefault(thread_id, entry)
            template, pages = pagination(document)
            if not template or page >= pages or page >= max_pages:
                break
            page += 1
            # replace, not %-format: slugs carry percent-encoded characters ("%C3%BC").
            url = template.replace("%d", str(page))
        return threads

    def thread_posts(self, thread, board, max_pages, keep_authors, captured):
        """Walk a thread's post pages, returning its posts."""
        posts, page, url = [], 1, thread["url"]
        while True:
            document = self.fetch(url, f"thread-{thread['thread_id']}-p{page}")
            if document is None:
                break
            thread["title"] = thread_title(document, thread["title"]) if page == 1 else thread["title"]
            posts.extend(parse_posts(document, thread, board, self.host, self.salt, captured, keep_authors))
            template, pages = pagination(document)
            if not template or page >= pages or page >= max_pages:
                break
            page += 1
            url = template.replace("%d", str(page))
        return posts

    def discover(self, max_boards):
        """Breadth-first walk of the board tree from the host root.

        Alliance and diplomacy boards are nested inside universe boards, so a name filter on
        the root page would miss them; the whole tree is walked and then filtered.
        """
        boards, queue = {}, [(self.base, "root")]
        while queue and len(boards) < max_boards:
            url, key = queue.pop(0)
            document = self.fetch(url, key)
            if document is None:
                continue
            for board_id, (name, board_url) in board_links(document, url).items():
                if board_id in boards:
                    continue
                boards[board_id] = {"source_id": "FORUM", "type": "board", "host": self.host,
                                    "board_id": board_id, "name": name, "url": board_url}
                queue.append((board_url, f"board-{board_id}"))
            print(f"  discovered {len(boards)} board(s)", file=sys.stderr, flush=True)
        return boards


def select_boards(boards, args):
    if args.boards:
        wanted = {b.strip() for b in args.boards.split(",")}
        return {bid: b for bid, b in boards.items() if bid in wanted}
    if args.all:
        return dict(sorted(boards.items(), key=lambda item: int(item[0]))[:args.max_boards])
    pattern = re.compile(args.match, re.I)
    return {bid: b for bid, b in boards.items() if pattern.search(b["name"])}


def run_host(host, args, captured):
    host_key = host.replace(".", "_")
    out_root = os.path.join(args.out, "sources", "FORUM", host_key)
    collector = Collector(host, out_root, args.delay)
    normalized = os.path.join(out_root, "normalized")
    os.makedirs(normalized, exist_ok=True)

    boards = collector.discover(args.max_boards)
    write_jsonl(os.path.join(normalized, "board.jsonl"), list(boards.values()))
    if args.discover:
        write_manifest(out_root, host, {"access": "public WoltLab board", "captured_at": captured,
                                        "parser_version": PARSER_VERSION, "boards": len(boards)})
        print(f"FORUM {host}: discovered {len(boards)} board(s)")
        return {"board": len(boards)}

    selected = select_boards(boards, args)
    print(f"FORUM {host}: {len(selected)} of {len(boards)} board(s) selected", flush=True)

    all_threads, all_posts = [], []
    title_filter = re.compile(args.thread_match, re.I) if args.thread_match else None
    for board_id, board in selected.items():
        threads = collector.board_pages(board, args.max_board_pages)
        if title_filter:
            threads = {tid: entry for tid, entry in threads.items() if title_filter.search(entry[0])}
        for thread_id, (title, url) in list(threads.items())[:args.max_threads]:
            thread = {"source_id": "FORUM", "type": "thread", "host": host, "board_id": board_id,
                      "board_name": board["name"], "thread_id": thread_id, "title": title, "url": url,
                      "captured_at": captured, "parser_version": PARSER_VERSION}
            all_threads.append(thread)
            all_posts.extend(collector.thread_posts(dict(thread), board, args.max_thread_pages,
                                                    args.keep_authors, captured))
        print(f"FORUM {host}: board {board_id} ({board['name'][:30]}) -> {len(all_threads)} thread(s), "
              f"{len(all_posts)} post(s)", flush=True)
        # Flush per board: a host crawl runs for hours, and a crash must not lose every post so far.
        write_jsonl(os.path.join(normalized, "thread.jsonl"), all_threads)
        write_jsonl(os.path.join(normalized, "post.jsonl"), all_posts)

    counts = {"board": len(selected), "thread": len(all_threads), "post": len(all_posts),
              "skipped": collector.skips}
    write_manifest(out_root, host, {"access": "public WoltLab board", "captured_at": captured,
                                   "parser_version": PARSER_VERSION, "pseudonymized": not args.keep_authors,
                                   "record_counts": counts})
    # A high skip count means the host throttled us: re-run the same command (cached pages are
    # reused, only the skipped ones are retried).
    if collector.skips:
        print(f"FORUM {host}: WARNING {collector.skips} page(s) skipped - re-run to retry them", flush=True)
    print(f"FORUM {host}: {counts}")
    return counts


def write_manifest(out_dir, host, manifest):
    manifest = {"source_id": "FORUM", "host": host, **manifest}
    with open(os.path.join(out_dir, "manifest.json"), "w", encoding="utf-8") as handle:
        json.dump(manifest, handle, indent=2, sort_keys=True)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--out", default="storage/rl/external", help="output root (default: storage/rl/external)")
    parser.add_argument("--host", action="append", help="crawl only this host (repeatable; default both)")
    parser.add_argument("--boards", help="comma-separated board ids to crawl")
    parser.add_argument("--all", action="store_true", help="crawl every discovered board, not just the alliance/social sections")
    parser.add_argument("--match", default=DEFAULT_SECTIONS, help="board-name regex for the default selection")
    parser.add_argument("--thread-match", default="", help="thread-title regex; keep only threads whose title matches")
    parser.add_argument("--discover", action="store_true", help="walk the board tree and stop (no threads)")
    parser.add_argument("--keep-authors", action="store_true", help="store the public handle instead of a pseudonym")
    parser.add_argument("--max-boards", type=int, default=5000, help="cap on boards discovered (default 5000)")
    parser.add_argument("--max-board-pages", type=int, default=5, help="thread-list pages per board (default 5)")
    parser.add_argument("--max-threads", type=int, default=200, help="threads per board (default 200)")
    parser.add_argument("--max-thread-pages", type=int, default=5, help="post pages per thread (default 5)")
    parser.add_argument("--delay", type=float, default=0.5, help="seconds between requests (default 0.5)")
    parser.add_argument("--self-check", action="store_true")
    args = parser.parse_args(argv)

    if args.self_check:
        self_check()
        return 0

    hosts = args.host or list(HOSTS)
    captured = now()
    counts = {host: run_host(host, args, captured) for host in hosts}
    total = {key: sum(c.get(key, 0) for c in counts.values()) for key in ("board", "thread", "post")}
    print(f"FORUM total: {total}")
    return 0


# --self-check: real markup fragments. The live path drives the same functions.
POST = """<article class="wbbPost message jsClipboardObject" data-post-id="7271606" data-user-id="187742">
<a class="username" href="https://board.en.ogame.gameforge.com/wcf/index.php?user/187742-jjmonti/">JJMonti</a>
<time datetime="2026-05-19T20:07:44+02:00">19 May 2026</time>
<div class="messageBody"><div class="messageText" itemprop="text"><p>Contest: <b>GUESS</b> THE NUMBER</p>
<blockquote><div>quoted</div></blockquote></div></div></article>
<article class="wbbPost message" data-post-id="7271610" data-user-id="186712">
<a class="username" href="/wcf/index.php?user/186712-x/">Guest Ghoster</a>
<div class="messageBody"><div class="messageText" itemprop="text"><p>I am in. 42</p></div></div></article>"""
THREAD_PAGE = """<html><head><title>Contest: GUESS THE NUMBER - OGame Forum</title></head><body>
<a class="wbbTopicLink" href="https://board.en.ogame.gameforge.com/index.php?thread/856745-contest-guess-the-number/">Contest: GUESS THE NUMBER</a>
<nav class="pagination" data-link="https://board.en.ogame.gameforge.com/index.php?thread/856745-contest-guess-the-number/&amp;pageNo=%d" data-pages="3"></nav>
</body></html>"""
BOARD_PAGE = """<a href="index.php?board/2156-galactic-hub/">Galactic Hub</a>
<a href="index.php?board/704-social-spamboard/">Social Spamboard</a>
<nav class="pagination" data-link="https://board.en.ogame.gameforge.com/index.php?board/2156-galactic-hub/&amp;pageNo=%d" data-pages="15"></nav>"""


def self_check():
    captured = "2026-10-10T00:00:00Z"
    host = "board.en.ogame.gameforge.com"
    board = {"board_id": "856745", "name": "Galactic Hub"}
    thread = {"thread_id": "856745", "title": "x", "url": "u"}
    posts = parse_posts(POST, thread, board, host, salt_for(host), captured, False)
    assert len(posts) == 2, "both posts parse"
    assert posts[0]["post_id"] == "7271606" and posts[0]["board_name"] == "Galactic Hub"
    assert posts[0]["posted_at"] == "2026-05-19T20:07:44+02:00"
    assert posts[0]["text"].startswith("Contest: GUESS THE NUMBER") and "quoted" in posts[0]["text"]
    assert posts[1]["text"] == "I am in. 42"
    assert posts[0]["author"] == pseudo(salt_for(host), "187742") and "JJMonti" not in json.dumps(posts)
    kept = parse_posts(POST, thread, board, host, salt_for(host), captured, True)
    assert kept[1]["author"] == "Guest Ghoster", "--keep-authors stores the handle"
    template, pages = pagination(THREAD_PAGE)
    assert pages == 3 and template.endswith("pageNo=%d"), (template, pages)
    links = thread_links(THREAD_PAGE, HOSTS[host])
    assert links["856745"][0] == "Contest: GUESS THE NUMBER"
    boards = board_links(BOARD_PAGE, HOSTS[host])
    assert boards["2156"][0] == "Galactic Hub" and boards["2156"][1].endswith("board/2156-galactic-hub/")
    assert thread_title(THREAD_PAGE, "fallback") == "Contest: GUESS THE NUMBER"
    print("self-check ok (posts, author pseudonym, pagination, board/thread links, title)")


if __name__ == "__main__":
    sys.exit(main())
