"""The extractors, imported from the tested stdlib collectors by path.

The Scrapy spiders own fetching, scheduling, throttling, retrying and caching; extraction is
already written and self-checked in `scripts/ogame-*.py`. Loading those modules keeps ONE parser
for both engines instead of a second copy that drifts.
"""

import importlib.util
from pathlib import Path

SCRIPTS = Path(__file__).resolve().parents[2] / "scripts"


def _load(name, filename):
    spec = importlib.util.spec_from_file_location(name, SCRIPTS / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


forum = _load("ogame_forum_collect", "ogame-forum-collect.py")
reddit = _load("ogame_reddit_collect", "ogame-reddit-collect.py")
archive = _load("ogame_archive_collect", "ogame-archive-collect.py")
