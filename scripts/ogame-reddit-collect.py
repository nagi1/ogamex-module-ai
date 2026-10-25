#!/usr/bin/env python3
"""Collect the public r/OGame community into normalized, thread-linked dialogue records.

Why this exists: the module's social and language behaviour is judged by what an account
says and how it answers (scorecard aspects: social, chat, alliance language). The OGame
subreddit is a large, public, years-deep corpus of exactly that - questions, trade and
alliance offers, raid stories, diplomacy and reply threads - which the wiki and the official
board do not cover at that volume.

Source: the PullPush archive API (`https://api.pullpush.io/reddit/search/{comment,submission}`),
the Pushshift successor that serves historical Reddit data anonymously. Docs: https://pullpush.io/.
An ordinary GET with a browser user agent against a documented endpoint; no auth, no login,
no access-control bypass. It is crawled slowly (--delay) and resumably.

Privacy: author handles are pseudonymized through a per-subreddit salt, so a reply's author is
stable within one contribution (the turn structure survives) while the identity does not.
Message bodies, titles and thread linkage are kept - that is the language data.

Usage:
    scripts/ogame-reddit-collect.py --subreddit ogame --kind submission --max-pages 5
    scripts/ogame-reddit-collect.py --kind comment --max-pages 200      # resumable; re-run to continue
    scripts/ogame-reddit-collect.py --kind comment --max-pages 3       # re-parse the cache, no network
    scripts/ogame-reddit-collect.py --self-check

Raw payloads are large and public: they live under host `storage/rl/external/` and stay out of git.
"""

import argparse
import datetime
import gzip
import hashlib
import hmac
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = "https://api.pullpush.io"
UA = "Mozilla/5.0 (compatible; OGameX-AI-research/1.0)"
PARSER_VERSION = "1"
PAGE_SIZE = 100          # the API's maximum page
KINDS = {"comment": "comment", "submission": "submission"}

# Two public mirrors of the same Reddit archive, answering the same JSON shape (so `parse_page`
# serves both). PullPush throttles the whole client with 429 once it is busy; Arctic Shift is a
# newer mirror that has not, which is why it is the default.
SOURCES = {
    "arctic": ("https://arctic-shift.photon-reddit.com/api", "{kind}/search",
               {"comment": "comments", "submission": "posts"}, "limit"),
    "pullpush": ("https://api.pullpush.io", "reddit/search/{kind}/",
                 {"comment": "comment", "submission": "submission"}, "size"),
}
DEFAULT_SOURCE = "arctic"


def search_url(kind, subreddit, size, before=None, source=DEFAULT_SOURCE):
    """The paging URL for one API page, for whichever mirror is in use."""
    base, path, kinds, size_key = SOURCES[source]
    query = {"subreddit": subreddit, size_key: size, "sort": "desc"}
    if source == "pullpush":
        query["sort_type"] = "created"
    if before is not None:
        query["before"] = before
    return f"{base}/{path.format(kind=kinds[kind])}?{urllib.parse.urlencode(query)}"


def now():
    return datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def get(url):
    last = None
    for attempt in range(6):
        try:
            request = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(request, timeout=60) as response:
                return response.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
            last = error
            # PullPush throttles the whole client with 429; wait, then give the round back to the
            # caller (the supervisor retries with a long cool-down) rather than burning minutes here.
            time.sleep(90 if error.code == 429 else 2 ** attempt)
        except (urllib.error.URLError, TimeoutError) as error:
            last = error
            time.sleep(2 ** attempt)
    raise RuntimeError(f"GET {url} failed after retries: {last}")


def save_json(path, payload):
    tmp = path + ".tmp"
    with gzip.open(tmp, "wt", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, sort_keys=True)
    os.replace(tmp, path)


def read_json(path):
    opener = gzip.open if path.endswith(".gz") else open
    with opener(path, "rt", encoding="utf-8") as handle:
        return json.load(handle)


def write_jsonl(path, records):
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as handle:
        for record in records:
            handle.write(json.dumps(record, ensure_ascii=False, sort_keys=True) + "\n")
    os.replace(tmp, path)


def salt_for(subreddit):
    return hashlib.sha256(f"REDDIT:{subreddit}".encode()).hexdigest()


def pseudo(salt, raw):
    """A stable, join-preserving pseudonym for one author within one subreddit."""
    if raw in (None, "", "[deleted]", "[removed]"):
        return None
    return hmac.new(salt.encode(), f"author:{raw}".encode(), hashlib.sha256).hexdigest()[:16]


def epoch(value):
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return None


def parse_page(payload, kind, subreddit, source_id, captured):
    """One API page as (records, oldest_created_utc). The records keep text and linkage."""
    salt = salt_for(subreddit)
    rows = payload.get("data") or []
    comments, submissions = [], []
    oldest = None
    for row in rows:
        created = epoch(row.get("created_utc"))
        if created is not None and (oldest is None or created < oldest):
            oldest = created
        base = {
            "source_id": source_id,
            "captured_at": captured,
            "parser_version": PARSER_VERSION,
            "subreddit": subreddit,
            "reddit_id": row.get("id"),
            "author": pseudo(salt, row.get("author")),
            "created_utc": created,
            "score": row.get("score"),
            "permalink": row.get("permalink"),
        }
        if kind == "comment":
            base.update({
                "type": "comment",
                "body": row.get("body") or "",
                "link_id": row.get("link_id"),          # t3_<submission>
                "parent_id": row.get("parent_id"),      # t3_... (top level) or t1_<comment>
                "thread_title": row.get("link_title") or "",
                "thread_permalink": row.get("link_permalink") or "",
                "controversiality": row.get("controversiality"),
            })
            comments.append(base)
        else:
            base.update({
                "type": "submission",
                "title": row.get("title") or "",
                "selftext": row.get("selftext") or "",
                "num_comments": row.get("num_comments"),
                "url": row.get("url"),
                "flair": row.get("link_flair_text"),
            })
            submissions.append(base)
    return comments + submissions, oldest


def thread_rows(comments, submissions):
    """Connect comments to the thread they answer, and roll comment counts up per submission."""
    counts, titles = {}, {}
    for comment in comments:
        link = (comment.get("link_id") or "").removeprefix("t3_")
        if link:
            counts[link] = counts.get(link, 0) + 1
        if comment.get("thread_title"):
            titles[link] = comment["thread_title"]
    rows = []
    for submission in submissions:
        sid = submission["reddit_id"]
        rows.append({
            "source_id": submission["source_id"],
            "type": "thread",
            "subreddit": submission["subreddit"],
            "reddit_id": sid,
            "title": submission["title"],
            "author": submission["author"],
            "created_utc": submission["created_utc"],
            "num_comments": submission["num_comments"],
            "captured_at": submission["captured_at"],
            "parser_version": PARSER_VERSION,
        })
    for sid, title in titles.items():
        if not any(row["reddit_id"] == sid for row in rows):
            rows.append({"source_id": comments[0]["source_id"], "type": "thread", "subreddit": comments[0]["subreddit"],
                         "reddit_id": sid, "title": title, "author": None, "created_utc": None,
                         "num_comments": counts.get(sid, 0), "captured_at": comments[0]["captured_at"],
                         "parser_version": PARSER_VERSION})
    return rows


def normalize(raw_root, out_root, subreddit, kind, captured):
    """Every saved raw page for one source, parsed and written as normalized JSONL.

    Pages from BOTH mirrors are read, not just the one being crawled: the normalized file is
    rebuilt from the cache on every run, so reading a single source would silently drop everything
    the other one had already contributed.
    """
    source_id = f"REDDIT-{subreddit.upper()}"
    comments, submissions = [], []
    marker = f"{kind}-{subreddit}-"    # both `arctic-comment-ogame-…` and legacy `comment-ogame-…`
    for name in sorted(f for f in os.listdir(raw_root) if marker in f and not f.endswith(".tmp")):
        records, _ = parse_page(read_json(os.path.join(raw_root, name)), kind, subreddit, source_id, captured)
        for record in records:
            (comments if record["type"] == "comment" else submissions).append(record)
    normalized = os.path.join(out_root, "normalized")
    os.makedirs(normalized, exist_ok=True)
    counts = {}
    if comments or kind == "comment":
        write_jsonl(os.path.join(normalized, "comment.jsonl"), comments)
        counts["comment"] = len(comments)
    if submissions or kind == "submission":
        write_jsonl(os.path.join(normalized, "submission.jsonl"), submissions)
        counts["submission"] = len(submissions)
    threads = thread_rows(comments, submissions)
    write_jsonl(os.path.join(normalized, "thread.jsonl"), threads)
    counts["thread"] = len(threads)
    return counts


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--subreddit", default="ogame", help="subreddit name (default: ogame)")
    parser.add_argument("--kind", default="comment,submission", help="comment, submission or both (default: both)")
    parser.add_argument("--source", default=DEFAULT_SOURCE, choices=sorted(SOURCES),
                        help=f"archive mirror (default: {DEFAULT_SOURCE})")
    parser.add_argument("--out", default="storage/rl/external", help="output root (default: storage/rl/external)")
    parser.add_argument("--max-pages", type=int, default=50, help="pages per kind (default 50; 0 = until exhausted)")
    parser.add_argument("--delay", type=float, default=6.0, help="seconds between requests (default 6.0; PullPush is strict)")
    parser.add_argument("--self-check", action="store_true")
    args = parser.parse_args(argv)

    if args.self_check:
        self_check()
        return 0

    kinds = [k.strip() for k in args.kind.split(",") if k.strip() in KINDS]
    if not kinds:
        parser.error(f"--kind must be one of {sorted(KINDS)}")

    captured = now()
    for kind in kinds:
        source_id = f"REDDIT-{args.subreddit.upper()}"
        out_root = os.path.join(args.out, "sources", "REDDIT", args.subreddit)
        raw_root = os.path.join(out_root, "raw")
        os.makedirs(raw_root, exist_ok=True)
        cursor, page, fetched = None, 0, 0
        while not args.max_pages or page < args.max_pages:
            url = search_url(kind, args.subreddit, PAGE_SIZE, cursor, args.source)
            # Keyed by source too: two mirrors page differently, so "page 3" of one is not "page 3"
            # of the other and sharing the cache between them would splice two sequences together.
            page_path = os.path.join(raw_root, f"{args.source}-{kind}-{args.subreddit}-{page + 1:05d}.json.gz")
            if os.path.exists(page_path):
                payload = read_json(page_path)
            else:
                try:
                    payload = json.loads(get(url))
                except RuntimeError as error:
                    # PullPush throttling must not lose the run: keep what we have and resume later.
                    print(f"REDDIT {args.subreddit} {kind}: stopping at page {page + 1}: {error}", flush=True)
                    break
                save_json(page_path, payload)
                time.sleep(args.delay)
            records, oldest = parse_page(payload, kind, args.subreddit, source_id, captured)
            if not records:
                break
            fetched += len(records)
            page += 1
            print(f"REDDIT {args.subreddit} {kind}: page {page}, {len(records)} record(s), oldest={oldest}", flush=True)
            # `before` is exclusive; a page that does not move the cursor would loop forever.
            if oldest is None or (cursor is not None and oldest >= cursor):
                break
            cursor = oldest
        counts = normalize(raw_root, out_root, args.subreddit, kind, captured)
        write_manifest(out_root, {"source_id": source_id, "subreddit": args.subreddit, "kind": kind,
                                  "source": args.source,
                                  "access": f"public Reddit archive ({args.source})", "captured_at": captured,
                                  "parser_version": PARSER_VERSION, "pseudonymized": True,
                                  "pages": page, "records": fetched, "record_counts": counts}, kind)
        print(f"REDDIT {args.subreddit} {kind}: {fetched} record(s) over {page} page(s); {counts}")
    return 0


def write_manifest(out_dir, manifest, kind):
    path = os.path.join(out_dir, f"manifest-{kind}.json")
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(manifest, handle, indent=2, sort_keys=True)


# --self-check: a real parse of a tiny two-item page. The live path uses the same functions.
SUBMISSION = {"data": [{"id": "abc123", "author": "raider_x", "created_utc": 1700000000, "score": 7,
                        "title": "Best defence ratio?", "selftext": "Fodder or not?",
                        "num_comments": 2, "permalink": "/r/OGame/comments/abc123/best_defence_ratio/",
                        "url": "https://reddit.com/x", "link_flair_text": "Question", "subreddit": "OGame"}],
              "metadata": {}, "error": None}
COMMENT = {"data": [
    {"id": "c1", "author": "miner_joe", "created_utc": 1700000100, "score": 3, "body": "Fodder, always.",
     "link_id": "t3_abc123", "parent_id": "t3_abc123", "link_title": "Best defence ratio?",
     "link_permalink": "https://reddit.com/r/OGame/comments/abc123/", "controversiality": 0, "subreddit": "OGame"},
    {"id": "c2", "author": "[deleted]", "created_utc": 1700000200, "score": -1, "body": "no",
     "link_id": "t3_abc123", "parent_id": "t1_c1", "link_title": "Best defence ratio?",
     "link_permalink": "https://reddit.com/r/OGame/comments/abc123/", "controversiality": 1, "subreddit": "OGame"}],
    "metadata": {}, "error": None}


def self_check():
    captured = "2026-10-10T00:00:00Z"
    assert search_url("comment", "ogame", 100, None, "arctic") == (
        "https://arctic-shift.photon-reddit.com/api/comments/search?subreddit=ogame&limit=100&sort=desc")
    assert "before=1700000000" in search_url("submission", "ogame", 100, 1700000000, "arctic")
    assert "sort_type=created" in search_url("comment", "ogame", 100, None, "pullpush")
    subs, _ = parse_page(SUBMISSION, "submission", "ogame", "REDDIT-OGAME", captured)
    assert subs[0]["title"] == "Best defence ratio?" and subs[0]["num_comments"] == 2
    assert subs[0]["author"] is not None and "raider_x" not in json.dumps(subs)
    comments, oldest = parse_page(COMMENT, "comment", "ogame", "REDDIT-OGAME", captured)
    assert oldest == 1700000100, "the cursor is the oldest created_utc on the page"
    assert comments[0]["link_id"] == "t3_abc123" and comments[0]["parent_id"] == "t3_abc123"
    assert comments[1]["parent_id"] == "t1_c1", "a reply keeps its parent comment id"
    assert comments[1]["author"] is None, "[deleted] has no author to pseudonymize"
    assert comments[0]["author"] == pseudo(salt_for("ogame"), "miner_joe"), "the pseudonym is stable"
    # The thread rollup links comment counts back to the submission they answer.
    threads = thread_rows(comments, subs)
    assert [t["reddit_id"] for t in threads] == ["abc123"] and threads[0]["num_comments"] == 2
    print("self-check ok (page parse, cursor, thread linkage, stable pseudonyms)")


if __name__ == "__main__":
    sys.exit(main())
