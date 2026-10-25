"""Page a public Reddit archive mirror backwards, one API page per request.

Defaults to Arctic Shift (`-a source=pullpush` for the older PullPush mirror); both answer the
same JSON shape and `scripts/ogame-reddit-collect.py` builds the URL for either. PullPush answers
429 to a whole client once it is busy, which is why the default moved - the spider is unchanged by
it, and RETRY_HTTP_CODES/AutoThrottle still cover a throttle when one happens.
"""

import scrapy

from ogame_corpus import parsers
from ogame_corpus.settings import CORPUS

REDDIT = parsers.reddit


class RedditSpider(scrapy.Spider):
    name = "reddit"

    def __init__(self, subreddit="ogame", kind="comment", source=REDDIT.DEFAULT_SOURCE,
                 max_records=0, **kwargs):
        super().__init__(**kwargs)
        self.subreddit = subreddit
        self.kind = kind
        self.source = source
        self.max_records = int(max_records)
        self.source_id = f"REDDIT-{subreddit.upper()}"
        self.captured = REDDIT.now()
        self.taken = 0
        self.out_dir = str(CORPUS / "sources" / "REDDIT" / subreddit)

    async def start(self):
        yield self.page(None)

    def page(self, cursor):
        url = REDDIT.search_url(self.kind, self.subreddit, REDDIT.PAGE_SIZE, cursor, self.source)
        # `identity` on purpose. Measured: this mirror answers 422 to `Accept-Encoding: gzip, deflate`
        # (a bug on its compression path) and 200 to `identity` for the identical URL. Since the walk
        # is a serial chain, one rejected page would end the rest of the archive, so compression is
        # simply not worth it here.
        return scrapy.Request(url, callback=self.parse_page, meta={"cursor": cursor},
                              headers={"Accept": "application/json", "Accept-Encoding": "identity"})

    def parse_page(self, response):
        try:
            payload = response.json()
        except ValueError:
            self.logger.warning("not JSON for %s (status %s)", response.url, response.status)
            return
        records, oldest = REDDIT.parse_page(payload, self.kind, self.subreddit, self.source_id,
                                            self.captured)
        if not records:
            self.logger.info("no more %s rows after %s", self.kind, response.meta["cursor"])
            return
        self.taken += len(records)
        yield from records
        cursor = response.meta["cursor"]
        if self.max_records and self.taken >= self.max_records:
            self.logger.info("record cap reached (%s)", self.taken)
            return
        # `before` is exclusive; a page that does not move the cursor would loop forever.
        if oldest is None or (cursor is not None and oldest >= cursor):
            self.logger.info("cursor stopped moving at %s", oldest)
            return
        yield self.page(oldest)
