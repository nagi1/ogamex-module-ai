#!/usr/bin/env python3
"""Build a validated pool of free public proxies for the corpus crawlers.

Why this exists: the crawl is limited by per-client rate limits (PullPush answers 429 to our IP,
some boards throttle a single client), not by anything a login would fix. Spreading the requests
over a pool of proxies means no single client hits the limit.

Three steps:
  1. download the published free-proxy lists (well-known GitHub mirrors);
  2. health-check every candidate concurrently against a canary URL and keep only those that answer
     with the expected marker - which is also what filters out the ones that inject a captive-portal
     or ad page instead of the real content;
  3. write the survivors to the file the crawler reads (`OGAMEX_PROXY_FILE`), one per line.

Usage:
    scripts/../proxy-pool.py                       # build and validate the pool
    python3 scrapers/proxy-pool.py --check-only    # re-validate an existing pool file
    python3 scrapers/proxy-pool.py --self-check

Say it plainly, because it matters: a proxy you do not control sees - and can change - everything
that passes through it, and these are strangers' machines. The corpus is public, unauthenticated
pages, so the exposure is bounded, but treat a sudden content surprise as a poisoned proxy rather
than a real page. For sustained collection, a paid rotating provider is the right answer.
"""

import argparse
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed

UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36"
DEFAULT_OUT = "storage/rl/external/proxies.txt"

# Published free lists. GitHub raw is used because it is a plain text file (no HTML to scrape).
SOURCES = (
    "https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/http.txt",
    "https://raw.githubusercontent.com/monosans/proxy-list/main/proxies/http.txt",
    "https://raw.githubusercontent.com/clarketm/proxy-list/master/proxy-list-raw.txt",
    "https://raw.githubusercontent.com/ShiftyTR/Proxy-List/master/http.txt",
    "https://raw.githubusercontent.com/proxifly/free-proxy-list/main/proxies/protocols/http/data.txt",
    "https://raw.githubusercontent.com/roosterkid/openproxylist/main/HTTPS_RAW.txt",
    "https://raw.githubusercontent.com/jetkai/proxy-list/main/online-proxies/txt/proxies-http.txt",
    "https://www.proxy-list.download/api/v1/get?type=http",
    "https://api.proxyscrape.com/v2/?request=displayproxies&protocol=http&timeout=10000&country=all",
)

# The canary is the host that is actually throttling us, so a proxy that passes is one that is
# immediately useful rather than merely alive. (A neutral canary measures worse than this: services
# like ipify block the datacentre IPs most free proxies sit on, so it under-reports usable ones.)
CANARY = "https://api.pullpush.io/reddit/search/submission/?subreddit=ogame&size=1"
MARKER = '"data"'


def get(url, proxy=None, timeout=10):
    handlers = [urllib.request.ProxyHandler({"http": proxy, "https": proxy})] if proxy else []
    opener = urllib.request.build_opener(*handlers)
    request = urllib.request.Request(url, headers={"User-Agent": UA})
    with opener.open(request, timeout=timeout) as response:
        return response.read().decode("utf-8", "replace")


def normalise(raw):
    """`1.2.3.4:8080`, `http://1.2.3.4:8080`, `user:pass@1.2.3.4:8080` -> `1.2.3.4:8080`."""
    entry = raw.strip()
    if not entry or entry.startswith("#"):
        return None
    entry = entry.split("//")[-1].strip()
    host = entry.split("@")[-1]
    if ":" not in host or host.count(":") != 1:
        return None
    address, port = host.split(":")
    if not port.isdigit() or not 1 <= int(port) <= 65535:
        return None
    if not address.replace(".", "").isdigit():        # IPv4 only: these lists are, and it keeps it simple
        return None
    return f"{address}:{port}"


def fetch_candidates():
    candidates = set()
    for source in SOURCES:
        try:
            text = get(source, timeout=30)
        except (urllib.error.URLError, TimeoutError, OSError) as error:
            print(f"  source failed: {source.split('/')[4] if '/' in source else source}: {error}", file=sys.stderr, flush=True)
            continue
        found = {normalise(line) for line in text.splitlines()}
        found.discard(None)
        print(f"  {len(found):>5} from {source.rsplit('/', 1)[-1][:40]}", flush=True)
        candidates |= found
    return sorted(candidates)


def works(proxy, canary, marker, timeout):
    """A proxy only counts if the canary answers with the expected content through it."""
    try:
        body = get(canary, proxy=f"http://{proxy}", timeout=timeout)
    except (urllib.error.URLError, TimeoutError, OSError, ValueError):
        return proxy, False
    return proxy, marker in body


def validate(candidates, canary, marker, timeout, workers):
    alive = []
    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = [pool.submit(works, proxy, canary, marker, timeout) for proxy in candidates]
        for done, future in enumerate(as_completed(futures), 1):
            proxy, ok = future.result()
            if ok:
                alive.append(proxy)
            if done % 200 == 0:
                print(f"  checked {done}/{len(candidates)}, {len(alive)} alive", flush=True)
    return sorted(alive)


def read_pool(path):
    if not os.path.exists(path):
        return []
    with open(path, encoding="utf-8") as handle:
        return [line.strip() for line in handle if line.strip() and not line.startswith("#")]


def write_pool(path, proxies):
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    with open(path, "w", encoding="utf-8") as handle:
        handle.write("# Validated free proxies for the OGame corpus crawlers.\n")
        handle.write("# Built by scrapers/proxy-pool.py; re-run to refresh - free proxies rot fast.\n")
        handle.write("# Untrusted machines: use for public pages only, never for anything authenticated.\n")
        for proxy in proxies:
            handle.write(proxy + "\n")


def self_check():
    assert normalise("1.2.3.4:8080") == "1.2.3.4:8080"
    assert normalise("http://1.2.3.4:8080") == "1.2.3.4:8080"
    assert normalise("user:pass@1.2.3.4:8080") == "1.2.3.4:8080"
    assert normalise("socks5://1.2.3.4:1080") == "1.2.3.4:1080"
    assert normalise("# comment") is None
    assert normalise("1.2.3.4") is None
    assert normalise("1.2.3.4:http") is None
    assert normalise("2001:db8::1:8080") is None, "IPv6 is out of scope for these lists"
    assert normalise("") is None
    print("self-check ok (proxy normalisation: schemes, credentials, ports, junk)")


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--out", default=DEFAULT_OUT, help=f"pool file to write (default {DEFAULT_OUT})")
    parser.add_argument("--canary", default=CANARY, help="URL every candidate must reach")
    parser.add_argument("--marker", default=MARKER, help="text that must appear in the canary's answer")
    parser.add_argument("--timeout", type=float, default=10.0, help="per-proxy timeout (default 10s)")
    parser.add_argument("--workers", type=int, default=120, help="concurrent checks (default 120)")
    parser.add_argument("--check-only", action="store_true", help="re-validate the existing pool file")
    parser.add_argument("--self-check", action="store_true")
    args = parser.parse_args(argv)

    if args.self_check:
        self_check()
        return 0

    candidates = read_pool(args.out) if args.check_only else fetch_candidates()
    print(f"{len(candidates)} candidate(s) to check", flush=True)
    if not candidates:
        print("no candidates - the list sources produced nothing", file=sys.stderr)
        return 1
    alive = validate(candidates, args.canary, args.marker, args.timeout, args.workers)
    write_pool(args.out, alive)
    print(f"{len(alive)} of {len(candidates)} proxies answered the canary -> {args.out}")
    print("use it with:  OGAMEX_PROXY_FILE=%s bash Modules/AI/scripts/collect-social-corpus.sh" % args.out)
    return 0 if alive else 1


if __name__ == "__main__":
    sys.exit(main())
