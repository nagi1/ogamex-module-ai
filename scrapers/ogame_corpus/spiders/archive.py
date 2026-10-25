"""Replay archived OGame board threads from the Wayback Machine.

One CDX query lists a host's archived thread URLs, then each capture is fetched as raw original
bytes (`.../<timestamp>id_/<original>`) and parsed into one record per page.

ROBOTSTXT_OBEY is off for this spider only: the CDX index endpoint and the `/web/` replay path
are the archive's documented programmatic interface, not a site to be crawled politely, and
obeying web.archive.org's robots.txt would refuse the very URLs the archive serves for this.
"""

import scrapy

from ogame_corpus import parsers
from ogame_corpus.settings import CORPUS

ARCHIVE = parsers.archive


class ArchiveSpider(scrapy.Spider):
    name = "archive"
    custom_settings = {"ROBOTSTXT_OBEY": False, "AUTOTHROTTLE_TARGET_CONCURRENCY": 1.0}

    def __init__(self, host="board.ogame.gameforge.com", cdx_limit=200, max_threads=200,
                 **kwargs):
        super().__init__(**kwargs)
        self.host = host
        self.cdx_limit = int(cdx_limit)
        self.max_threads = int(max_threads)
        self.captured = ARCHIVE.now()
        self.out_dir = str(CORPUS / "sources" / "ARCHIVE" / host.replace(".", "_"))

    async def start(self):
        yield scrapy.Request(ARCHIVE.cdx_url(self.host, self.cdx_limit),
                             callback=self.parse_index, headers={"Accept": "text/plain"})

    def parse_index(self, response):
        rows = ARCHIVE.parse_cdx(response.text)[:self.max_threads]
        self.logger.info("%s: %s archived thread(s) to replay", self.host, len(rows))
        for row in rows:
            yield scrapy.Request(ARCHIVE.replay_url(row["timestamp"], row["original"]),
                                 callback=self.parse_capture, meta={"row": row})

    def parse_capture(self, response):
        row = response.meta["row"]
        yield ARCHIVE.parse_page(response.text, self.host, row["timestamp"], row["original"],
                                 self.captured)
