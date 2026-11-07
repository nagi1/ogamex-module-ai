# OGame wiki — module-wide corpus sift

The `queries/` step for a whole-site source rather than a topic. It answers one question: of every
page on `ogame.fandom.com`, which are worth ingesting for the AI module, and which are noise, duplicate
host authority, or the wrong ruleset.

Reproduce the inventory with:

```
python3 scripts/ogame-source-fetch.py --inventory > /tmp/inventory.tsv
```

That call needs **no page bodies** — `api.php` returns every content page with its categories and
redirect flag, so the sift is metadata work, not a crawl. 306 of 309 articles carry at least one
category, which is why metadata alone is sufficient.

## The rule

| Bucket | Membership | Verdict |
| --- | --- | --- |
| redirect | `redirect` flag set | **drop** — resolve to the target; never register a redirect as a source. Worked example: `1% Rule (The bounce effect)` redirects to `Bouncing Effect`, which is in `Strategy`, so the rule was never at risk |
| excluded-ruleset | `Outdated articles`, `Discontinued`, `Lifeform`, `Lifeform Research` | **hard drop** — a different or dead ruleset; the batch rules forbid importing Lifeforms-era and discontinued content |
| doctrine | `Strategy` | **keep** — the corpus's actual target |
| mechanics | `Combat`, `Rules`, `Moon`, `Defenses`, `Class` | **keep** — the mechanics doctrine cites; still only `CONTESTED` until the host confirms a number |
| objects | `Buildings`, `Ships`, `Technologies`, `Resources`, `Facilities`, `Civil Ships` | **keep out of `raw/`** — per-object stats the host already owns; ingesting them creates a second authority that can drift |
| noise | `Article stubs`, `Terms`, `Community`, `Ogame User Interface`, `Premium Service`, `Den`, `Humans`, `Browse` | **drop** — glossary entries, UI, pay features, social texture |
| concepts | everything above leaves over | **eyeball required** — formulas and concepts mixed with help/view pages |
| tools | `Tools` | **keep** — the wiki's own index of battle simulators, calculators, stat sites and add-ons, plus the rule that only Gameforge-approved add-ons are legal |
| ruleset-dating | `Changelog` | **keep** — the only dated pages on the wiki, i.e. the way to age-check a claim |
| alliance | `Alliance Related` | **keep, different batch** — alliance mechanics (ACS, NAP, war, circulars) belong to a social/diplomacy batch that does not exist yet |
| espionage / expedition / miner / dens | `Espionage`, `Expedition`, `Miner`, `Resource hideouts` | **mostly already covered or wrong ruleset** — the class pages were kept via `Class`; the Stealth/Low-Temperature drives and the resource dens are Lifeform-era |

**A keep-category must outrank a quality tag.** `Article stubs` and `Terms` describe how *thin* a page
is, not what it is *about*, so they may only exclude a page that has no keep-category. Applying the
exclusions first silently deleted **15 of the 34 doctrine pages** — including `Fleetsaving`, `Ninja` and
`Fleetcrash`, all three already registered and load-bearing. Only the ruleset categories are hard drops.

**Derive the buckets from the complete category list, never a sample of it.** The rule table above was
first written from the ~25 busiest categories, so nine topic categories were never given a verdict and
their articles fell silently into `concepts` (see the audit below).

```
python3 scripts/ogame-source-fetch.py --category <Name>     # per category
```

The wiki has **58 categories**; `list=allcategories` enumerates them, and every one needs a verdict
before the sift is trustworthy.

## Result

| | count |
| --- | --- |
| content pages (namespace 0) | 606 |
| redirects | 297 |
| articles | 309 |
| — excluded, wrong ruleset | 24 |
| — excluded, noise | 66 |
| — **doctrine** | **34** |
| — **mechanics** | **56** |
| — objects (host authority) | 61 |
| — concepts, needs an eyeball pass | 68 |

## Doctrine worklist (34)

The ingest target, in the wiki's own classification:
`Blind phalanx` · `Bouncing Effect` · `Canceling` · `Colonization` · `Defense Build Strategies` ·
`Evaluation of Defense` · `Farm` · `Fleetcrash` · `Fleeter` · `Fleetsaving` · `Gaining resources` ·
`Headhunt` · `Herscheling` · `Miner` · `Mobile Attack Base` · `Moonchance Strategy` · `Ninja` ·
`Playing Styles` · `Prat` · `Profit` · `Quick Start Guide` · `Resource Hiding` · `Resource Saving` ·
`Safety Probe` · `Save` · `Strategy` · `Tips` · `Tips For Creating An Alliance` ·
`Tips for Picking a Universe` · `Turtle` · `Uber-Turtle` · `Vault Planet` · `Wave` · `Wavefarming`

Registered (21 of 33; `Strategy` is navigation, not evidence). Earliest six: `Colonization`,
`Defense Build Strategies`, `Fleetcrash`, `Fleetsaving`, `Ninja`, `Playing Styles`. Ingested
2026-09-28 as `WIK-014`…`WIK-030`: `Miner`, `Profit`, `Gaining resources`, `Turtle`, `Uber-Turtle`,
`Vault Planet`, `Evaluation of Defense`, `Fleeter`, `Save`, `Canceling`, `Safety Probe`,
`Resource Saving`, `Resource Hiding`, `Moonchance Strategy`, `Bouncing Effect` — the last one being the
only claim here the wiki could not carry alone, so it was confirmed against the host battle engine.

Still unregistered: `Blind phalanx`, `Farm`, `Headhunt`, `Herscheling`,
`Mobile Attack Base`, `Prat`, `Quick Start Guide`, `Tips`, `Tips For Creating An Alliance`,
`Tips for Picking a Universe`, `Wave`, `Wavefarming` — economy/attack/social doctrine, for batches that
do not exist yet.

All 89 keep-set pages (doctrine + mechanics) are staged from this pass, so the remaining 13 doctrine
pages and the 56 mechanics pages need no re-fetch — only the ingest step.

## Notes for whoever runs the next pass

- `Class`, `Collector`, `General`, `Discoverer`, `Researcher`, `Trader`, `Warrior` are in the keep set
  because the host has character classes and doctrine differs per class — they are the one place a
  mechanics page is a *strategy* input.
- `Changelog version 7.0.0` / `7.1` are the only dated pages in the set and are the way to age-check a
  doctrine claim against the ruleset in force.
- The 68 concepts still contain screen/view/help pages (`Empire view`, `Research Screen`, `Tutorial
  Help`, `Main Page`) next to real ones (`Formulas`, `Fuel Consumption`, `Distance`, `Temperature`,
  `Energy`, `Espionage`, `Expedition`, `Tactical Retreat`, `Scrap Merchant`). Expect roughly half to
  survive that pass; do it when a batch needs a formula, not as a bulk fetch.
- Nothing here overrides the rule that a **mechanics claim needs an official source**. Every wiki page
  in the keep set is `DOCUMENTED` at best, `ANECDOTAL` where it states doctrine.

## Audit — 2026-09-28 (the double-check)

**Verified complete — the enumeration.** 606 ns0 pages = 309 articles + 297 redirects, and that article
count matches `siteinfo`'s, so no article is unaccounted for. All 297 redirects were resolved: 296 land
on pages in the enumeration, `Aliens` → `Expedition/Aliens` is a red link (dead), and 8 point outside
ns0 (user, template, project, interwiki).

**Found incomplete — the classification.** The rule table was written from the ~25 busiest categories
of a frequency sort, but the wiki has **58**. Nine topic categories therefore never received a verdict
and their articles fell silently into `concepts` and were never fetched. The substantive misses are now
staged:

| Missed | Why it matters |
| --- | --- |
| `Changelog version 7.0.0` (8.0 KB), `Changelog version 7.1` (12.4 KB) | the only dated pages on the wiki — the way to age-check any claim against the ruleset in force |
| `Tools` (7.9 KB) | the wiki's own index of battle simulators, calculators, stat sites and add-ons, including the rule that only Gameforge-approved add-ons are legal (the same constraint as `ORG-014`) |
| `Simulator` (5.8 KB) | the community's combat simulator, for the measurement tier |
| 17 `Alliance Related` pages | ACS, NAP, war, circular messages, ranks — a social/diplomacy batch, not a defence one |

Correctly irrelevant inside those same categories: the Lifeform-era items (`Stealth Field Generator`,
`Low-Temperature Drives`, the three resource dens) and `Discoverer` / `Collector` / `Trader`, which were
already kept through `Class`.

**Namespaces: only ns0 was ever enumerated.** `User` holds 500+ pages and at least one defence
calculator; `Template` (107), `Category` (49), `Project` (8), `Module` (1) and `File` (500+) are
infrastructure. Leaving them out is correct — but it was an oversight that happened to be harmless, not
a decision, so state it as one.

**Tool bug found by the audit and fixed:** a red link anywhere in a batch aborted the whole run
(`KeyError: 'parse'` from the API's error object). Missing titles now report `FAILED` and the batch
continues — `Admin alliance` is a red link, and it no longer costs the other 23 pages.

Staged total after both passes: **113 pages**.
