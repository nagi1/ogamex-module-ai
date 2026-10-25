"""Rotate the client's identity, because a fixed one is what gets rate-limited.

Two rotations, both driven per request so a *retry* comes back as somebody else:

  * **user agent** - a pool of real desktop browser strings, cycled (never the same twice running),
    with a matching `Accept-Language`;
  * **proxy** - a pool of `host:port` (or `user:pass@host:port`) entries, used round-robin. A proxy
    that answers 403/407/429/5xx or fails at the socket is parked for `PROXY_COOLDOWN` seconds and
    the request is retried through the next one. If every proxy is parked the one that frees up
    soonest is used, so the crawl slows rather than stops.

Where the pool comes from (either, or both):

    OGAMEX_PROXIES="host:port,user:pass@host:port,..."
    OGAMEX_PROXY_FILE=/path/to/proxies.txt      # one per line, # comments allowed

With no pool configured the middleware is a no-op beyond the user-agent rotation, so the default
crawl behaves exactly as before.

Scope: this rotates identity for PUBLIC, unauthenticated pages to spread load and stay under
per-client rate limits - it is not an access-control bypass. Nothing here logs in, uses cookies, or
reaches anything a browser could not. Note that a proxy you do not control can see the traffic, so
the pool should be one you trust (a paid rotating provider, or your own).
"""

import itertools
import os
import time

import scrapy
from scrapy import signals

USER_AGENTS = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.2 Safari/605.1.15",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0",
    "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:139.0) Gecko/20100101 Firefox/139.0",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36",
)
LANGUAGES = ("en-US,en;q=0.9", "en-GB,en;q=0.8", "de-DE,de;q=0.9,en;q=0.6", "en-US,en;q=0.9,de;q=0.5")
BLOCKED = {403, 407, 429, 500, 502, 503, 504}
FIELDS = ("HTTP_PROXY", "HTTPS_PROXY", "http_proxy", "https_proxy")


def split_pool(raw):
    """A comma/space/newline separated proxy string as a list."""
    return [part.strip() for chunk in raw.splitlines() for part in chunk.replace(",", " ").split() if part.strip()]


class RotateIdentityMiddleware:
    def __init__(self, proxies, cooldown):
        self.agents = itertools.cycle(USER_AGENTS)
        self.languages = itertools.cycle(LANGUAGES)
        self.proxies = proxies
        self.rotation = itertools.cycle(proxies) if proxies else None
        self.cooldown = cooldown
        self.parked = {}
        self.served = {}
        self.parks = 0

    @classmethod
    def from_crawler(cls, crawler):
        proxies = split_pool(os.environ.get("OGAMEX_PROXIES", ""))
        pool_file = os.environ.get("OGAMEX_PROXY_FILE")
        if pool_file and os.path.exists(pool_file):
            with open(pool_file, encoding="utf-8") as handle:
                proxies += split_pool("".join(line for line in handle if not line.lstrip().startswith("#")))
        if not proxies:                                   # honour a single proxy in the environment
            proxies = [os.environ[f] for f in FIELDS if os.environ.get(f)]
        middleware = cls(proxies, crawler.settings.getfloat("PROXY_COOLDOWN", 300.0))
        crawler.signals.connect(middleware.spider_closed, signal=signals.spider_closed)
        crawler.signals.connect(middleware.spider_opened, signal=signals.spider_opened)
        return middleware

    def spider_opened(self, spider):
        if self.proxies:
            spider.logger.info("identity rotation: %s proxies + %s user agents", len(self.proxies), len(USER_AGENTS))
            return
        spider.logger.info("identity rotation: user agents only (set OGAMEX_PROXIES to rotate proxies)")

    def spider_closed(self, spider):
        if self.proxies:
            spider.logger.info("proxy uses: %s | parks: %s", self.served, self.parks)

    def next_proxy(self):
        if not self.rotation:
            return None
        now = time.monotonic()
        for _ in range(len(self.proxies)):
            proxy = next(self.rotation)
            if self.parked.get(proxy, 0.0) <= now:
                self.served[proxy] = self.served.get(proxy, 0) + 1
                return proxy
        proxy = min(self.parked, key=self.parked.get)      # everything parked: take the first to free
        self.served[proxy] = self.served.get(proxy, 0) + 1
        return proxy

    def park(self, proxy, spider):
        self.parked[proxy] = time.monotonic() + self.cooldown
        self.parks += 1
        spider.logger.info("parking proxy %s for %ss", proxy.split("@")[-1], int(self.cooldown))

    def process_request(self, request, spider):
        # Reassigned on every request, including a retry, so a retried request leaves through a
        # different proxy and a different user agent than the one that was blocked.
        request.headers["User-Agent"] = next(self.agents)
        request.headers["Accept-Language"] = next(self.languages)
        request.headers.setdefault("Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8")
        request.headers.setdefault("Upgrade-Insecure-Requests", "1")
        proxy = self.next_proxy()
        if proxy:
            request.meta["proxy"] = proxy

    def process_response(self, request, response, spider):
        proxy = request.meta.get("proxy")
        if proxy and response.status in BLOCKED:
            self.park(proxy, spider)
        return response

    def process_exception(self, request, exception, spider):
        proxy = request.meta.get("proxy")
        if proxy:
            self.park(proxy, spider)
        return None
