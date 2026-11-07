# Expedition vs raid vs mine income (real OGame against ogsim)

Question: real income split by phase and player type, and whether the sim's expedition yield (2.4-3.1x mine
production for every persona) is too high and its raid yield (0.1-3% of production) too low. Research only.

Sources: the forum corpus `storage/rl/external/sources/FORUM/board_en_ogame_gameforge_com/normalized/post.jsonl`
(cited as `EN <post_id>`), wiki captures WIK-173 and WIK-079 (`raw/wiki/...`), and the web pages linked below.
`real-bands.json` holds only score shares (eco/res/mil), with no income split. OGMEM (599 reports) covers crashes,
not farming. Neither can measure income.

## 1. Expedition formula: real vs sim

**Sim and host** (`rl/ogsim/src/expedition.rs` `base_find`/`max_find`/`resources_found`; host `ExpeditionMission`,
catalogue `ExportAiCombatCatalogueAction::expedition`):
`metal = U[0.1, 1] x B(top1) x class x 2(Pathfinder) x variant`. The variant is 1 (89%), U{2..3} (10%) or U{5..10}
(1%). The find is cut only to the free hold. B is the bracket, for example 2.4M for top-1 5-25M. Class is
1.5 x eco for a Discoverer and 1 otherwise. Crystal is 2/3 and deuterium 1/3 of the metal find. Expected find:
**0.668 B**, and the largest possible find is 10 B. There is no expedition-points (structure) cap and no
system depletion. The late runs use `class=3`, so **every persona is a Discoverer at eco 8: x12 (x24 with a
Pathfinder)** (`combat-u1-class3.json` `class_resource_multiplier` = 12).

**Real.** The classic formula, still structure-based after 7.1 (DE board t211203, post of 28.03.2020: "The relevant
structure points needed didn't change"):
- `metal = factor x min(expedition points, cap(top1))`, where expedition points are structure x 0.005.
- The factor is 10-50 (89%), 50-100 (10%) or 100-200 (1%). The find is also cut to the cargo hold.
- Crystal is /2 and deuterium /3 of the metal find.
- B is cap x 200, so **B is the largest possible (1%) find, not the normal one**.
  ([keywalker wiki](https://wiki.keywalker.de/doku.php?id=missionen%3Aexpedition),
  [EN guide t810634](https://board.en.ogame.gameforge.com/index.php?thread%2F810634-guide-10-expedition-guide%2F=):
  "<100k: 2,500 points, max metal 500,000").
- v7/7.1 multiplies only by x2 for a Pathfinder and by 1.5 x eco for the Discoverer alone ([EN bug thread t813878](https://board.en.ogame.gameforge.com/index.php?thread%2F813878-eco-factor-in-expedition-formula-only-for-discoverer%2F=&pageNo=2): "Collector 1x or 2x, Discoverer 10.5x or 21x" at eco 7).
- Fandom (WIK-173, live wikitext) gives a maximum of 75M at eco 5 and top-1 above 100M, which is 5M x 1.5 x 5 x 2: the
  bracket is the ceiling. It adds: "The average finds will be much smaller."
- Expected find: (0.89 x 30 + 0.10 x 75 + 0.01 x 150) / 200 = **0.179 B**.
- Depletion: a system recovers about 10 expeditions a day (t810634; [Sidian](https://sidian.app/s/ogame-wiki/guides/expeditions)).

**Named difference.** The sim treats B as the normal-find maximum (uniform 10-100%) and then stacks x2-3 and
x5-10 variants on top. In the real game, B is the top of the 1% huge find, and normal finds are 5-25% of it.
- Sim mean per resource find: 0.668 B. Real mean: 0.179 B. With the resource mix, the sim's mean is **4.1x the
  real one**.
- The sim also ignores the structure cap (small early fleets get full finds) and depletion. It gives crystal 2/3
  of the metal find instead of 1/2.
- Sidian's "U[0.1,1] x cap" page matches the sim, and it is probably what the host copied. It is an unsourced
  mirror and the measured averages contradict it:
  - Langston, a Collector at eco 7 with 900 LC and a Pathfinder (ceiling 10M), logged 287 expeditions: about 83M in
    all, or about 0.88M per resource find (0.088 of the ceiling). The classic formula predicts 0.11 and the sim
    predicts 0.45.
  - Grimm, a Discoverer at eco 7, averaged 4.26M per expedition: about 0.12 of the ceiling per resource find
    (t813878 p2).
  - Both are about 4-5x below the sim and fit the classic formula within about 20%.

**Worked number (mid player, months 2-4, eco 8, top-1 5-25M, Discoverer with a Pathfinder, 7 slots).**
- Ceiling is 2.4M x 12 x 2 = 57.6M metal. The real mean per resource find is 6.3M resource units; the sim's is
  25.7M.
- An active human sends 6-7 waves a day, about 45 expeditions (EN 7194319 "6-7 waves"; EN 7195518 "every
  90-120 min"). Resources alone then come to:
  - real: 45 x 0.325 x 6.3M = **92M/day (3.8M/h)**
  - sim formula: **414M/day (17M/h)**
- Mines at 32/28/28 on 12 planets with plasma 10 at eco 8 produce **84M/day (3.5M/h)**.
- Real expedition resources/mines is therefore about **1.1x** (about 1.6x counting ship finds). A bot-like 15
  waves a day gives at most about 2.5x. The sim formula gives about 4.9x.

## 2. Real income shares (% of total income, active player, eco x8 style, lifeform era)

| phase | miner / Collector (mines/expo/raid) | expeditioner / Discoverer | raider / General | evidence |
|---|---|---|---|---|
| 0-2 wk | 80-95 / 5-15 / 0-5 | 30-50 / 45-65 / 0-10 (75% inactive loot) | 40-60 / 0-10 / 30-55 | EN 7195518 (Narvi day 15: Disco 200-300M/day from expeditions vs General farming 100M/day), EN 7253607 |
| 2 wk-2 mo | 75-90 / 5-20 / 0-5 | 30-45 / 50-65 / 0-10 | 35-55 / 5-10 / 35-60 | EN 7194319 (19M pts: 150M+ res + 100M fleet/day), EN 6979151 (goal 100M/day farming vs top miner 90M/day, EN 6978813) |
| 2-4 mo | 70-90 / 10-30 / 0-5 | 25-40 / 55-70 | 40-60 / 5-10 / 30-50 | EN 7115053 (mines 200M/day, expeditions 500M+/day), EN 7179586 ("close to 1B/day in x8 uni"), EN 7142294 (Collector, 60 expeditions/day: at most 300-350M) |
| 4-8 mo | 70-90 / 10-30 / 0-5 | 20-40 / 60-75 (+ ship finds 1-2x again) | 50-70 / 5 / 25-45 (crash debris is lumpy) | EN 7271760 (Lyra: miner at 45/38/40 makes about 1B/day; explorer 1.5-2B res + 2-4B fleet), EN 7079983 (farming growth about 2-3x production in a fast-fleet uni), EN 7244427 |

- **Top10 vs mid.** Real top10 are military-heavy, but that fleet is largely fed by **Discoverer expeditions plus
  ship finds**, not by loot: "tons of top ranked fleeters on Narvi are discos" (EN 7195518); "explorer / fleeter
  way better than miner" (EN 7271760). Mid and casual players run 1-3 waves a day, so their expedition share is
  about half the table's Discoverer column. Generals and Collectors get finds 12x smaller at eco 8, so their
  expedition share stays under 10-30%.
- **Raid income per day of active farmers:**
  - 5M/day for a small account (50 raids at 100k, EN 7253607)
  - 20-30M/day in a dead uni (US 627008)
  - 100M/day as a 2018 Vega goal (EN 6979151), against 90M/day for the 3rd-best miner (EN 6978813)
  - 100M/day from players, 12 h/day active (EN 7195518)
  - 140M/day (EN 7148857)
  - 100M+/hour for a top old-uni fleeter (EN 7053149)
- **Raid/own production for an active raider:** typically **0.5-2x**, 0.2-0.5x for a casual one.

## 3. Verdict

- **Expedition yield too high: about 4x per find** (formula shape, section 1). On top of that, the sim gives **every
  persona Discoverer x12**. For a raider or miner that should be a General or Collector, real finds are about
  **1/12 x 1/4, roughly 1/50 of the sim's**. Dividing the sim's 2.4-3.1x by 4.1 gives about 0.6-0.75x for a
  Discoverer. That is the low end of the real range: casual Discoverers make 0.5-1x, active ones 1-2.5x from
  resources alone. So after the fix, check expedition frequency and fleet size, and do not cut further.
- **Raid yield too low: about 15-50x for raiders** (sim 2-4% of production, real active 30-150%, central about
  50%). The likely cause is the farms, not the loot rule: the sim's quitters (half the accounts, mean 15 days,
  `season.rs:20`) are few, small and early, against thousands of inactives in a real uni. The 99% no-loss fights
  (REPLICATOR-PERSONAS item 2) point the same way. Check what a quitter keeps producing and its stock on day X.
- **Confidence.**
  - High: the sim formula, the Discoverer-only eco multiplier and the all-Discoverer arm.
  - Medium-high: the 4x shape gap. Three independent sources plus two field averages agree; Sidian is the only
    dissent.
  - Low-medium: the table's percentages. They are forum anecdotes from active and mostly top players. No source
    gives a measured income split, and Gameforge publishes none.

## 2Moons cross-check (5 Nov, our copy: storage/rl/external/sources/GH-jkroepke-2moons/.../MissionCaseExpedition.class.php)
- Ship expedition points = (metal + crystal cost) × 5 / 1000 per ship (l.42); fleet points summed (l.52).
- Resource find: size roll 0-100 → factor 10-50 (>10, ~89%), 50-100 (1-10, ~10%), 100-200 (0, ~1%) (l.65-81);
  found = min(free cargo, min(maxFactor, max(200, factor)) × fleetPoints) (l.125), maxFactor 2400/6000/9000/12000 by
  top-1 points (l.108-122). So the rare/huge classes ARE the factor (no multiplier on top) and the find scales with
  the fleet's points. Bug: max(200, factor) is always 200 → every find is the huge one; maxFactor caps the factor,
  not the points. Same structure as classic OGame, wrong numbers.
- OGameX (app/GameMissions/ExpeditionMission.php l.1062-1102, l.379): U[10%,100%] of the top-1 bracket max, then ×1 /
  2-3 / 5-10; fleet size only matters through cargo. Both differ from the real "factor × min(fleet points, cap)".
