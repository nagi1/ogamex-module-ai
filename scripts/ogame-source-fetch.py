#!/usr/bin/env python3
"""Fetch OGame research sources into a staging directory.

Why this exists: MediaWiki wikis (ogame.fandom.com) answer plain page GETs and
`?action=raw` with a Cloudflare 403, while their own `api.php` serves the same
wikitext unblocked. Everything else is a plain GET with a browser user agent and
is saved verbatim.

This tool only FETCHES. Extraction of rules/numbers and the provenance block are
judgement work and belong to the `ogame-source-ingest` skill.

Usage:
    scripts/ogame-source-fetch.py <url> [<url> ...] [--out DIR] [--print] [--links]
    scripts/ogame-source-fetch.py --category Strategy [--host ogame.fandom.com]
    scripts/ogame-source-fetch.py --inventory [--host ogame.fandom.com]
    scripts/ogame-source-fetch.py --self-check
"""

import argparse
import json
import os
import re
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request

UA = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36"
)
WIKILINK = re.compile(r"\[\[([^\]|#]+)")


def fetch(url):
    request = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(request, timeout=30) as response:
        return response.read().decode("utf-8", "replace")


def wiki_page(url):
    """Return (host, title) for a MediaWiki page URL, else None."""
    parsed = urllib.parse.urlparse(url)
    if "/wiki/" not in parsed.path:
        return None
    title = parsed.path.split("/wiki/", 1)[1]
    return parsed.netloc, urllib.parse.unquote(title)


def wikitext(host, title):
    api = (
        f"https://{host}/api.php?action=parse&page={urllib.parse.quote(title)}"
        "&prop=wikitext&format=json&formatversion=2"
    )
    data = json.loads(fetch(api))
    if "parse" not in data:
        # A red link or a missing title: the API answers with an error object, not an error status.
        raise LookupError(data.get("error", {}).get("info", "page not available"))
    return data["parse"]["wikitext"]


def wikilinks(text):
    """Page titles linked from a wikitext body, in order, deduplicated."""
    seen, out = set(), []
    for target in WIKILINK.findall(text):
        title = target.strip().replace("_", " ")
        # A colon always means a namespace or interlanguage prefix, never prose.
        if not title or ":" in title or title in seen:
            continue
        seen.add(title)
        out.append(title)
    return out


def category_members(host, name):
    """Every page in a wiki category, following API continuation.

    A category is the wiki's own complete classification of a topic, unlike a
    hand-maintained hub page, and it is not reachable with --links because
    `action=parse` refuses special pages such as Special:AllPages.
    """
    url = (
        f"https://{host}/api.php?action=query&list=categorymembers"
        f"&cmtitle=Category:{urllib.parse.quote(name)}&cmlimit=500"
        "&format=json&formatversion=2"
    )
    titles, params = [], {}
    while True:
        query = url + "".join(
            f"&{key}={value}" for key, value in params.items() if value != "-||"
        )
        data = json.loads(fetch(query))
        titles += [member["title"] for member in data["query"]["categorymembers"]]
        params = data.get("continue")
        if not params:
            return titles


def inventory(host):
    """Every content page as (title, is_redirect, categories).

    Sifting a whole wiki is affordable only because this needs no page bodies:
    categories classify the pages, and the redirect flag keeps ~half of them out.
    """
    url = (
        f"https://{host}/api.php?action=query&generator=allpages&gapnamespace=0"
        "&gaplimit=500&prop=categories|info&cllimit=500&format=json&formatversion=2"
    )
    rows, params = [], {}
    while True:
        query = url + "".join(
            f"&{key}={value}" for key, value in params.items() if value != "-||"
        )
        data = json.loads(fetch(query))
        for page in data["query"]["pages"]:
            cats = "|".join(
                category["title"].removeprefix("Category:")
                for category in page.get("categories", [])
            )
            rows.append((page["title"], bool(page.get("redirect")), cats))
        params = data.get("continue")
        if not params:
            return rows


def safe_name(title):
    return re.sub(r"[^A-Za-z0-9._-]+", "_", title).strip("_") or "page"


def self_check():
    assert wiki_page("https://ogame.fandom.com/wiki/Strategy") == (
        "ogame.fandom.com",
        "Strategy",
    )
    assert wiki_page("https://ogame.fandom.com/wiki/Strategy?action=raw") == (
        "ogame.fandom.com",
        "Strategy",
    )
    assert wiki_page("https://gameforge.com/en-GB/games/ogame-raider-guide.html") is None
    assert wikilinks("a [[Ninja]] b [[Turtle|t]] [[Category:X]] [[Ninja]]") == [
        "Ninja",
        "Turtle",
    ]
    assert wikilinks("[[Fleetsaving]] [[tr:Strateji]]") == ["Fleetsaving"]
    assert safe_name("Defense Build Strategies") == "Defense_Build_Strategies"
    print("self-check ok")


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("urls", nargs="*")
    parser.add_argument("--out", help="staging directory (default: a temp dir)")
    parser.add_argument("--print", action="store_true", dest="print_body")
    parser.add_argument("--links", action="store_true", help="list page links too")
    parser.add_argument(
        "--category", action="append", default=[], metavar="NAME",
        help="list every page in a wiki category instead of fetching (repeatable)",
    )
    parser.add_argument(
        "--inventory", action="store_true",
        help="list every content page with its redirect flag and categories (TSV)",
    )
    parser.add_argument("--host", default="ogame.fandom.com", help="wiki host for --category/--inventory")
    parser.add_argument("--self-check", action="store_true")
    args = parser.parse_args(argv)

    if args.self_check:
        self_check()
        return 0
    if args.inventory:
        for title, is_redirect, cats in inventory(args.host):
            print(f"{title}\t{'redirect' if is_redirect else 'article'}\t{cats}")
        return 0
    if args.category:
        for name in args.category:
            print(f"# Category:{name} @ {args.host}")
            for title in category_members(args.host, name):
                print(title)
        return 0
    if not args.urls:
        parser.error("give at least one URL (or --category/--self-check)")

    out_dir = args.out or tempfile.mkdtemp(prefix="ogame-sources-")
    os.makedirs(out_dir, exist_ok=True)

    failures = 0
    for url in args.urls:
        page = wiki_page(url)
        try:
            if page is None:
                body, name = fetch(url), safe_name(urllib.parse.urlparse(url).path)
            else:
                name = safe_name(page[1])
                body = wikitext(*page)
        except (LookupError, urllib.error.URLError) as error:
            # One bad title in a scraped list must not lose the rest of the batch.
            failures += 1
            print(f"FAILED\t{url}\t{error}")
            continue
        path = os.path.join(out_dir, f"{name}.txt")
        with open(path, "w", encoding="utf-8") as handle:
            handle.write(body)
        print(f"{path}\t{len(body)} bytes")
        if args.links and body:
            for title in wikilinks(body):
                print(f"  link\t{title}")
        if args.print_body:
            print(body)

    print(f"staging dir: {out_dir}")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
