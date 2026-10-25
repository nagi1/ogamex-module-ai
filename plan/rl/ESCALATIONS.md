# Escalations (implementer → planner)

One entry per thing an implementer could not settle inside its row. Format: date, row, what was measured, the
question. The planner answers here or amends `NORTH-STAR-PLAN.md`; nobody edits §1-§7 or §11-§12 of the plan.

---

## 26 Oct — N2 — the §2 counts do not reproduce exactly (−3 / −9 / −25), every distribution number does

**Measured** (`rl/scripts/real_bands.py`, 267 universes, 0 dropped for lack of an alliance date, 15 595 active
players of which 14 172 have a score):

| band | §2 n | N2 n | mil p10/p50/p90 | eco | res | planets | moons |
| --- | --- | --- | --- | --- | --- | --- | --- |
| top10 | 5 617 | **5 614** | .007/.058/.351 = §2 | .275/.500/.737 = §2 | .075/.208/.453 = §2 | 11/16/18 = §2 | 9/15/18 = §2 |
| mid | 6 921 | **6 912** | .015/.161/.514 vs .015/.16/.514 | .343/.613/.870 vs .343/.614/.87 | .043/.129/.294 = §2 | 2/10/14 = §2 | 0/6/14 = §2 |
| bottom | 1 671 | **1 646** | 0/.068/.452 vs 0/.066/.45 | .361/.679/1.0 vs .362/.685/1.0 | 0/.138/.362 vs 0/.136/.361 | 1/2/6 = §2 | 0/0/1 = §2 |

All 24 p10/p50/p90 cells reproduce inside ±0.01 (median of `mil_share` bottom: .068 vs .066). Only `n` differs,
by 0.26 % overall. Definition used: active = `rank_status == ''`; percentile = type-0 position / number of
type-0 positions in the universe (dense, inactives and zero-score players included); band rows require
`total > 0` — including the 1 419 zero-total players moves the bottom band's planet median from 2 to 1, which
is how §2's own table settles it.

**Question:** which `n` does the plan want — every active player (15 595), every active player with a score
(14 172, used), or the planner's own figure (14 209 = 14 172 + 37)? The bands are unaffected; only the weight
`hds.py` gives each cell changes. No action taken on the data.

## 26 Oct — N2 — the OStats activity band has almost no support: 515 of 561 curves are one snapshot

**Measured:** `OSTATS/normalized/score_point.jsonl` holds 561 players × exactly 24 hourly points. In **515** of
them the score is byte-identical across all 24 hours (one capture broadcast over a day). Only **46** curves
vary, and across those 12 903 hour-steps only 440 rise (3.4 %).

Per rank band, share of hours with score growth: top10 p10/p50/p90 = .096/.391/.730 (n=43), mid = .087/.261/.435
(**n=3**), bottom — none. `real-bands.json.activity.series` records both counts.

**Question:** §4 lists activity as a real-band behaviour measure. With 46 usable curves the band exists only
for `top10`, and it cannot separate mid from bottom. Do we (a) use it for `top10` only and leave the other bands
as hard constraints (login spread, dark period), (b) collect more OStats history, or (c) drop the measure?

## 26 Oct — N2 — almost every real player is in one age bucket, and a 60-day season lands inside it

**Measured:** with `speed-days = age × speed`, the occupied cells are `32-64` (127 top10 / 456 mid / 118 bottom)
and `256+` (**5 251 top10 / 5 703 mid / 1 239 bottom**); `0-8`, `8-16` and `16-32` are thin or empty (16, 57,
33 players at top10). PUB's universes are old, so `256+` spans everything from 5 months to 15 years of age.

A 60-day season at speed 8 is **480 speed-days** — inside `256+`, but scored against players whose universes are
many times older. Real growth per speed-day falls with age, so `growth` will read too high for young accounts
compared with that pool.

**Question:** should the buckets be refined near the season length (…`[256,512)`, `[512,1024)`, `[1024,∞)`), or
should `growth` be compared against a fitted age curve (score ∝ speed-days^α) per band instead of a bucket?
This is a §4 matching-rule decision, so it is the planner's.
