# What real top10 players earn that the sim's top10 does not (5 Nov)

**Question.** With the real expedition find size, the sim's top10 in `top10|128-256` reaches a median growth of 21k
points per speed-day (bench `storage/rl/ogsim/runs/late/expo-a/hds.txt`). The real median is 98.6k. Which missing
income explains the gap, and is the real reference itself biased? This is research only: no simulations were run.

**Sources.**
- Our data: `real-bands.json`, `real_bands.py`, the 267 `PUB-*` API dumps (raw `serverData.xml`) and the OSTATS
  catalogue.
- The forum corpus (`storage/rl/external/sources/FORUM/board_en_.../post.jsonl`, cited as `EN <post_id>`).
- Reddit (`REDDIT/ogame/normalized/comment.jsonl`, cited as `R <reddit_id>`).
- The sim seasons `expo-a` and `spy-a` (`ready-*.jsonl`, `*.levels.json`).

## 1. The reference: every universe is a lifeform universe

- **All 267 universes are lifeform universes.** All of them run version `13.0.1-r2hf` and carry
  `<lifeformSettings>` in serverData (count: 267 of 267).
  - The 27 universes behind `top10|128-256` are all speed 8, and they opened 168-252 days before capture.
  - Their age comes from the alliance found date, which matches the OSTATS `opened` date within one day for 264 of
    267 universes. So these universes had lifeforms from day one.
  - Our sources hold **no non-lifeform universe and no pre-lifeform snapshot**. Lifeforms arrived with v9 in 2022
    (EN 7173605). A clean split of the 267 universes is therefore impossible.
  - Wayback has only sparse pre-2022 API captures, for example s1-de in 2015-16. Universe-matched speed-8 data
    from before 2022 would be a new collection job.
- **Lifeform points are already removed, but only roughly.** `lifeform_free()` scores `min(total, eco+res+mil)`.
  - Lifeform share of total points among top10 in this cell (n=163): median **12.0%** (p10 2.5%, p90 35.5%).
  - Growth including lifeform points is 116.8k; without them it is 98.6k.
  - The subtraction is imperfect because economy and military double-count some units. For example, br178 #4 has
    eco+res+mil 4.6% *above* its total. So the true lifeform share is somewhat higher, and the lifeform-free total
    is slightly overstated (by a few %).
- **The bigger bias is not the points.** Lifeform production and expedition bonuses end up inside the
  economy/research/military scores, and the subtraction cannot remove them.
  - Cross-section of the cell by lifeform share:
    - under 5% lifeform share (n=33): growth 95k
    - 10-20% (n=48): 103k
    - 20-40% (n=41): 131k
    - a log-linear fit puts zero lifeform share at 93k (−6%)
  - That −6% is a lower bound, because every account still keeps the lifeform level bonus and some buildings.
  - Mature accounts: lifeforms make **49% of metal output** for a 5B-point miner (Magma Forge plus 120% lifeform
    tech) and 47% for a fleeter (EN 7257948).
  - Expeditions: lifeforms add about **+70% expedition finds after 4 months** at speed 10 (R jpuvdcw), and reaching
    "~270%" is "not even that expensive" (R kff1dzp).
  - **Estimate for top10 at 6 months:** lifeforms multiply income by **1.3-1.6×**. The lifeform-free counterfactual
    is about **60-75k per speed-day** (p10 about 39k), not 98.6k.
- **Pay-to-win.** A pre-lifeform universe (Sombrero, 2020) had #1 at 79M points and #3 at 19M after one month, both
  "spending thousands of dollars on DM" (EN 7122220; also EN 7140142, and the "metal package meta" in EN 7173605).
  - Pre-lifeform top growth was therefore no lower. Dark matter bought with money is part of the real top10, and
    our data cannot quantify it.
  - These universes are small: 31-176 ranked players, so top10 means 3-15 accounts. One or two paying whales move
    the cell.

**What hds.py should use.** Keep the lifeform-free total, and add a documented lifeform deflator on `growth` only:
divide the real top10 and mid growth p10/p50/p90 by **f = 1.4** (range 1.2-1.6). Shares and counts stay as they are.

- Print the stratum with lifeform share under 5% (n=33 at 128-256) next to it as the empirical floor.
- Mark the band provisional until a pre-lifeform reference exists: Wayback or mmorpg-stat dumps of speed-8
  universes aged 4-8 months, from 2019-21.
- Even with f = 1.6 the real median (≈62k) is **3× the sim's 21k**, so lifeforms are not the main answer.

## 2. Sim against real: top10, expo-a, days 128-256

| | sim expo-a top10 (p50) | real top10, lifeform-free (estimate) |
|---|---|---|
| score at day 192 | 32M (growth 21k) | ≈100-115M after deflator (98.6k×1536 = 151M raw) |
| planets | **10** (in-band 0.10) | 13 (p10 11) |
| mines at day 256 / plasma | 38/31/32, plasma 15 | ~40-45/35-38/36-40, plasma ~20 (EN 7271760, R onr8qb8) |
| class / crawlers | all Discoverer (`class=3`), **0 crawlers**, 7,081 satellites per planet | Collector or Discoverer; crawlers up to +50% (serverData `resourceBuggyMaxProductionBoost` 0.5) |
| mine income at day 192 | **0.16 B/day** (`produced`) | ≈0.4-0.7 B/day without lifeforms |
| expeditions | **28/day**, 6 slots (astro 18 → 4, +2), 0.12 B/day | 80-170/day reported (EN 7194319: 6-7 waves; R kao60c0: 140-170 with 13 slots; R lkl4a7j) |
| raids / loot | 0 raids per day; `loot_in` 3M in total | active fleeters get 0.5-2× production (EXPEDITION-VS-RAID-INCOME §2) |

The real lifeform-free totals need about 100-150B resources spent by day ~190, against 34B for the sim
(17.4B produced + 16.9B from expeditions). Real income at day 190 is therefore about 1-1.5B/day, against 0.28B.

## 3. The three largest missing sources, ranked by expected size

**1. Mine production: about 2.5-4× low (gap about 0.3-0.5 B/day). Confidence: medium.**

The sim's top10 runs 10 planets at 38/31/32 with plasma as the only multiplier: no crawlers, no Collector +25%,
and no geologist.

- Real top10: 13 planets (×1.3), mines 2-6 levels higher (×1.2-1.5), and crawlers, class and plasma together worth
  1.6-2.0× against the sim's ~1.15×.
- The host allows crawlers for every class (Collector up to 150%, `PlanetService.php:1301`), so 0 crawlers is a
  **policy gap**, not a missing rule.

*Check:* run an expo-a arm with `class=1` (Collector) and a crawler fill to cap, and a second arm that targets
astrophysics high enough for 13 planets. Measure top10 `produced`/day at day 192 (target 0.4-0.7 B), planets p50
(target 13), and the mine levels in `levels.json`.

**2. Expedition throughput, not find size: about 2× low without lifeforms, 4-6× with them (gap about 0.1-0.25 B/day
without lifeforms). Confidence: medium on frequency, low on slots.**

- At real find size the sim makes about 4.3M per expedition, which is in line with the real classic formula.
- The sim sends 28 expeditions a day; real top Discoverers send 6-7 waves into 13-15 slots (EN 7270433, EN 7250017).
- Without lifeforms, slots top out at astrophysics (4-5) + Discoverer 2 + Admiral 1 (host `PlayerService::getExpeditionSlotsMax`),
  which is **7-8 slots**. The real 13-15 probably come from lifeform-era sources (unverified) and must not be copied.

*Check:* add an arm with Admiral hired and 6-7 waves a day. Measure expeditions per day (target 45-56), slots used,
`expedition_in`/day, and the depletion messages. Do **not** raise the find size again.

**3. Raids, farming and crash debris: about 15-50× low for fleeter accounts (gap about 0.05-0.3 B/day). Confidence:
low-medium.**

- The sim's top10 send 0 raids a day. The real top10 military share is 0.29, and real universes keep many inactive
  farms (the `dropped` status counts in real-bands.json: I 54.5k, i 2.2k).

*Check:* count the farmable inactive accounts on each sim day and their stock. Run a raider-persona arm and measure
top10 `loot_in`/day against `produced` (target 0.5-2× for fleeters) and the mil_share band.

**Not modelled, and it biases the reference:** dark matter bought with money (resource packages and speed-ups) in
the real top10 (EN 7122220). It cannot be quantified from our data, and with 3-15 accounts per cell it can lift
p50 and p90. Treat the leftover after fixes 1-3 plus the f = 1.4 deflator as the dark-matter share, not as a sim
fault.

**Overall confidence.**
- High: the dataset facts (every universe has lifeforms, the 12% lifeform point share, the sim's levels and
  rates).
- Medium: the ranking and that the production gap is largest.
- Low-medium: the 1.3-1.6× lifeform factor and the slot sources above 8.
