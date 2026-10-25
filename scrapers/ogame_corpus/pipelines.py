"""Write every item to the JSONL file its type belongs to, under the spider's output dir.

Same layout and schema as the stdlib collectors (`FORUM/<host>/normalized/post.jsonl`), so
`scripts/social-corpus-stats.py` reads either engine's output and the two can hand a host back
and forth.

Writing is append-only and flushed per item, so a crawl that dies at hour five keeps everything
up to the last post. Appending is safe because the writer is idempotent: at start it loads the
identity keys already on disk and drops items it has seen, so re-running a crawl - or running
Scrapy over a host the stdlib collector already did - cannot double-write a post.
"""

import json
from pathlib import Path

# The fields that identify an item, in preference order. An item with none of them is written.
KEYS = {
    "post": [("host", "thread_id", "post_id")],
    "thread": [("host", "thread_id"), ("host", "original_url")],
    "board": [("host", "board_id")],
    "comment": [("source_id", "reddit_id")],
    "submission": [("source_id", "reddit_id")],
}


def identity(item):
    for fields in KEYS.get(item.get("type"), ()):
        values = tuple(item.get(field) for field in fields)
        if any(value is not None for value in values):
            return values
    return None


class CorpusWriter:
    def open_spider(self, spider):
        self.dir = Path(spider.out_dir) / "normalized"
        self.dir.mkdir(parents=True, exist_ok=True)
        self.handles = {}
        self.seen = {}
        self.duplicates = 0

    def _seen(self, kind):
        if kind not in self.seen:
            path = self.dir / f"{kind}.jsonl"
            keys = set()
            if path.exists():
                with path.open(encoding="utf-8") as handle:
                    for line in handle:
                        if line.strip():
                            key = identity(json.loads(line))
                            if key is not None:
                                keys.add(key)
            self.seen[kind] = keys
        return self.seen[kind]

    def process_item(self, item, spider):
        kind = item.get("type", "item")
        seen = self._seen(kind)
        key = identity(item)
        if key is not None:
            if key in seen:
                self.duplicates += 1
                return None
            seen.add(key)
        handle = self.handles.get(kind)
        if handle is None:
            handle = self.handles[kind] = (self.dir / f"{kind}.jsonl").open("a", encoding="utf-8")
        handle.write(json.dumps(item, ensure_ascii=False, sort_keys=True) + "\n")
        handle.flush()
        return item

    def close_spider(self, spider):
        for handle in self.handles.values():
            handle.close()
        if self.duplicates:
            spider.logger.info("skipped %s item(s) already on disk", self.duplicates)
