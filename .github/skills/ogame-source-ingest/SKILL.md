---
name: ogame-source-ingest
description: Ingest one or more OGame strategy URLs into the durable source corpus.
---

Given URLs or a research query:

1. Read the existing source registry first and avoid duplicates.
2. Fetch each source with `scripts/ogame-source-fetch.py <url> [--out DIR] [--links]`.
   MediaWiki/Fandom pages answer plain GETs and `?action=raw` with a Cloudflare 403;
   their own `api.php?action=parse&prop=wikitext` serves the same text unblocked, so let
   the script handle those hosts. Give it a hub/index page with `--links` to get the list
   of pages to fetch next, and do not register a pure index page as evidence — it holds
   no numbers, only navigation.
3. Extract only strategy-relevant rules, numbers, ratios, thresholds and
   meaningful disagreement.
4. Preserve exact numerical values and short surrounding evidence.
5. Tag each claim CANONICAL, DOCUMENTED, MEASURED, CONTESTED, ANECDOTAL,
   or INFERENCE.
6. Save raw evidence under the correct site/topic path.
7. Add/update stable source IDs in `SOURCE-REGISTRY.yaml`.
8. Do not synthesize a universal rule yet.
9. Return source IDs added and unresolved questions only.
