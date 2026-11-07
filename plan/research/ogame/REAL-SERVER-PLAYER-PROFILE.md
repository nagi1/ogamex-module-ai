# What players on a real OGame server look like, and how far our simulator is from it (6 Nov 2026)

Read-only research. No numbers are invented: each real value cites a dataset, a research note or a post id, and each
"ours" value names the run and how it was computed. Where nothing was found, the entry says **NO SOURCE**.

## Sources and method

**Real data (local, first).**
- **PUB**: Gameforge public API dump of **267 live universes**, captured 2026-10-09
  (`Modules/AI/storage/rl/external/sources/PUB-*/raw/`). Files used: `players.xml` (status, alliance), `alliances.xml`
  (members, foundDate), `highscore-1-{0,1,2,3}.xml` (total, economy, research, military, and the `ships` count in the
  military list), `highscore-2-0.xml` (alliance points), `universe.xml` (every planet, moon and moon size) and
  `serverData.xml`.
  - Speeds: 196 universes at speed 8 and 34 at speed 10, the rest mixed.
  - Communities: mostly small ones (hu, ro, nl, pt, tw, br, cz …). en/de/es are 9 of the 267.
  - All 267 are lifeform universes (TOP10-GROWTH-SOURCES.md §1).
- **Age** is the OSTATS `opened` date (`OSTATS/normalized/universe_catalogue.jsonl`, 264 of 267 matched), else the
  oldest alliance `foundDate`.
- **Points** are lifeform-free, as in `rl/scripts/real_bands.py` `lifeform_free()`: `min(total, eco+res+mil)`.
  - This removes the lifeform points (median 8-12% of a top10% account here), but not lifeform-boosted income.
    TOP10-GROWTH-SOURCES.md suggests deflating growth by f = 1.4 (range 1.2-1.6).
- **Bands** are ranks among all ranked accounts, inactives included, because that is what the highscore shows:
  - rank 1, ranks 2-10, top 1%, top 10%, "median" = ranks 45-55%, bottom = 90-100%.
  - "Active median" = ranks 40-60% among active accounts only.
  - **Active** means a status with none of i, I, v, b, a (DIPLOMACY-BETRAYAL.md §0).
- **Reference cell** = speed 8, universe age 128-256 days (n=27 universes, median age about 190 d), with 256-512 d
  (n=59) next to it. Every cell below is the median across universes, or across accounts for per-account traits.
- Other real sources:
  - OGMEM battle archive (599 reports), via LATE-GAME-FLEET-STORE.md.
  - Forum and Reddit corpus: `storage/rl/external/sources/{FORUM,REDDIT,board_*}/normalized/*.jsonl`. Ids are cited
    as `EN/DE/FR/US <post_id>` or `reddit <id>`.
  - The research notes in this folder.
- The 70-universe en/de/es snapshot of 2026-11-04/05 (DIPLOMACY-BETRAYAL.md) is **not on disk**, so its figures are
  quoted, not recomputed.

**Ours.**
- **T** = teacher seasons `storage/rl/ogsim/runs/err4-side/teacher-held/ready-11..30.jsonl`: 20 seeds, 64 accounts.
- **B** = 1000-account worlds `storage/rl/ogsim/runs/big1000/world-{7,8,9,10}-d256.jsonl`.
- Both run at speed 8 for 256 days. Values are read from the day rows, plus `.levels.json` for units, levels and
  positions.
- Per-day rates are the mean over days 129-256, or over the 30 days before the measured day.
- Alliance comes from the day rows, because `levels.json` `alliance` is buggy in big1000.
- Sim "day 256" = 2,048 speed-days. That is comparable to a real speed-8 universe of 128-256 real days, where 1 real
  day = 8 speed-days at speed 8 (`strength_ladder.py`).

The analysis scripts were ad hoc and read-only. They live in the session scratchpad and are not committed. The method
above is enough to redo them: one regex pass over the raw XML, and one `json.loads` pass over the day rows.

---

## Master table

The table is ranked by gap size × how much the trait shapes play. **Gap** is ours ÷ real, or the difference;
↑ means ours is too high, ↓ too low.

| # | Trait | Real value (band/age, source) | Ours (run, how) | Gap & direction |
|---|---|---|---|---|
| 1 | **Raid income** (loot ÷ own mine production) | Active raider 0.5-2×, casual 0.2-0.5× (EXPEDITION-VS-RAID-INCOME.md §2; EN 7253607, 7195518, 7148857) | Raider persona 0.017 (T) / 0.023 (B). Top10% 0.000 (sum of `loot_in` ÷ sum of `produced`, days 129-256) | **↓ 20-90×**. Raiding does not pay, so the raider/miner balance cannot form |
| 2 | **Farm supply**: inactive (i/I, not v) accounts and their size | Per active player at 128-256 d: 1.05 inactives. Those >100k pts: **0.42** (256-512 d: 0.95). Those >1M pts: 0.15 (0.31). Inactives sit at ranks 45-55% in 71% of cases (PUB) | Exactly 1.0 inactives per active. Those >100k pts: 0.20 (T) / 0.23 (B). Those >1M: 0.03 / 0.06. The quitters stop at day 18 (median) / 41-43 (p90) and stay small (median 7-8k pts) | ↓ 2-5× for big farms, worse later. No farms appear after day ~45 |
| 3 | **Raids and spying per day** (active raider) | Farmers: 50-150 CRs a day and 200-500 spy reports a day. Players get probed 20-50 times a day (LATE-GAME-TACTICS-USAGE.md §4; FORUM 7084989, 7230473, 7275523; reddit ib0xj4o) | Raider persona: 9.6 (T) / 17 (B) `raid_sent` a day, 3.1 / 3.9 `spy_sent` a day. Miners and savers: 0 spies, 0.02-0.14 raids | ↓ 5-10× raids, ↓ 50-100× probes |
| 4 | **Alliances**: count, size and concentration | Universe at 128-256 d: 26 alliances with ≥1 member, **2** with ≥2 active members, 72% are one-member tags. Largest alliance holds **19%** of active players (256-512 d: 30%). Top alliance holds **49%** of all points (53% of alliance points); top 3 hold 86%. Of the top 10 players, at most 4 are in one alliance (PUB) | **One** alliance holding 84% (T) / 82% (B) of active accounts and **95% / 92% of all points**. All top 10 are in it | ↑ about 4× concentration. There are no rival alliances, and the war/NAP/pact structure has no counterpart |
| 5 | **Alliance membership by band** | Top10%: 100%. Active median: 100% at 128-512 d (mean 57% at ranks 45-55%). Bottom: 0%. Active players overall: 62-67% (PUB; DIPLOMACY §1: 98/93/74/63/40% by rank band in older en/de/es universes) | Top10%: 100%. Ranked median: 0%. Active median: 86% (T) / 83% (B) | Shape OK. The sim's ranked median is 0 because it is an active non-member, while the real ranked median is an inactive (see #2) |
| 6 | **Churn and quitting over server age** | Active share of accounts: 0.89 (<30 d) → 0.29 (30-128 d) → 0.27 → 0.15 → 0.09 (512+). Only 12% of all account ids ever issued survive at 128-256 d (PUB). DE census: active players fall every month, to 38% of month 1 at month 6 and 33% at month 8 (DE 3427329…3489702, thread 183222) | Active share 1.0 to day 7, 0.83 on day 14, 0.61 on day 30, then **flat at 0.50** from about day 45 to day 256 (T, B) | No quitting after day ~45. Real worlds keep losing about 10-20% of actives a month |
| 7 | **Late inflow** (new accounts) | New account ids per day: 27 (<30 d), 4.0 (30-128 d), 3.4 (128-256 d), 2.0 (256-512 d) (PUB, `max(id)−100000` ÷ age). These late joiners are the "bottom" and the new farms | 0. All accounts start on day 1 | Missing mechanic. The bottom band and the newbie-protection dynamics are absent |
| 8 | **Vacation mode** | 41% of accounts at 128-256 d (44% at 256-512 d). **20%** of the top 10% (p50; 33% at 256-512 d). The median vacation account has 5.3M pts (PUB). DE census: 43% of all DE accounts (DE 3452393) | 0 (not modelled) | Missing. A large part of the real top cannot be attacked or produce at any moment |
| 9 | **Dominance**: points share and spread | At 128-256 d: rank 1 holds **16.5%** of all points (34% at 256-512 d), top 10% hold 74% (82%), Gini 0.84 (0.89). Rank 1 ÷ rank 10 = 5.5 (20.6) (PUB, lifeform-free) | T: rank 1 holds 10.5%, top 10% hold 57%, Gini 0.78, r1/r10 8.3. B: rank 1 holds **0.7%**, r1/r10 **1.09**, r10/r100 1.26 | T is about right, slightly flat. B ↓ heavily: 400 clone savers tie at the top |
| 10 | **Spread inside the top 10%** (p90 ÷ p10 of points) | 4.5× (192-256 d: 9.1e7…4.1e8). 3.4× at 128-256 d (PUB) | 1.3× (T day 256: 1.27e8…1.64e8; B 1.22×) | ↓. Our top accounts are homogeneous, with no whales and no weak top players |
| 11 | **Rank 1 strength at age** | About 210 d (192-256 d, n=20): rank 1 3.7e8, top10% p50 1.7e8, rank 10 8.7e7. Deflated by f=1.4: 2.7e8 / 1.2e8. Per speed-day at 128-256 d: rank 1 1.8e5 (PUB) | Day 192 (= about 192 d at speed 8): rank 1 1.04e8 (T) / 1.20e8 (B), top10% 0.98e8 / 1.04e8. Day 256: rank 1 1.63e8 / 1.86e8, 7.9e4-9.1e4 per speed-day | Top 10% about 0.6-0.85× real (in band after the deflator). **Rank 1 ↓ 2.3-3.6×** |
| 12 | **Stock held** (hours of own production) | Unprotected stock ≤24-48 h. Hoards exist only while saving for a named target, and they fly or hide (LATE-GAME-FLEET-STORE.md Q2; EN 737333, WIK-028/038). Total hoard size: NO SOURCE | Top10%: **2,080 h (T) / 2,400 h (B)**, about 87-100 days. Active median: 970 / 1,240 h. Computed as `stock` ÷ (`produced` ÷ 24) | ↑ about 40-100× against the exposure rule. Nobody raids it (#1), so hoarding is free |
| 13 | **Military share by age** (top 10%) | 0.48 (<30 d) → 0.34 (30-128 d) → 0.30 → 0.25 → **0.08** (512+). Active median 0.23 (128-256 d), 0.17 (256-512 d) (PUB) | Top10% 0.28-0.30 flat on days 128-256. Active median 0.08 (T) / 0.11 (B) | Level OK at 128-256 d. The early peak and the late decline are missing. Mid accounts are ↓ 2-3× military |
| 14 | **Expeditions** (per day, and ÷ mine income) | Active Discoverer: about 45 a day (6-7 waves; EN 7194319, 7195518). Resources about **1.1-1.6×** mines (EXPEDITION-VS-RAID-INCOME.md §1). Collector or General finds are 12× smaller | Saver (Discoverer): 24-26 a day, `expedition_in` ÷ `produced` **2.4-2.6**. Miner and raider: 13-15 a day, 0.11-0.16 | Discoverer: ↓ about 2× in frequency, ↑ about 2× in yield per mine unit. Non-Discoverers OK |
| 15 | **Class mix** | API has no class field: **NO census**. Anecdotes: about 75% to "99%" Discoverer on new universes (FR 12158797, FR 12040901). Top 10/20 "mostly General" in some universes (EN 7194331, reddit irdguzp) | Population 40/30/30 Collector/General/Discoverer by persona. **Top 10% = 100% Discoverer (saver)** in T and B | Unmeasured. The sim's top is single-class, which no source supports |
| 16 | **Hull count and value per hull** (top 10%) | `ships` 2.6M (128-256 d), 2.4M (256-512 d). Military points per ship **16.9-18** (PUB highscore type 3; whether `ships` counts satellites and probes is not documented) | 6.0M (B) / 5.0M (T) hulls, about 2M of them probes. 83k satellites. Military points per hull 7.7-8.1 | ↑ about 2.2× hulls, ↓ about 2.2× value per hull. Fodder- and probe-heavy (LATE-GAME-FLEET-STORE.md Q3: BC+cruiser 60-80% vs ours about 20%) |
| 17 | **Deathstars** | 93-95% of defenders hold a DS at fleet value 1e10-1e11, median 11-27 (OGMEM, LATE-GAME-FLEET-STORE.md Q1) | Top10% fleet value 4.6-5.3e10. **52% (B) / 61% (T)** hold a DS, median 14 | ↓ about 35 pp in presence. Count OK |
| 18 | **Fleet-save habit** | Active players fleetsave nightly or more often (5-14 h saves) (US 657801, EN 7013859, EN 7172953, US 652251; EN 737333 "at least once a day") | `save_ok`+`save_late` only when raided: 0 a day (raider, miner), 0.08-0.28 a day (saver) | ↓. Saves are reactive, not routine |
| 19 | **Logins per day** | Spread from 1-3 a day (casual, Collector "5 min a day", EN 7238577, reddit lykual1) to every 20-30 min for top fleeters (EN 6983439, reddit jcbgf70). OSTATS snapshot: 13% of active accounts online at once (128-256 d) | About 9 a day for **every** active persona (T, B) | Variance ↓. There are no casual or no-life tails |
| 20 | **Population size** | Speed-8 universes in small communities: 181 accounts and 170 active at <30 d, **84 / 24** at 128-256 d, 74 / 10 at 256-512 d (PUB). en/de/es: about 247 active per universe (DIPLOMACY §1, 17,289 ÷ 70). DE classic universes 2-3k active at month 1 (DE thread 183222) | T: 64 accounts, 32 active. B: 1000 accounts, 500 active | T matches small communities. B matches only a big-community start (month 1-2) |
| 21 | **Dark matter at account start** | `darkMatterNewAcount` 8,000 (speed-8 128-512 d), 25,000 in new universes (PUB serverData) | 3,000 on day 1 (day rows `dark_matter`) | ↓ 2.7-8× |
| 22 | **Home and colony slot** (top 10%) | Position 8: **68%**, 9: 10%, 7: 8% (PUB `universe.xml`, 128-256 d). All ranked players: position 8 25% (`real-bands.json` `positions`) | Position 5: 48-56%, 7: 23-26%, 8: 20-25% (`levels.json` top10%) | Shape differs. Real top players stack slot 8 |
| 23 | Planets (top10% / active median) | 13 / 9 (128-256 d); 13 / 10 (256-512 d). Rank 1: 14-15 (PUB) | 12 / 9 (T, B day 256). Rank 1: 12 | ≈. Rank 1 ↓ by 2-3 planets |
| 24 | Moons (top 10%) | 12 of 13 planets. Moon size median **8,640** km, close to the 20%-chance maximum (PUB) | 12 of 12, from combat moon rolls (T seed 11: 170 `moon_made`, 0 `moon_shot`) | ≈ in count |
| 25 | Score split (top 10%) | eco 0.55 / res 0.12 / mil 0.30 (128-256 d) (PUB) | 0.57 / 0.13 / 0.30 (T, B) | ≈ |
| 26 | Research points (top10% / rank 1) | 1.8e7 / 4.5e7 (128-256 d); 2.4e7 / 1.5e8 (256-512 d) (PUB) | 1.95e7 / 2.1e7 (T, B) | Top 10% ≈, rank 1 ↓ 2-7× |
| 27 | Galaxies spanned | Top10% 5-6, ranked median 2, active median NO split (PUB) | Top10% 6, ranked median 6 (T, B) | ↑ for mid accounts: the sim spreads every account across 6 galaxies |
| 28 | Banned accounts | 4.5% flagged b (128-256 d), 12% at 512+ (PUB). DE census 0.1-1% (DE 3181594) | 0 | Cosmetic |
| 29 | Mines and research levels (top 10%) | No API. Snapshots without a known age: 39/32/34 (EN 6604615), 36/32/32 on 11 planets (EN 6635724), 48/38/42 Collector (DE 3901296). Estimate at about 6 months 40-45/35-38/36-40, plasma about 20 (TOP10-GROWTH-SOURCES.md §2) | Per-planet median 39/32/34. Plasma 16-17, astro 21-22, weapons/shields/armour 19/20/19, graviton 1 | ≈ on mines. Plasma ↓ about 3 levels. Age-tied real data: NO SOURCE |

---

## Details by area

### A. Who is on a real server (population and status), speed 8

Status shares of all non-admin accounts, median across universes (PUB):

| age (d) | n univ | accounts | active | active share | i | I | v | b | new ids/day | survivors of ids |
|---|---|---|---|---|---|---|---|---|---|---|
| <30 | 5 | 181 | 170 | 0.89 | 0.01 | 0 | 0.10 | 0 | 26.8 | 0.99 |
| 30-128 | 16 | 93 | 28 | 0.29 | 0.23 | 0.36 | 0.41 | 0.02 | 4.0 | 0.21 |
| 128-256 | 27 | 84 | 24 | 0.27 | 0.14 | 0.50 | 0.41 | 0.04 | 3.4 | 0.12 |
| 256-512 | 59 | 74 | 10 | 0.15 | 0.08 | 0.71 | 0.44 | 0.05 | 2.0 | 0.10 |
| 512+ | 89 | 173 | 17 | 0.09 | 0.05 | 0.82 | 0.38 | 0.12 | 1.9 | 0.11 |

How to read the table:
- Flags overlap: vI is counted in both v and I.
- Accounts that are deleted (dead accounts are purged) vanish from the API. That is why only 10-21% of the ids ever
  issued are still listed after the first month.
- These are small-community universes. The en/de/es snapshot has about 247 active players per universe
  (DIPLOMACY §1).
- Classic DE universes had 2-3k active in month 1 and kept 33-44% of that at months 6-8 (DE thread 183222:
  s138 3,138 → 1,043, s139 2,256 → 1,055).
- **Implication.** The sim's 64-account world is the right size for a small community at 4-8 months. A 1000-account
  world needs churn and late inflow to look like any real age.

### B. Dominance and score shape (lifeform-free, speed 8)

| age (d) | rank 1 | rank 10 | ranked median | r1/r10 | r1/median | rank-1 share | top10% share | Gini | rank 1 per speed-day |
|---|---|---|---|---|---|---|---|---|---|
| <30 | 4.2e6 | 6.1e5 | 12 | 6.9 | 3e5 | 0.20 | 0.94 | 0.93 | 7.6e4 |
| 30-128 | 1.3e8 | 2.5e7 | 6.2e5 | 7.0 | 252 | 0.20 | 0.71 | 0.84 | 1.6e5 |
| 128-256 | 3.2e8 | 7.6e7 | 1.3e6 | 5.5 | 247 | 0.17 | 0.74 | 0.84 | 1.8e5 |
| 256-512 | 6.5e8 | 2.4e7 | 7.9e5 | 20.6 | 826 | 0.34 | 0.82 | 0.89 | 2.2e5 |
| ours T d256 | 1.6e8 | 1.9e7 | 2.0e6 | 8.3 | 75 | 0.105 | 0.57 | 0.78 | 7.9e4 |
| ours B d256 | 1.9e8 | 1.7e8 | 4.1e6 | 1.09 | 45 | 0.007 | 0.60 | 0.78 | 9.1e4 |

How to read the table:
- The real rank-10 point at 256-512 d is low because those universes have only about 10 active players.
- The real "median" is an inactive account (71% of ranks 45-55% are i/I). Ours is an active account.
- Rank 100 exists in only 9 of the 27 universes at 128-256 d (r10/r100 median about 7,000, meaning rank 100 is a dead
  account). B's r10/r100 is 1.26.
- **Implication.** Real servers have one or two runaway accounts (whales, including paid DM, EN 7234677, 7249930) and
  a long dead tail. B has neither. T has the tail but no runaway.

### C. Planets, moons, slots and fleet (top 10%, speed 8; PUB unless noted)

| age (d) | planets r1 / top10% / median | moons r1 / top10% / median | ships top10% | mil pts per ship | research pts top10% |
|---|---|---|---|---|---|
| 30-128 | 13 / 11 / 6 | 12.5 / 10 / 1 | 1.0e6 | 14.5 | 4.6e6 |
| 128-256 | 14 / 13 / 7.5 | 13 / 12 / 2 | 2.6e6 | 16.9 | 1.8e7 |
| 256-512 | 15 / 13 / 7 | 15 / 13 / 2 | 2.4e6 | 18.0 | 2.4e7 |
| 512+ | 17 / 15 / 5 | 17 / 15 / 1 | 1.7e6 | 35.8 | 2.7e8 |
| ours T/B d256 | 12 / 12 / 6 | 12 / 12 / 1 | 5.0e6 / 6.0e6 | 8.1 / 7.7 | 2.0e7 |

- Sim top10% fleet value share (B `levels.json`, base prices):
  - About 10-11% each for HF, cruiser, BS, LC, SC.
  - BC 7.8%, PF 8.1%, reaper 7.7%, destroyer 7.5%, bomber 6.2%, LF 4.5%, probes 3.9%, DS 0.3%.
  - Satellites are about 7k per planet.
  - OGMEM attackers at 1e10-1e11 hold BC 52% and cruiser 24% (LATE-GAME-FLEET-STORE.md Q3). Ours is a flat mix and
    probe-heavy.
- Crawlers: 0 in our top 10% (T, B). Real defenders hold crawlers on 3.5% of OGMEM sides, all below 1e8
  (LATE-GAME-TACTICS-USAGE.md §6).

### D. Income and daily actions (active accounts, days 129-256, mean per account-day)

| persona (class) | raids sent | spies sent | expeditions | loot ÷ prod | expo ÷ prod | logins | fights | phalanx |
|---|---|---|---|---|---|---|---|---|
| raider (General) T / B | 9.6 / 17.2 | 3.1 / 3.9 | 12.7 / 13.1 | 0.017 / 0.023 | 0.16 / 0.16 | 9.0 / 9.1 | 10 / 18 | 5.2 / 8.6 |
| miner (Collector) T / B | 0.03 / 0.11 | 0 / 0 | 14.2 / 15.0 | 0.0005 / 0.0007 | 0.11 / 0.12 | 8.9 / 9.1 | 0.7 / 0.8 | 0 |
| saver (Discoverer) T / B | 0.02 / 0.14 | 0 / 0 | 24.2 / 25.9 | 0.0004 / 0.0002 | 2.43 / 2.62 | 9.0 / 9.1 | 0.6 / 0.8 | 0 |

The real side, with the per-type income table, is in EXPEDITION-VS-RAID-INCOME.md §2:
- Active raider: loot 0.5-2× own production.
- Active Discoverer: about 45 expeditions a day, resources 1.1-1.6× mines (plus ship finds).
- Collector: mines 70-90% of income.
- Spy reports: 200-500 a day for farmers (LATE-GAME-TACTICS-USAGE.md §4).
- Miners probing a few times before a save: NO SOURCE for a number.

Notes on the sim columns:
- Miners and savers never send a spy in T or B (`spy_sent` = 0). Under the no-cheating rule, their raids, which run at
  0.02-0.14 a day, must still read a report from somewhere. LATE-GAME-TACTICS-USAGE.md §4 already flags this.
- Our sim gives every active persona the same login count (about 9 a day). Idle hours differ by persona: saver 0.4 h,
  miner 7 h, raider 12 h.

### E. Alliances (PUB, speed 8, median per universe)

| age (d) | alliances with ≥1 member | with ≥2 active | with ≥5 active | one-member tags | largest (members, all statuses) | largest share of actives | actives in any alliance | top alliance share of all pts | top 3 share of alliance pts |
|---|---|---|---|---|---|---|---|---|---|
| <30 | 31 | 7 | 2 | 0.80 | 11 | 0.05 | 0.26 | 0.21 | 0.73 |
| 30-128 | 27 | 3 | 0 | 0.75 | 10 | 0.16 | 0.60 | 0.35 | 0.83 |
| 128-256 | 26 | 2 | 1 | 0.73 | 9 | 0.19 | 0.67 | 0.49 | 0.86 |
| 256-512 | 21 | 1 | 0 | 0.71 | 6 | 0.30 | 0.70 | 0.58 | 0.90 |
| ours T / B | 1 | 1 | 1 | 0 | 27 / 410 | 0.84 / 0.82 | 0.84 / 0.82 | 0.95 / 0.92 | 1.0 |

Notes:
- Real top-10 players spread over alliances, with at most 3-4 of them in any single one.
- Real alliances that last are founded by day 23.5 (median), with 57% inside 30 days. 4,355 alliances have an active
  member: median 1 active, p90 5, p99 20 (DIPLOMACY §1, en/de/es).
- Pacts and wars are not in the API: **NO SOURCE** for counts or durations (DIPLOMACY §2).

### F. Server settings that differ from ours (PUB serverData, speed 8, 128-256 d)

| setting | real | note |
|---|---|---|
| debris factor | 0.3 (median), range 0.3-0.8 | |
| fleet war speed | 2 (p10 1, p90 4.4) | |
| galaxies | 6 (5-7) | |
| bash limit | 6 attacks a day | applies in 68 of 70 universes (DIPLOMACY) |
| dark matter for a new account | 8,000 (25,000 in universes <30 d) | |
| starting class bonus | all have `characterClassesEnabled=1` | |

Our sim's debris, fleet speed and bash settings were not checked in this note. Open item O7.

---

## Open items: no source covers these

- **O1 Class distribution.** The public API has no class field, and no poll or staff figure exists (forum search,
  this session). The only route is to collect class from `playerData.xml` per player, or from galaxy-view icons. Not
  done.
- **O2 Officers and paid dark matter share.** NO SOURCE. Anecdotes only: "3-4 players spending €1000 a universe"
  (EN 7234677), "€50/month normal payer" (EN 7249930). Not modelled; it inflates real rank 1 (#11).
- **O3 Login pattern distribution.** Only anecdotes (§D) and one OSTATS online snapshot (13% of actives online at
  once). The OSTATS hourly score curves (`real-bands.json` `activity`: top10 p50 0.39 share of hours with score
  growth, n=43) could be matched against a sim "hours with spend" measure. Not computed.
- **O4 Individual alliance switching, kick rate, NAP and war counts.** These need a second `players.xml` snapshot
  (DIPLOMACY §1 method). The 2026-10-09 dump here and the 2026-11-04/05 en/de/es dump do not overlap in universes on
  disk.
- **O5 Total hoard size, the day of the first DS, weeks of saving for a big research.** NO SOURCE
  (LATE-GAME-FLEET-STORE.md gaps).
- **O6 Mine, research and terraformer levels tied to universe age.** The API does not expose them. Forum snapshots
  have no age (#29). Planet fields: NO SOURCE.
- **O7 Sim server settings** (debris factor, fleet speeds, bash cap, newbie protection) vs the serverData table in §F.
  Not checked here.
- **O8 What highscore `ships` counts** (satellites, crawlers and probes in or out). Not documented. #16 assumes it
  counts all ships.
- **O9 Lifeform bias.** All 267 universes are lifeform universes. Point totals are corrected; income and fleet are not
  (f = 1.2-1.6, TOP10-GROWTH-SOURCES.md §1). Shares and counts (alliances, statuses, planets, moons) are not affected.
- **O10 Honour and bandit prevalence.** NO SOURCE. The API highscore type 7 (honour) was not captured. Next dump:
  add `highscore.xml?category=1&type=7`.

## Suggested order to close the gaps (testable; no code changed here)

1. **Farm and status population (#2, #6, #7, #8).**
   - Accounts quit throughout the season, keeping their planets and mines and staying raidable.
   - About 40% of accounts are in vacation mode at any time.
   - About 3 new accounts a day arrive per 100.
   - Target at day 128-256: active share about 0.27, inactives >100k pts ≥0.4 per active, vacation 0.4.
2. **Raid economics (#1, #3, #12).** Once farms exist, check that raider loot ÷ production reaches 0.5-2 and that the
   top10% exposed stock falls toward ≤48 h.
3. **Alliances (#4).** Several alliances. Target: largest ≤20-30% of actives, top alliance about 50% of points, and
   top 10 players spread with at most 4 per alliance.
4. **Spread at the top (#9, #10, #11).**
   - Vary strength and persona inside the top. The "saver = Discoverer = top" coupling makes the top 10% one clone
     (#15).
   - Target: top10% p90/p10 ≥3, rank-1 share 0.15-0.35.
5. **Fleet shape (#16, #17), slot 8 (#22) and start DM (#21).** Smaller, but they move combat and expedition maths.
