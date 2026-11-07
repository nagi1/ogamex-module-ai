# External data collection report (the Extract of the ETL)

Date: 10 October 2026. Run by the collection harness `scripts/ogame-external-collect.py`.
Scope: the transcript catalogue `plan/ogame_ai_sources.csv` (127 rows). Plan context:
[external-data-etl-plan.md](external-data-etl-plan.md).

## The metered-connection guard (hard, fail-closed)

The collector is built for a metered link. It will not spend data it was not told it may spend.

- **Per-source cap** `--max-source-bytes` (default 2 GiB). A source larger than the cap is never
  started: a cheap preflight probe records it as `defer` and it stays on disk untouched.
- **Whole-run budget** `--max-total-bytes` (default 2 GiB). Once the budget is spent every further
  source is `skip: budget`, and a single oversized file inside a source is dropped rather than
  fetched (its part file is deleted).
- **Byte-level, not just preflight.** `stream_download` counts every 64 KiB chunk and aborts the
  instant either cap would be crossed, so a wrong `Content-Length` cannot overshoot. The cap holds
  even when the server lies.
- **Fail-closed on unknown size.** A bulk source whose size cannot be probed is `defer`, never
  guessed small. Only a single `doc` page (small by nature) is fetched while unknown, and still
  byte-capped.
- **Default action is `--probe`** (metadata only). Downloading requires `--download --yes`.
- **Resume.** A completed file is never re-fetched, so a re-run costs no metered data.
- **Probe cache.** Sizes are cached in `storage/rl/external/source-sizes.json`, so the rate-limited
  GitHub API (60 req/hour) and the metered link are hit once.

Proof of the guard (throwaway output, no real data touched):

| Test | Expected | Observed |
| --- | --- | --- |
| 32.5 GiB source | refuse | `defer (over 2.0GiB cap (32.5GiB))`, 0 bytes |
| 5.4 MiB source vs 100 KiB per-source cap | refuse | `defer (over 97.7KiB cap (5.4MiB))`, 0 bytes |
| 21 KiB source vs 5 KiB run budget | stop at the budget | wrote the 1.1 KiB that fit, dropped the rest |

`scripts/ogame-external-collect.py --self-check` asserts the same rules offline.

## Result

**58 sources fetched (~1.6 GiB over two runs; the final run used 0.99 GiB of the 2 GiB budget),
all normalized. 9 deferred (deal with later). 2 errors. 3 already held. 51 skipped (contact leads,
forums, model/tool pages - no dataset payload).**

On disk the corpus is **3.7 GiB** (raw payloads plus normalized JSONL) under
`storage/rl/external/sources/` (328 source directories: 267 `PUB-*`, `OSTATS`, `OGMEM`, `HFGUIDE`,
and the 58 new ones).

### Fetched, by category

| Category | Sources | Bytes | Representative |
| --- | --- | --- | --- |
| HuggingFace social / conversation | 11 | 583.0 MiB | werewolf, LLMafia, portal2, Discord-Dialogues, FIREBALL |
| Strategy-game data | 4 | 775.7 MiB | SC2-Dynamics-50K (765k rows), Catan-43k, Tribal Wars, Grepolis |
| OGame server source / engines / bots | 12 | 105.6 MiB | 2Moons, SteemNova, New-Star, oClassic, Arathi, OPBE, ogame-agi |
| Human diplomacy corpora | 16 | 47.2 MiB | STAC-Settlers, CL-Aff, Avalon-NLU, DeliData, CaSiNo |
| Other AI research / environments | 6 | 42.9 MiB | Cicero, DipNet, CivRealm, Overmind-RL, catan-ai |
| OGame stats / archives | 8 | 35.0 MiB | OStats collector, Ogniter, Wayback 2Moons/OStats indexes |
| Contact-lead page (collected as evidence) | 1 | 0.2 MiB | OStats developer page |

Per source (bytes / normalized rows) - code repos are prepared as a file inventory of the extracted
tree, so their "rows" column is the file count:

| Source | Category | Bytes | Rows |
| --- | --- | --- | --- |
| HF-yxzhang2024-sc2-dynamics-50k | strategy-game | 775.6 MiB | 765238 |
| HF-mookiezi-discord-dialogues | HF-social | 331.6 MiB | 6723 |
| HF-berkeley-nlp-portal2 | HF-social | 123.5 MiB | 352953 |
| GH-yaro2709-new-star | OGame-code | 68.7 MiB | 2270 |
| HF-bolinlai-werewolf-among-us | HF-social | 64.1 MiB | 2481 |
| HF-lara-martin-fireball | HF-social | 56.7 MiB | 6427 |
| GH-sorinmd-stacsettlers | diplomacy | 29.6 MiB | 755 |
| GH-bigai-ai-civrealm | AI-research | 28.9 MiB | 378 |
| GH-dlaynes-ogniter | OGame-data | 26.5 MiB | 1026 |
| GH-steemnova-steemnova | OGame-code | 15.6 MiB | 2136 |
| GH-kokiljaidka-claff-diplomacy | diplomacy | 6.9 MiB | 14 |
| GH-darthpinkpapa-oclassic | OGame-code | 6.7 MiB | 1773 |
| GH-sstepput-avalon-nlu | diplomacy | 6.6 MiB | 90 |
| GH-diplomacy-research | AI-research | 5.5 MiB | 888 |
| HF-niveck-llmafia | HF-social | 5.4 MiB | 43252 |
| GH-arathi-2moons | OGame-code | 5.7 MiB | 1466 |
| GH-jkroepke-2moons | OGame-code | 5.4 MiB | 1737 |
| GH-facebookresearch-diplomacy-cicero | AI-research | 2.8 MiB | 616 |
| GH-bencbartlett-overmind-rl | AI-research | 2.7 MiB | 67 |
| GH-supermopf-galaxytool | OGame-code | 1.8 MiB | 421 |
| GH-uec-inabalab-overcookedchat | diplomacy | 1.7 MiB | 116 |
| GH-facebookresearch-end-to-end-negotiator | diplomacy | 1.6 MiB | 42 |
| GH-cocochief4-llm-mafia | AI-research | 1.6 MiB | 4 |
| GH-8910work-cell-catan-ai | AI-research | 1.4 MiB | 27 |
| HF-kchawla123-casino | HF-social | 1.2 MiB | 324 |
| GH-jstar88-opbe | OGame-code | 605.4 KiB | 455 |
| DOC-wayback-ostats-homepage-snapshot | OGame-data | 594.0 KiB | 4574 |
| GH-zhudotexe-fireball | diplomacy | 478.0 KiB | 6 |
| HF-supergoose-flan-combined-task1590 | HF-social | 373.2 KiB | 86 |
| GH-theonlybeardedbeast-ogame-agent | OGame-code | 287.1 KiB | 172 |
| DOC-christian-kohlstedt-ostats-developer | contact-lead | 202.4 KiB | 887 |
| HF-lots-of-loras-task1590 | HF-social | 179.2 KiB | 118 |
| GH-7omate-ogame | OGame-code | 629.0 KiB | 30 |
| GH-shinigallo-ogame-agi | OGame-code | 86.2 KiB | 65 |
| DOC-wayback-all-ostats-captures | OGame-data | 141.8 KiB | 2406 |
| DOC-wayback-ostats-root-captures | OGame-data | 141.8 KiB | 2406 |
| DOC-new-shores-a-game-for-democracy | diplomacy | 113.3 KiB | 1252 |
| GH-chrko-ostats | OGame-data | 39.4 KiB | 71 |
| GH-balrok-og-api | OGame-code | 37.4 KiB | 28 |
| HF-stanfordnlp-mutual-friends | HF-social | 21.2 KiB | 468 |
| DOC-casino-original-corpus | diplomacy | 18.9 KiB | 295 |
| DOC-persuasionforgood | diplomacy | 18.4 KiB | 287 |
| DOC-gap-group-discussion | diplomacy | 18.8 KiB | 288 |
| DOC-conversations-gone-awry | diplomacy | 16.4 KiB | 234 |
| DOC-cornell-deception-in-diplomacy | diplomacy | 16.3 KiB | 245 |
| DOC-delidata-convokit | diplomacy | 16.8 KiB | 243 |
| DOC-conversations-gone-awry-cmv | diplomacy | 16.2 KiB | 232 |
| DOC-stac-catan-corpus | diplomacy | 12.0 KiB | 166 |
| DOC-minecraft-collaborative-dialogue | diplomacy | 8.3 KiB | 91 |
| DOC-wayback-2moons-cdx-index | OGame-data | 7.6 MiB | 28309 |
| HF-mikelewis0-deal-or-no-dialog | HF-social | 12.6 KiB | 322 |
| GH-eracle-ogamebot | OGame-code | 13.4 KiB | 13 |
| GH-riaevangelist-php-grepolis-json-api | strategy-game | 4.9 KiB | 16 |
| GH-orgadyron-catan-43k-games-dataset | strategy-game | 6.0 KiB | 3 |
| GH-freyhold-tribal-wars-api | strategy-game | 4.4 KiB | 1 |
| DOC-internet-archive-ogame-query | OGame-data | 1.8 KiB | 32 |
| DOC-wayback-exact-dump-cdx-index | OGame-data | 3.0 B | 1 |
| DOC-stac-catan-corpus | diplomacy | 12.0 KiB | 166 |

(The full, exact list is machine-readable in `storage/rl/external/catalog.csv`.)

## Deferred - deal with later (over 2 GiB, or size unprobeable)

These are untouched. Raise `--max-source-bytes` on a non-metered link, or fetch by hand.

| Source | Size | Reason |
| --- | --- | --- |
| HF-djmango-openfront-human-games | 32.5 GiB | over cap |
| HF-iohadrubin-werewolf-dialogue-data-10sec-v2 | 7.8 GiB | over cap |
| HF-kaszanas-sc2egset | 7.8 GiB | over cap |
| HF-saadi01-llm-werewolf-data | 3.8 GiB | over cap |
| HF-taso-labs-strategybench | 2.1 GiB | over cap (just above) |
| HF-openllm-france-lucie-training-dataset | unknown | nested/script repo; known to be very large |
| HF-negotiation-games-c2c-ai-vs-ai | unknown | no sized files at top level |
| HF-among-us-x-amongus-x | unknown | no sized files at top level |
| HF-shinjo-ogameguid | unknown | HTTP 401 (gated / not public) |

## Errors (recorded, batch survived)

| Source | Cause |
| --- | --- |
| DOC-stac-situated-corpus-page | TLS certificate hostname mismatch on the origin (textlink.ii.metu.edu.tr) |
| DOC-wayback-guessed-dump-capture-url | the guessed archive payload was never captured |

## Skipped (51)

50 rows are **contact leads, forum/discord pages and third-party documentation** - not datasets
(the `contact` kind). One (`CATALOG-minilm-sentence-encoder`) is a **model/tool page**, not a
dataset. Three are **already held** by earlier collectors and were not re-fetched: `OSTATS`,
`OGMEM`, `HFGUIDE`.

Deliberately excluded, per the ETL plan: **MMORPG-STAT** (`ai-train=no`) and **PTRE** (terms
disclaim AI training). Neither was collected.

## Layout and how to use it

```text
storage/rl/external/
  catalog.json | catalog.csv     the categorized inventory (source_id, category, kind, action, bytes)
  source-sizes.json              the probe cache (target -> bytes)
  download.log                   the run log
  sources/<SOURCE-ID>/
    manifest.json                provenance: urls, per-file sha256, byte counts, capture time
    raw/                         original payloads (xml.gz / json / tarball + extracted tree)
    normalized/records.jsonl     typed records (or files.jsonl for a code repo's tree)
```

Reproduce / extend:

```bash
# inventory only (metadata, ~no bulk data)
scripts/ogame-external-collect.py --probe
# fetch everything within the cap (needs the metered confirmation)
scripts/ogame-external-collect.py --download --yes
# one source, or a bigger cap on a non-metered link
scripts/ogame-external-collect.py --download --yes --only HF-niveck-llmafia
scripts/ogame-external-collect.py --download --yes --max-total-bytes 20000000000
```

## Prepare (transform) notes

- **Text tables** (CSV/TSV/JSON/JSONL) become `normalized/records.jsonl`; every downloaded source
  produced rows (no source was raw-only).
- **Code repos** are extracted and prepared as `normalized/files.jsonl`, a per-file inventory with
  size and extension - evidence for the engine/battle-logic work, not training rows.
- **Parquet** files are kept verbatim in `raw/`; converting them needs a reader (`pyarrow`) that is
  not installed, so it is left as a documented step rather than adding a dependency on a metered link.
- Provenance is per file (url + sha256); nothing here is anonymized at the collector level because
  every fetched source is already public and published (unlike the OGame API collectors, which drop
  names).

## Next steps

1. **Retry the deferred set off-meter**, smallest first (StrategyBench 2.1 GiB), and probe the four
   unknown-size repos by hand.
2. **Wire the usable corpora** into training via the existing seams only: the social/diplomacy text
   through `rl/scripts/social_corpus.py`, and the OGame engines/bots as behavioural evidence for the
   aspect rows - never as a new class or a hardcoded object list (gates 1-3).
3. **Add a parquet reader** when a training machine (non-metered) is available.
4. Keep `storage/rl/external/` out of git (added to `Modules/AI/.gitignore`).
