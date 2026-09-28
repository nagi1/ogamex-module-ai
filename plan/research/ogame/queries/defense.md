# OGame defence — Google query batches

Discovery engine: **Google only** (do not silently substitute another engine).

This file is the `queries/` step of the corpus pipeline (`queries → raw → synthesis → behavior`).
It logs *what to look for and why* before anything is fetched. Each batch is one bounded pass that
should yield roughly **5–10 promising URLs** — no more. Execute a batch, then stop.

## How to run one batch

1. Run the batch's Google queries verbatim (run the `site:` ones per board).
2. Keep a URL only if it plausibly contains a **number, ratio, threshold, unit composition,
   production-hour rule or profitability rule** — or a *named school's* stated position.
3. Log the pass (batch id, queries run, URLs kept, unresolved questions) before fetching.
4. Fetch later via the `ogame-source-ingest` skill; raw evidence goes under
   `plan/research/ogame/raw/<family>/<site>/<topic>/<source-id>.md` with a provenance block.
5. Tag every claim `CANONICAL` / `DOCUMENTED` / `MEASURED` / `CONTESTED` / `ANECDOTAL` / `INFERENCE`.
   **Official mechanics and player doctrine must stay distinguishable** — a mechanics claim needs an
   official/Gameforge/Origin source, never a forum post.
6. Register new source ids in `SOURCE-REGISTRY.yaml` using the canonical families (`DEF-`, `ORG-`,
   `GF-`, `WIK-`, `RDT-`, `TP-`, `DEW-`, `FRB-`, …). Reuse the canonical namespace; never renumber.

## The schools (preserve all of them — do not converge on one "correct" answer)

- **A — Wall-first / turtle**: defence is the strategy; the wall is built to make every attack unprofitable.
- **B — Minimal / production-shell**: mines first, a token wall only to cover the stockpile; the wall is overhead.
- **C — No-defence / save-first**: mobility beats masonry; fleetsave and spend the stockpile instead of building.
- **D — Reactive / trigger-based**: no standing wall; build when a threat is observable, sized to the threat.

Batches below deliberately seek sources that argue **against** each other (e.g. DEF-R05 hunts the
anti-wall case, DEF-R04 the pro-wall case).

## Batch index

| Batch | Topic | School(s) targeted |
| --- | --- | --- |
| DEF-R01 | Official Gameforge + Origin defence mechanics/guides | mechanics baseline |
| DEF-R02 | .org/.en forum defence composition and ratios | A vs B (numbers) |
| DEF-R03 | Miner / minimal-defence / production-shell doctrine | B |
| DEF-R04 | Turtle / bunker / heavy-defence doctrine | A |
| DEF-R05 | Fleeter / no-defence / fleetsave-first doctrine | C |
| DEF-R06 | Reactive defence, incoming attacks, profitability | D |
| DEF-R07 | Shield domes, fodder, HL/Gauss/Ion/Plasma mechanics | mechanics + layering |
| DEF-R08 | IPM/ABM and anti-turtle doctrine | counter-play |
| DEF-R09 | Modern Reddit / community defence discussions | all (current opinion) |
| DEF-R10 | Stockpile / storage / protect-vs-spend doctrine | B vs C (thresholds) |

---

## DEF-R01 — Official Gameforge + Origin defence mechanics/guides

- **Goal:** Establish the canonical **rules** (not opinions) that any doctrine must obey: defence
  object stats and requirements, loot/protection rules, bashing, IPM/ABM. The reference for "is this
  a mechanic or an opinion?".
- **Google queries:**
  - `site:gameforge.com ogame defence guide`
  - `gameforge ogame "defence" guide turret`
  - `ogame official rules bashing loot "50%"`
  - `site:board.origin.ogame.gameforge.com rules defence`
  - `gameforge ogame "interplanetary missile" rules`
  - `ogame official tutorial defence shield dome plasma`
- **Preferred domains:** `gameforge.com` (official guides, raider/fleetsave/tutorial pages),
  `board.origin.ogame.gameforge.com` and `board.en.ogame.gameforge.com` official/tutorial threads.
- **Capture:** object costs/requirements/rapid-fire values as officially published; the loot rule
  ("50%, 75% with Raider"); the bashing rule; IPM/ABM mechanics; whether resources above capacity are
  fully lootable. Tag `DOCUMENTED` (official) only.
- **Stop:** 5–10 official pages covering (a) defence object stats, (b) loot/protection rules,
  (c) IPM/ABM. If an official page restates another, keep one.
- **Output:** `plan/research/ogame/raw/official-gameforge/<site>/def-mechanics/`
- **What NOT to infer:** no player opinion as mechanics; no Lifeforms-era stats or artifacts; no
  number that is not printed on the page; do not assume our fork's values match a different ruleset.

## DEF-R02 — .org/.en forum defence composition and ratios

- **Goal:** Find the forum threads where players state **concrete composition, ratios and
  thresholds** for a defence wall, in the Origin/EN ruleset (closest to ours) — the raw material for
  school A vs school B.
- **Google queries:**
  - `site:board.origin.ogame.gameforge.com defence`
  - `site:board.en.ogame.gameforge.com defence guide`
  - `site:board.en.ogame.gameforge.com "defence ratio"`
  - `ogame defence composition light laser heavy laser gauss ion plasma ratio`
  - `ogame "how much defence" ratio "resources on the planet"`
  - `site:board.de.ogame.gameforge.com verteidigung guide`
  - `site:board.us.ogame.gameforge.com defense guide`
  - `site:board.fr.ogame.gameforge.com defense guide`
- **Preferred domains:** `board.origin.ogame.gameforge.com`, `board.en.ogame.gameforge.com`; then
  `.de` / `.us` / `.fr` boards (different language is the intentional difference, not duplication).
- **Capture:** every stated defence:resources rule, "X hours of production" thresholds, unit mixes
  and the resulting disagreement between posters in the same thread. Quote the numbers verbatim.
- **Stop:** 5–10 threads that each contain at least one number/ratio.
- **Output:** `plan/research/ogame/raw/official-gameforge-forum/<board>/def-composition/`
- **What NOT to infer:** do not average competing ratios; do not treat one poster's number as
  consensus; do not convert a ratio into an absolute unit count.

## DEF-R03 — Miner / minimal-defence / production-shell doctrine

- **Goal:** Capture **school B** — how a miner sizes (or minimises) defence and why, and what they
  rely on instead (small footprint, spend, save).
- **Google queries:**
  - `ogame miner guide defence how much`
  - `ogame miner minimal defence mines only strategy`
  - `ogame production playstyle defence guide`
  - `site:board.en.ogame.gameforge.com miner defence`
  - `ogame ultimate miner guide defence`
- **Preferred domains:** `board.en.ogame.gameforge.com` (the "ultimate miner guide" family),
  `reddit.com/r/ogame`, `ogames.net`, `sidian.app`.
- **Capture:** the miner's stated defence rule, the number ("N hours of production", "only a small
  wall"), the reasoning, and whether they build at all.
- **Stop:** 5–10 sources that state a miner rule with a number or an explicit "no wall" position.
- **Output:** `plan/research/ogame/raw/<family>/<site>/def-miner/`
- **What NOT to infer:** a miner's minimal wall is **not** a universal rule; do not generalise it to
  Turtle/Fleeter; do not assume "miner" implies "no defence".

## DEF-R04 — Turtle / bunker / heavy-defence doctrine

- **Goal:** Capture **school A** — how a turtle sizes a wall, what "make the attack unprofitable"
  means numerically, and whether defence is points/ROI-driven.
- **Google queries:**
  - `ogame "defence wall" turtle guide`
  - `ogame turtle bunker defence guide`
  - `ogame defence ROI points score why build defence`
  - `ogame how much defence to make a planet unprofitable`
  - `site:board.en.ogame.gameforge.com turtle defence`
- **Preferred domains:** `board.en.ogame.gameforge.com` (tutorial/player-styles threads),
  `reddit.com/r/ogame`, guide sites.
- **Capture:** wall composition, layering order (dome first? fodder first?), the profitability
  threshold, any points/ROI argument, and how the wall scales with the stockpile.
- **Stop:** 5–10 sources stating a turtle rule with numbers.
- **Output:** `plan/research/ogame/raw/<family>/<site>/def-turtle/`
- **What NOT to infer:** do not copy a wall ratio without its stockpile context; do not present the
  turtle position as the default; do not invent a "correct" defence-to-value percentage.

## DEF-R05 — Fleeter / no-defence / fleetsave-first doctrine

- **Goal:** Capture **school C** — the explicit argument that defence is a waste (spend it, or
  fleetsave instead), since it is the direct contradiction of school A and must survive as a doctrine.
- **Google queries:**
  - `ogame fleeter guide no defence build fleets instead`
  - `ogame "defence is a waste" fleetsave instead`
  - `ogame defence "waste of resources" spend instead fleetsave`
  - `ogame no defence strategy fleeter`
  - `site:reddit.com/r/ogame fleeter no defence`
- **Preferred domains:** `board.en.ogame.gameforge.com` fleeter guides, `reddit.com/r/ogame`,
  `ogames.net` raiding guide, `gameforge.com` raider guide.
- **Capture:** the stated reason, any numbers (what they build instead), and whether they ever build a
  token wall. Quote the strongest anti-wall statements verbatim.
- **Stop:** 5–10 sources that state the no-defence/fleetsave-first position.
- **Output:** `plan/research/ogame/raw/<family>/<site>/def-none-fleeter/`
- **What NOT to infer:** this school is **not** "wrong" and must not be discarded; do not treat it as
  a beginner error; do not merge it with the turtle case.

## DEF-R06 — Reactive defence, incoming attacks, profitability

- **Goal:** Capture **school D** — the decision rule *given an inbound attack* (defend vs fleetsave vs
  nothing), keyed on time-to-impact, and the numerical meaning of "unprofitable".
- **Google queries:**
  - `ogame incoming attack defend or fleet save`
  - `ogame attacked what to do defence or fleetsave`
  - `ogame build defence before attack arrives how much`
  - `ogame "make the attack unprofitable"`
  - `site:board.en.ogame.gameforge.com incoming attack defence`
- **Preferred domains:** `board.en.ogame.gameforge.com`, `reddit.com/r/ogame`,
  `gameforge.com/en-GB/games/ogame-fleetsave.html`.
- **Capture:** the threshold (time-to-impact at which a player builds vs saves), the reactive build
  quantity, and the profitability condition. Separate "a probe is inbound" from "an attack is inbound".
- **Stop:** 5–10 sources that state a reactive rule with a time or profit threshold.
- **Output:** `plan/research/ogame/raw/<family>/<site>/def-reactive/`
- **What NOT to infer:** do not collapse espionage probes into attacks; do not invent a reaction
  window; do not assume the player knows the attacker's composition.

## DEF-R07 — Shield domes, fodder, HL/Gauss/Ion/Plasma mechanics

- **Goal:** Pin the **mechanics of layering**: what shield domes actually protect, which ships each
  defence counters (rapid fire), and the fodder-vs-heavy relationship — so a composition rule rests on
  mechanics, not vibes.
- **Google queries:**
  - `ogame defence guide shield dome plasma turret build order`
  - `ogame small shield dome vs large shield dome worth it`
  - `ogame plasma turret worth it how many to build`
  - `ogame defence cruiser rapid fire light laser rocket launcher`
  - `ogame defence destroyer bomber plasma gauss strategy`
  - `site:board.en.ogame.gameforge.com "rocket launcher" plasma defence`
- **Preferred domains:** wikis/mechanics pages (`sidian.app`, fandom), official stats pages, then the
  EN board for the composition opinions.
- **Capture:** rapid-fire values (defence ↔ ship), dome mechanics (does a dome shield the whole stack?),
  dome cost/benefit, and the stated fodder/heavy/plasma ratio at small/medium/large stockpiles.
- **Stop:** 5–10 sources; at least one mechanics page for rapid fire and dome behaviour.
- **Output:** `plan/research/ogame/raw/<family>/<site>/def-layering/`
- **What NOT to infer:** never state a rapid-fire or dome number without the page it came from; do not
  mix a mechanics page with a player opinion in one claim; do not invent tank/plasma counts.

**Pass log — 2026-09-28 (partial batch: query 1 of 6, Top-3 of the SERP only)**

- Query run: `ogame defence guide shield dome plasma turret build order` (Google, EN result set).
  Direct HTTP fetch of Google is served a JS challenge; the SERP loaded in the integrated browser.
- Kept 3 of the top 3: `WIK-013` (ogame.fandom.com `Defense_Build_Strategies`), `GF-007`
  (gameforge.com EN-GB defence-building page), `TP-021` (ogame.life defence-ratio guide).
  Raw: `raw/{wiki,gameforge,third-party}/…/def-layering/`.
- **Not independent:** `TP-021` reproduces `WIK-013`'s Early Game / Balanced / Big Gun Heavy ratios and
  renames its "Equivalent Effective Cost" section — one source behind two citations. Recorded as a
  negative control in the raw file.
- **Resolved 2026-09-28:** the `WIK-013` Big Gun Heavy disagreement was a *render-capture* defect, not a
  source inconsistency. Re-captured as verbatim wikitext through `api.php` (unblocked), the ratio lines
  read 1 LL per 2 RL, 1 HL per 10, 1 Ion per 25, 1 Gauss per 50, 1 Plasma per 100, and the printed
  1000-RL list is **500 LL / 100 HL / 20 Gauss / 10 Plasma / 40 Ion** — the counts agree, only the
  printed *order* differed. Eight further sections the render never showed are now in the file.
  Lesson: capture wiki sources through `api.php?action=parse&prop=wikitext`, never a rendered snapshot.
- Harvested in the same pass: 89 staged pages (all of `Category:Strategy` + all of
  `Combat`/`Rules`/`Moon`/`Defenses`/`Class`) produced **17 raw files** — `WIK-013` corrected plus
  `WIK-014`…`WIK-029` added for this batch (miner, turtle, fleeter, reactive, ipm, stockpile).
- **Redirect case, worth knowing:** `1% Rule (The bounce effect)` is not a page — it redirects to
  `Bouncing Effect`, which *is* on the doctrine list. The sift drops redirects and resolves them to the
  target, so nothing was lost; registered as `WIK-030` under the canonical title and never under the
  redirect. Its claim is the only one in this batch that the wiki could not carry alone, so it was
  checked against the host: `PhpBattleEngine::attackUnit` deals shield damage in whole multiples of 1 %
  of the defender's **original** shield, `floor(damage / 1 %) == 0` is the bounce, stripped shields take
  full damage "however weak the shot", and bounced shots never roll for hull explosion. The wiki's
  `WT ≥ 2×ST + 10` figure for Light Fighter vs Large Shield Dome reproduces exactly from the host
  formula — the first `CANONICAL` mechanics claim in this corpus, and the model for how every other
  mechanics statement has to be confirmed.
- Still open for this batch: queries 2–6, i.e. dome-vs-dome worth, plasma-turret count, cruiser
  rapid fire, destroyer/bomber/plasma, and the EN board `"rocket launcher" plasma defence` search;
  and no mechanics source yet for the rapid-fire table (only community pages so far).

**Inventory note — `Special:AllPages` is the wrong list, use the category.** That page holds the whole
wiki (500+ content pages and still continuing, mostly per-object stat pages that duplicate the host and
must never become a second authority) and `action=parse` refuses special pages anyway. The wiki's own
classification is the bounded set:

```
python3 scripts/ogame-source-fetch.py --category Strategy
```

The module-wide sift of all 606 pages — its rule, buckets and exclusions — is in `queries/wiki-corpus.md`.

33 pages, complete, no continuation; **6 registered, 27 missing**. The defence batches can draw on:
`Miner`, `Gaining resources`, `Profit` (R03) · `Turtle`, `Uber-Turtle`, `Vault Planet`,
`Evaluation of Defense` (R04) · `Fleeter`, `Save`, `Resource Saving`, `Resource Hiding` (R05, R10) ·
`Canceling`, `Safety Probe` (R06). R07 has no other layering page and **R08 has no page in this
category at all** — the IPM/ABM mechanic still needs an official source, since the wiki cannot supply
a mechanics claim. Remaining members (`Blind phalanx`, `Bouncing Effect`, `Farm`, `Headhunt`,
`Herscheling`, `Mobile Attack Base`, `Moonchance Strategy`, `Prat`, `Quick Start Guide`, `Tips`,
`Tips For Creating An Alliance`, `Tips for Picking a Universe`, `Wave`, `Wavefarming`) are economy /
attack / social doctrine and belong to batches that do not exist yet — do not fetch them from a
defence pass.

## DEF-R08 — IPM/ABM and anti-turtle doctrine

- **Goal:** Capture the **counter-play** that constrains walls: interplanetary missiles, anti-ballistic
  missiles, and how attackers break a turtle — because a doctrine that ignores IPM is unrealistic.
- **Google queries:**
  - `ogame IPM turtle anti ballistic missile defence strategy`
  - `ogame interplanetary missile defence counter`
  - `ogame anti-ballistic missile how many`
  - `ogame how to break a turtle defence`
  - `site:board.en.ogame.gameforge.com interplanetary missile defence`
- **Preferred domains:** official rules/stats pages, `board.en.ogame.gameforge.com`, `reddit.com/r/ogame`.
- **Capture:** IPM damage vs defence, ABM interception mechanics, missile silo capacity, and any
  stated "turtle is dead because of IPMs" argument with numbers.
- **Stop:** 5–10 sources covering both the IPM mechanic and the anti-turtle doctrine.
- **Output:** `plan/research/ogame/raw/<family>/<site>/def-ipm/`
- **What NOT to infer:** do not conflate the IPM (offence) with the ABM (defence); do not assume
  missile silo levels; do not import Lifeforms-era missile changes.

## DEF-R09 — Modern Reddit / community defence discussions

- **Goal:** Capture **current** (post-2020) community opinion across all schools, with dates and
  vote-scores, to see which doctrine dominates today.
- **Google queries:**
  - `site:reddit.com/r/ogame best defence composition`
  - `site:reddit.com/r/ogame defence worth it`
  - `site:reddit.com/r/OGame "daily production" defense`
  - `site:reddit.com/r/ogame shield dome worth it`
  - `site:reddit.com/r/ogame how to defend beginner`
- **Preferred domains:** `reddit.com/r/ogame` and `reddit.com/r/OGame` (both subreddit spellings).
- **Capture:** the thread's date, the prevailing answer, the dissent, and any numbers. Note whether the
  thread is about the current ruleset.
- **Stop:** 5–10 threads from the last ~5 years.
- **Output:** `plan/research/ogame/raw/reddit/<sub>/def-community/`
- **What NOT to infer:** a single comment is not doctrine — capture the thread's balance (upvotes,
  dissent); a five-year-old thread is not "modern"; reddit ≠ official mechanics.

## DEF-R10 — Stockpile / storage / protect-vs-spend doctrine

- **Goal:** Capture the **threshold rules** that decide whether a stockpile is defended, spent, hidden
  or ignored — the numbers that bridge school B and school C (and the "X hours of production" rule).
- **Google queries:**
  - `ogame holding too many resources what to do with them`
  - `ogame how to protect resources from being farmed`
  - `ogame storage full spend resources instead of upgrading storage`
  - `ogame what to do with resources can't spend`
  - `site:board.en.ogame.gameforge.com "daily production" defence`
- **Preferred domains:** `board.en.ogame.gameforge.com`, `reddit.com/r/ogame`, storage/guide pages,
  `gameforge.com` guides.
- **Capture:** the exact rule for "how much of a stockpile to hold", the "24–48 hours of production"
  style thresholds, and the spend-vs-defend-vs-store positions with numbers.
- **Stop:** 5–10 sources that state a stockpile rule with a number or a time window.
- **Output:** `plan/research/ogame/raw/<family>/<site>/def-stockpile/`
- **What NOT to infer:** do not conflate "storage capacity" with "defence"; do not assume a stockpile
  rule is persona-independent; do not invent a percentage of resources that "must" be defended.
