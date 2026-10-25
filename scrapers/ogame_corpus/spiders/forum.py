"""Crawl a Gameforge board's alliance / diplomacy / community / archive sections.

Selection is streaming. Every board page is visited because alliance and diplomacy boards are
nested inside universe boards, so a name filter on the root page would miss them; thread pages
are only enqueued for boards whose own name matches the multilingual section filter. That is the
same rule the stdlib collector applies, but it starts fetching threads immediately instead of
walking the whole tree first, and Scrapy schedules the thousands of requests across the
community's download slot with AutoThrottle deciding how hard to push.

Parsing reuses `scripts/ogame-forum-collect.py`, so there is one WoltLab parser for both engines.
"""

import re

import scrapy

from ogame_corpus import parsers
from ogame_corpus.settings import CORPUS

FORUM = parsers.forum


class ForumSpider(scrapy.Spider):
    name = "forum"

    def __init__(self, host="board.en.ogame.gameforge.com", match=None, boards=None,
                 thread_match=None, max_board_pages=6, max_threads=300, max_thread_pages=6,
                 keep_authors=False, **kwargs):
        super().__init__(**kwargs)
        self.host = host
        self.base = FORUM.HOSTS[host]
        self.salt = FORUM.salt_for(host)
        self.captured = FORUM.now()
        self.section = re.compile(match or FORUM.DEFAULT_SECTIONS, re.I)
        self.forced = {b.strip() for b in (boards or "").split(",") if b.strip()}
        self.title_filter = re.compile(thread_match, re.I) if thread_match else None
        self.max_board_pages = int(max_board_pages)
        self.max_threads = int(max_threads)
        self.max_thread_pages = int(max_thread_pages)
        self.keep_authors = str(keep_authors).lower() in ("1", "true", "yes")
        self.seen_boards = set()
        self.threads_per_board = {}
        self.out_dir = str(CORPUS / "sources" / "FORUM" / host.replace(".", "_"))

    async def start(self):
        yield scrapy.Request(self.base, callback=self.parse_board_list)

    def parse_board_list(self, response):
        for board_id, (name, url) in FORUM.board_links(response.text, response.url).items():
            yield from self.visit(board_id, name, url)

    def visit(self, board_id, name, url):
        """Record a board and walk into it, so nested alliance boards are reached."""
        if board_id in self.seen_boards:
            return
        self.seen_boards.add(board_id)
        yield {"source_id": "FORUM", "type": "board", "host": self.host,
               "board_id": board_id, "name": name, "url": url}
        yield scrapy.Request(url, callback=self.parse_board,
                             meta={"board_id": board_id, "board_name": name})

    def parse_board(self, response):
        board_id = response.meta["board_id"]
        name = response.meta["board_name"]
        for sub_id, (sub_name, sub_url) in FORUM.board_links(response.text, response.url).items():
            yield from self.visit(sub_id, sub_name, sub_url)
        if board_id not in self.forced and not self.section.search(name):
            return
        yield from self.thread_list(response, {"board_id": board_id, "name": name}, page=1)

    def thread_list(self, response, board, page):
        """Thread pages are only enqueued for a board that was selected."""
        room = self.max_threads - self.threads_per_board.get(board["board_id"], 0)
        for thread_id, (title, url) in list(FORUM.thread_links(response.text, response.url).items())[:max(room, 0)]:
            if self.title_filter and not self.title_filter.search(title):
                continue
            self.threads_per_board[board["board_id"]] = self.threads_per_board.get(board["board_id"], 0) + 1
            thread = {"thread_id": thread_id, "title": title, "url": url}
            yield {"source_id": "FORUM", "type": "thread", "host": self.host,
                   "board_id": board["board_id"], "board_name": board["name"],
                   "thread_id": thread_id, "title": title, "url": url,
                   "captured_at": self.captured, "parser_version": FORUM.PARSER_VERSION}
            yield scrapy.Request(url, callback=self.parse_thread,
                                 meta={"board": board, "thread": thread, "page": 1})
        template, pages = FORUM.pagination(response.text)
        if template and page < pages and page < self.max_board_pages:
            yield scrapy.Request(template.replace("%d", str(page + 1)),
                                 callback=self.parse_thread_list,
                                 meta={"board": board, "page": page + 1})

    def parse_thread_list(self, response):
        yield from self.thread_list(response, response.meta["board"], response.meta["page"])

    def parse_thread(self, response):
        board = response.meta["board"]
        thread = response.meta["thread"]
        page = response.meta["page"]
        if page == 1:
            thread["title"] = FORUM.thread_title(response.text, thread["title"])
        yield from FORUM.parse_posts(response.text, thread, board, self.host, self.salt,
                                     self.captured, self.keep_authors)
        template, pages = FORUM.pagination(response.text)
        if template and page < pages and page < self.max_thread_pages:
            yield scrapy.Request(template.replace("%d", str(page + 1)), callback=self.parse_thread,
                                 meta={"board": board, "thread": thread, "page": page + 1})
