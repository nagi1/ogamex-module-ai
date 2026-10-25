#!/usr/bin/env python3
"""Read the social/diplomacy corpus back: counts, language spread and coverage, in one pass.

Why this exists: the collectors write large public corpora that stay out of git, and watching them
by opening the JSONL is expensive and noisy. This is the cheap read - bounded, machine-readable,
aggregated - so a run can be judged by its shape (which sources, which languages, how many rows,
what date range) without reading a single post into the terminal.

Usage:
    scripts/social-corpus-stats.py                      # summary table
    scripts/social-corpus-stats.py --json               # same numbers as JSON
    scripts/social-corpus-stats.py --root storage/rl/external/sources
    scripts/social-corpus-stats.py --self-check
"""

import argparse
import glob
import json
import os
import sys

# FORUM host -> the community's language, so the spread is visible at a glance.
LANGUAGE = {
    "board.en": "en", "board.us": "en", "forum.origin": "en",
    "board.de": "de", "board.fr": "fr", "board.it": "it", "board.tr": "tr",
    "board.ru": "ru", "board.hu": "hu", "board.ro": "ro",
    "forum.es": "es", "forum.pt": "pt", "forum.ba": "south-slavic",
}


def language_of(host):
    return LANGUAGE.get((host or "").split(".ogame")[0], "en")


def rows(path):
    with open(path, encoding="utf-8") as handle:
        for line in handle:
            if line.strip():
                yield json.loads(line)


def span(records, key="created_utc"):
    stamps = [r.get(key) for r in records if r.get(key)]
    return (min(stamps), max(stamps)) if stamps else (None, None)


def summarise(root):
    out = {"reddit": {}, "forum": {}, "archive": {}}

    for path in glob.glob(os.path.join(root, "REDDIT", "*", "normalized", "*.jsonl")):
        kind = os.path.basename(path).removesuffix(".jsonl")
        data = list(rows(path))
        subreddit = os.path.basename(os.path.dirname(os.path.dirname(path)))
        bucket = out["reddit"].setdefault(subreddit, {})
        bucket[kind] = len(data)
        if kind == "comment":
            bucket["distinct_authors"] = len({r.get("author") for r in data if r.get("author")})
            first, last = span(data)
            bucket["oldest"], bucket["newest"] = first, last
            bucket["chars"] = sum(len(r.get("body") or "") for r in data)

    for path in glob.glob(os.path.join(root, "FORUM", "*", "normalized", "*.jsonl")):
        kind = os.path.basename(path).removesuffix(".jsonl")
        for record in rows(path):
            host = record.get("host") or "?"
            bucket = out["forum"].setdefault(host, {"language": language_of(host)})
            bucket[kind] = bucket.get(kind, 0) + 1
            if kind == "post":
                bucket["chars"] = bucket.get("chars", 0) + len(record.get("text") or "")
                bucket["authors"] = bucket.get("authors", set())
                if record.get("author"):
                    bucket["authors"].add(record["author"])

    for path in glob.glob(os.path.join(root, "ARCHIVE", "*", "normalized", "thread.jsonl")):
        data = list(rows(path))
        host = os.path.basename(os.path.dirname(os.path.dirname(path)))
        out["archive"][host] = {"thread": len(data), "chars": sum(len(r.get("text") or "") for r in data),
                                "with_date": sum(1 for r in data if r.get("posted_at"))}

    for bucket in out["forum"].values():
        bucket["distinct_authors"] = len(bucket.pop("authors", set()))
        bucket["chars"] = bucket.get("chars", 0)
    return out


def render(summary):
    lines = []
    for subreddit, bucket in sorted(summary["reddit"].items()):
        lines.append(f"REDDIT r/{subreddit}: {bucket.get('comment', 0)} comments, "
                     f"{bucket.get('submission', 0)} submissions, {bucket.get('thread', 0)} threads, "
                     f"{bucket.get('distinct_authors', 0)} authors, {bucket.get('chars', 0):,} chars")
    lines.append("FORUM:")
    for host, bucket in sorted(summary["forum"].items(), key=lambda kv: -kv[1].get("post", 0)):
        lines.append(f"  {host:<38} [{bucket['language']:<11}] boards {bucket.get('board', 0):>5} "
                     f"threads {bucket.get('thread', 0):>6} posts {bucket.get('post', 0):>7} "
                     f"authors {bucket.get('distinct_authors', 0):>5} chars {bucket.get('chars', 0):>10,}")
    lines.append("ARCHIVE:")
    for host, bucket in sorted(summary["archive"].items()):
        lines.append(f"  {host:<38} threads {bucket['thread']:>5} dated {bucket['with_date']:>5} "
                     f"chars {bucket['chars']:>10,}")
    return "\n".join(lines)


def self_check():
    assert language_of("board.de.ogame.gameforge.com") == "de"
    assert language_of("forum.es.ogame.gameforge.com") == "es"
    assert language_of("") == "en"
    assert span([{"created_utc": 5}, {"created_utc": 9}, {}]) == (5, 9)
    print("self-check ok (host->language map, date span)")


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", default="storage/rl/external/sources", help="corpus root")
    parser.add_argument("--json", action="store_true", help="emit JSON instead of a table")
    parser.add_argument("--self-check", action="store_true")
    args = parser.parse_args(argv)

    if args.self_check:
        self_check()
        return 0
    if not os.path.isdir(args.root):
        print(f"no corpus at {args.root}", file=sys.stderr)
        return 1
    summary = summarise(args.root)
    print(json.dumps(summary, indent=2, default=str) if args.json else render(summary))
    return 0


if __name__ == "__main__":
    sys.exit(main())
