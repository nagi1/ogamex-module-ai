# Late-game fleet, Deathstars and stored resources (research note, 5 Nov 2026)

Question: why our sim's top10 (150-190M points at day 256) never hold a Deathstar (DS) and hold ~87 days of
production as stored resources, against real data. Read-only research; numbers only where a source gives them.

Units: fleet value = metal + crystal + deuterium at base prices (`rl/scripts/ogmem_strategies.py` PRICE; DS = 1e7).
1e9 resources = 1M points. OGMEM "filtered" = the 372 battle sides left after `ogmem_strategies.load()` drops
reports with round-thousand unit counts (simulator inputs); "all" = the 1,126 raw sides.
Query scripts (not in the repo): a per-tier count of DS holders and hull value shares on `normalized/battle_unit.jsonl`,
plus the per-side "Weapons N%" parsed from `storage/rl/external/sources/OGMEM/raw/report-*.html.gz` (926 sides).

## Caveats on the main dataset (OGMEM)

- OGMEM = public OGMem battle archive, 599 reports, communities de/es/pl/..., anonymised, captured 2026-10-09
  (`Modules/AI/storage/rl/external/sources/OGMEM/manifest.json`). It holds **no date, no universe age, no account
  points and no economy speed**. Only the fleet/defence at the fight and the side's Weapons/Shields/Armour %
  (that % may include class/lifeform bonuses, so read it as an upper bound on the research level).
- The archive is made of posted reports, so it leans toward big fights and crashes. It is not a census of accounts.
- So OGMEM answers "who holds DS by fleet size and tech". It **cannot** answer "on what day".

## Q1. Deathstar: when, how many, who

- **The 23% / median 2 figure checks out:** filtered OGMEM has 87 of 372 sides holding DS (23%), median 2.
  The split by side is very lopsided. Defenders: 85/186 (46%), median 2, and 51 of those 85 hold ≤5 DS.
  Attackers: 2/186 (1%). (OGMEM query; high.)
- **Who holds DS, by the defender's fleet value (filtered | all):**
  | Fleet value | Fleet points | Defenders with DS | Median DS among holders | Mean DS share of fleet value |
  |---|---|---|---|---|
  | <1e8 | <0.1M | 9% \| 9% | 1 | low |
  | 1e8-1e9 | 0.1-1M | 19% \| 22% | 1 | ~1-3% |
  | 1e9-1e10 | 1-10M | 57% \| 71% | 2 | 1% (holders' median) |
  | 1e10-1e11 | 10-100M | **95% \| 93%** | 11 \| 27 | 9% \| 16% (mean); holders' median ≈0-1% |
  | ≥1e11 | ≥100M | 100% \| 95% | 5,956 \| 1,576 | 51% \| 20% |

  Our sim's top10 have a fleet median of 4.2e10 (`plan/rl/STRONG-PLAYER-PLAN.md`, the day-256 entry), which falls in
  the tier where 93-95% of real defenders hold DS. (OGMEM; high for presence, medium for counts because the samples
  are small: n = 37 and n = 4 filtered in the top two tiers.)
- **The first DS comes with research, not with a fleet-mass threshold.** DS presence among defenders by Weapons %
  (≈ level ×10): W6-13 5-33%; W14-17 47-53%; W18-21 54-70%; W22-23 80%; W26+ 75-100%. Median weapons of DS holders
  is 200% and of non-holders 180%. The lowest holder has 30%, and the 10th percentile is 130%. Bulk stacks (median
  ≥50 DS) only appear at W22+. (OGMEM raw + query; medium, because % includes bonuses.) The requirements are shipyard
  12, hyperspace drive 7, hyperspace tech 6 and graviton 1, and graviton needs 300,000 energy on one planet (WIK-175,
  WIK-139; [owiki Gravitonforschung](https://www.owiki.de/index.php/Gravitonforschung)).
  **Points, days and economy speed at the first DS: no source found.** The only anecdote: at ~300k points it was called "too early, mines
  first" ([gutefrage](https://www.gutefrage.net/frage/ogame-todesstern); low).
- **Graviton is a planned, saved-for event.** The thread on the DE board (2012) is a whole discussion on saving resources first, then building
  3,500-9,000 satellites in one burst (≈24 h, nanite 4-6), timed to when you are scanned least
  ([board.de thread 173530](https://board.de.ogame.gameforge.com/index.php?thread%2F173530-todesstern-bauen%2F=);
  medium). So the first DS is reached through a **step**: save, then graviton, then DS. It is not a steady drip. No
  source describes the pace of DS buying after the first one.
- **Non-attackers build DS.** "Most players use rips solely for fleetsave" (slow and cheap on deut, big cargo: a 7h+
  harvest to their own debris field), and "Miners/turtles using rips in this way often have enough defense"
  ([EN board thread 653047, 2011](https://board.en.ogame.gameforge.com/index.php?thread%2F653047-fleet-composition%2F=);
  medium). The DE wiki/walkthrough snippets say the same thing: "ideal zum Saven von Flotten und Rohstoffen" (ideal for
  saving fleets and resources; search snippet of owiki/walktrough; low). Turtles keep DS "behind a huge wall of defensive
  fodder" (WIK-017) and "Death Stars - 500+ (as mobile defense)" (WIK-018, an old ruleset; low). Once graviton is done, the
  wiki "Defense Build Strategies" page says DS can be used defensively "but not many" (search snippet, the page
  returned 402; low). Expeditions: no. Finds are capped and DS cargo/deut make them a loss (EXP-002;
  [EN board 714431](https://board.en.ogame.gameforge.com/index.php?thread%2F714431-deathstar-for-an-expedition%2F=); medium).
- **Attackers use DS as pure stacks against turtles, never mixed.** OGMEM all: 11 attacks carry DS, 10/11 have ≥90% of
  their value in DS, 579-16,000 hulls (STRONG-PLAYER-PLAN "5 Nov Deathstar"). "Rips almost exclusively work best on their
  own" and give a "no-loss against a turtle" (thread 653047). Largest published crash: an attacker with 25,001 DS among
  7.6M LF, 4.2M HF, 134,633 BC, 101,323 destroyers and 1.19M cargos killed 48,700 DS on a French server
  ([OGame on X](https://x.com/OGame/status/1236215007781715968); high for the numbers). By my arithmetic at base
  prices, DS ≈ 70% of that attacker's value. Those are extreme endgame figures, not day-256 ones.
- **Sources disagree on counts.** Forum and wiki text says "not many" or "1-2 for fleetsave". OGMEM says a median of
  11-27 at 1e10-1e11 and thousands at ≥1e11. These fit together if the 1-2 are the anchor or fleetsave hull and the
  big stacks come only at ≥1e10-1e11 fleet value.

## Q2. Late-game stored resources

- **TP-022's "18-24 h" is not a figure for total stores.** It sizes the **storage buildings on spoke mining planets**
  against the shipping frequency ("Storage levels should match roughly 18-24 hours of production at your chosen dispatch
  frequency"). Its rule is "Resources must move, not sit." It is also a marketing blog for a separate speed-server
  project (`raw/third-party/ogames.net/empire/TP-022.md`, lines 25-34; low as a stockpile benchmark).
- **Guides set the exposure ceiling, meaning what you leave unprotected, at about 1-2 days.** "Defences that will protect one or two day production"
  and "enough defense to protect 24 hours (maybe 48) worth of resource build-up". The same guide says "fleetsave at least once a day"
  ([EN board 737333 Pure Miners Guide, 2013](https://board.en.ogame.gameforge.com/index.php?thread%2F737333-pure-miners-guide-beginning-to-end-game%2F=);
  medium). There is also "If you don't have more than one day of production you are not likely to be attacked" (forum
  anecdote via search; low). WIK-038 says a fleeter fleetsaves the resources and builds only enough defence to make
  the overnight production unprofitable to raid. WIK-013 says "fleetsave and spend your resources promptly" (medium).
- **Saving for days or weeks for a big target is normal and is documented.** In the same miner guide: "at this level it takes many days to save res for
  next level" and "you need days to gather resources to upgrade the next mine" (737333; medium). WIK-028: players "saving
  up for an expensive technology or building" hide the stockpile by starting and cancelling builds or research of ≥4 h, "Days would
  be preferred". WIK-027 lists three ways to protect a stockpile: fleetsave the resources, park them on a harvest mission, or
  hold them in a cancellable build. Graviton itself is a saved-for burst (thread 173530).
- **How big a late-game hoard is, in hours or days: no source found.** No source gives the size of a top player's
  hoard, and none says how many weeks they save for Astrophysics, Graviton, IRN or high W/S/A. The pattern the sources
  do agree on: the hoard exists only while saving for a named target, and it is **never left on the planet**. It flies
  (fleetsave or harvest) or sits inside a cancellable build.
- **Where the sources conflict:** none of them conflict on "a stock sits openly on the capital for months". Every source says
  that gets farmed (WIK farm: profitable once the stock exceeds the attacker's projected losses;
  [Farm](https://ogame.fandom.com/wiki/Farm)). Our sim's median of 87 days, 60% on the capital
  (STRONG-PLAYER-PLAN, re-measure entry), is only realistic if the sim counts in-flight or hidden resources the same
  way. Otherwise it is unsupported by any source.

## Q3. Late-game fleet composition (by value)

- **OGMEM attackers, mean value share (filtered):** 1e9-1e10: BC 53%, cruiser 27%, BS 9%, reaper 7%. 1e10-1e11:
  BC 52%, cruiser 24%, destroyer 6%, BS 6%, reaper 5%, DS 2%. ≥1e11 (n=13): cruiser 55%, BC 28%, reaper 6%, destroyer
  5%. LF, bombers and pathfinders are ≤2% each above 1e9. (OGMEM query; high for the shape.)
- **OGMEM defenders 1e10-1e11 (filtered):** BC 22%, LC 13%, cruiser 10%, DS 9%, reaper 9%, destroyer 7%, BS 5%,
  PF 5%. Across all 1,126 sides, DS reaches 16% at 1e10-1e11 and 20% at ≥1e11. The earlier pooled cut was BC 38%,
  cruiser 17%, DS 8%, LC 7%, reaper 7%, BS 6%, destroyer 5% (STRONG-PLAYER-PLAN, day-256 entry). (high)
- **The 2011 guide, as unit ratios (old ruleset, no reaper/PF):** LF:BS from 5:1 rising to 10:1 or more. Cruiser:BS 1:1, then
  2-3:1, then about 3:1 in late game. BC:BS about 1:1. Destroyer ≤0.5:1. Bomber below that. Recycler ≥1:1 (thread 653047; medium,
  dated). OGMEM shows LF fodder near 0% by value at ≥1e9, but by **count** LF can still be large (the French crash had
  7.6M LF ≈ 9% of its value).
- **Agreement:** the value goes into the BC + cruiser core, about 75-80% of attacking fleets. DS shows up either as a few
  anchors held by defenders or as a pure attacking stack. The sim's top10 (BS 18%, LF 18%, LC 17%, cruiser 16%,
  PF 13%, SC 10%, BC 5%; STRONG-PLAYER-PLAN) are off on BC (5% vs 22-52%) and LF/SC (28% vs ≈1-4%).

## What the sim should do (testable rules)

1. **The DS anchor comes with graviton.** An account that has graviton ≥1 and meets the yard, hyperspace and drive requirements
   holds ≥1 DS, and at least 2 once its fleet value is ≥1e9. Test: share of accounts holding DS by fleet-value tier
   within ±15 pp of OGMEM defenders (9 / 20 / 57-71 / 93-95 / 95-100%).
2. **Graviton is a planned step.** Save, then graviton (satellite burst on one planet), then the first DS. It is not a
   steady drip. The research levels at that point are about W14-20 (OGMEM median holder W20, non-holder W18). Points or day:
   **no source**. Do not hardcode a day.
3. **The count scales with fleet value.** Median DS among holders is 1-2 below 1e10 fleet value, 11-27 at 1e10-1e11, and
   ≥1,000 above 1e11. DS value share among holders stays ≤~15% below 1e11. Test: the sim's median DS count and mean DS
   share per tier match the table above within a factor of 2.
4. **Miners, savers and turtles hold DS too**, as fleetsave and anchor hulls behind defence (653047, WIK-017). The DS rides on the
   long fleetsave (slow, low deut) and carries resources. Never send it on expeditions (EXP-002).
5. **Attackers use DS only as a pure stack** (≥90% DS by value) against a defence-heavy target. Keep the `ds_mixed`
   war cell near 0.
6. **Resource exposure.** On-planet resources not committed to a build or research stay ≤24-48 h of that planet's
   production (737333, WIK-038). Anything above that is either (a) in flight on a fleetsave or harvest, or (b) savings for one named
   target whose cost exceeds 48 h of account production, spent the moment it is affordable (WIK-028, 737333). Measure
   "store" as *unprotected on-planet* resources. If the sim's 87-day hoard is savings for big research, it may stay,
   but it must fly or hide. Test: median exposed store ≤48 h for top10. The total including saves has **no source**,
   so leave it unconstrained.
7. **Fleet value mix at ≥1e10.** BC + cruiser ≈ 60-80% of the attack fleet, reaper and destroyer 5-10% each, LF/SC/bomber ≤5%
   by value. Test: the existing `mil_sh` and hull-share cells against the OGMEM tiers above.

Gaps (no source found): days or points at the first DS; DS buying pace after the first; the size of a late-game hoard in
hours or days; how many weeks players save for a given late research.
