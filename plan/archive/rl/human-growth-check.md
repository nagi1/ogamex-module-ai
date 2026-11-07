# Human growth check: our accounts against real OGame players

Date: 25 October 2026. Script: `rl/scripts/human_growth.py` (numpy only), run as
`.venv-rl/bin/python Modules/AI/rl/scripts/human_growth.py` (add `--score-only` to drop held resources from ours).
Ours: closed-loop recordings `storage/rl/m5-eval7/{teacher,policy}` (16 seeds, speed 8, 72 h from a fresh start).
Arms: **planner** = teacher run, every seat; **control** = teacher run, the seats the learner holds in the policy run;
**learner** = policy run, learner seats. Real: OStats hourly history (`OSTATS`) and the public-API snapshot (`PUB-*`),
both 9 Oct 2026. Speed: every rate is points per speed-hour, `(points/h) / economy speed`, and speed-age = age x speed.

## Findings

1. **OStats cannot answer the question as asked.** Of 561 player histories, 30 are active accounts in universes
   with a known speed, all between 1M and 100G points, and each covers one day. None is in an account's first days,
   so table A has no real data in our 1-316 point range and gives no verdict.
2. **The fair comparison is matched speed-age (table C):** universes opened 7.3 days before the snapshot at speed 1
   (`s185-hu`, 7.3 speed-days) and speed 3 (`s178-tw`, 22 speed-days, which is our hour 66 at speed 8).
3. **Planner: slower than real players, inside the band only because the band is very wide.** Its median is 0.21x
   the real median at 7.3 speed-days and 0.72x at 22 speed-days, and about 1/100 of the real p90 at both. Counting
   score only, it drops to 0.05x and 0.55x the median.
4. **Learner: the same as the planner on value, slower on score.** On value/1000 it sits beside its control
   (0.92x vs 0.78x the real median at 22 speed-days). On score alone it is 0.26x vs 0.6x, and below the real p10 at
   7.3 speed-days, because it holds resources instead of spending them.
5. **Table B (rate since start, by points) says "faster than real", but that comes from selection bias.** In a
   week-old universe, the real players still at 10-300 points are late joiners and people who barely play, while
   day-one players have already passed those bins. Do not read B as a verdict.
6. **The real p90 to max range (1.3k-8.7k points at 7.3 speed-days, 7.7k-41k at 22) is the active-human reference.**
   Our best decile (22 and 224 points) is one to two orders of magnitude below it.
7. **The data cannot tell** how the real players spent their first hours (no early trajectories exist), when each
   one joined (universe age is an upper bound on account age, so real rates are understated and the gap is larger
   than shown), or how much of the real lead comes from lifeforms, classes and Dark Matter, which OGameX does not
   model. Also untested: whether points really scale by speed at speeds 1 and 3 against our 8 (research speed and
   fleet speed are separate settings). No speed-8 universe young enough to match our 72 h exists in the data.

## Output (value/1000 = points)

# Human growth check
ours: /home/nagi/code/ogamex-next/storage/rl/m5-eval7 (planner 192 accounts, control 97 accounts, learner 97 accounts), speed 8, points = value/1000 (score + held resources), longest account 72.0 h
OStats series: dropped no speed (universe not in PUB) 19, dropped status I 320, dropped status i 21, dropped status v 27, dropped status vI 122, dropped status vIb 1, dropped status vi 21, kept 30, series 561
fresh universes (<= 100 d, PUB speed known): 29, active players 3421
all rates are points per speed-hour = (points/hour) / economy speed

## A. growth over 23 h, by points at the window start
real: OStats hourly history, active players only, one window per player (8-9 Oct 2026)

| points bin | real n | real p10 / med / p90 | planner n | planner p10 / med / p90 | control n | control p10 / med / p90 | learner n | learner p10 / med / p90 |
|---|---|---|---|---|---|---|---|---|
| 1-3.16 | 0 | - | 1161 | 0.0512 / 0.0687 / 0.149 | 601 | 0.0516 / 0.0713 / 0.142 | 605 | 0.0438 / 0.0701 / 0.127 |
| 3.16-10 | 0 | - | 2430 | 0.0557 / 0.0826 / 0.204 | 1261 | 0.056 / 0.0897 / 0.207 | 1304 | 0.0441 / 0.0623 / 0.183 |
| 10-31.6 | 0 | - | 3320 | 0.059 / 0.154 / 0.333 | 1773 | 0.0627 / 0.158 / 0.355 | 1841 | 0.0364 / 0.201 / 0.465 |
| 31.6-100 | 0 | - | 1153 | 0.223 / 0.428 / 0.733 | 634 | 0.232 / 0.448 / 0.739 | 466 | 0.372 / 0.571 / 0.877 |
| 100-316 | 0 | - | 55 | 0.596 / 1.03 / 1.28 | 25 | 0.649 / 0.996 / 1.19 | 45 | 0.877 / 0.997 / 1.13 |
| 1M-3.16M | 1 | 0 / 0 / 0 | 0 | - | 0 | - | 0 | - |
| 31.6M-100M | 3 | 166 / 830 / 3.26k | 0 | - | 0 | - | 0 | - |
| 100M-316M | 1 | 0 / 0 / 0 | 0 | - | 0 | - | 0 | - |
| 316M-1G | 6 | -37.6 / 1.99k / 9.49k | 0 | - | 0 | - | 0 | - |
| 1G-3.16G | 5 | 4.87k / 11k / 46.8k | 0 | - | 0 | - | 0 | - |
| 3.16G-10G | 9 | 0 / 21k / 282k | 0 | - | 0 | - | 0 | - |
| 10G-31.6G | 3 | 7.38k / 17.3k / 18.2k | 0 | - | 0 | - | 0 | - |
| 31.6G-100G | 2 | 71.3k / 356k / 642k | 0 | - | 0 | - | 0 | - |

- planner: no verdict, no points bin holds both our accounts and >=5 real players
- control: no verdict, no points bin holds both our accounts and >=5 real players
- learner: no verdict, no points bin holds both our accounts and >=5 real players

## B. mean rate since start, P / (age x speed), by current points (biased: real low-points players are the slow ones)
real: PUB snapshot, active players in fresh universes, age = universe age (lower bound); ours from hour 6

| points bin | real n | real p10 / med / p90 | planner n | planner p10 / med / p90 | control n | control p10 / med / p90 | learner n | learner p10 / med / p90 |
|---|---|---|---|---|---|---|---|---|
| 1-3.16 | 1120 | 0 / 0 / 0.000507 | 85 | 0.064 / 0.0644 / 0.0648 | 40 | 0.0639 / 0.0643 / 0.0647 | 40 | 0.0639 / 0.0643 / 0.0647 |
| 3.16-10 | 81 | 0.000676 / 0.00496 / 0.00993 | 2460 | 0.0536 / 0.0603 / 0.07 | 1252 | 0.0541 / 0.0611 / 0.0709 | 1299 | 0.0505 / 0.058 / 0.0688 |
| 10-31.6 | 79 | 0.00334 / 0.0163 / 0.0828 | 4451 | 0.0556 / 0.0654 / 0.108 | 2258 | 0.0555 / 0.0673 / 0.11 | 2537 | 0.0468 / 0.0591 / 0.101 |
| 31.6-100 | 102 | 0.01 / 0.0511 / 0.252 | 3296 | 0.0888 / 0.142 / 0.205 | 1783 | 0.086 / 0.145 / 0.205 | 1279 | 0.0987 / 0.155 / 0.225 |
| 100-316 | 109 | 0.0182 / 0.155 / 0.524 | 999 | 0.219 / 0.303 / 0.438 | 592 | 0.216 / 0.301 / 0.435 | 720 | 0.223 / 0.31 / 0.485 |
| 316-1k | 110 | 0.0663 / 0.409 / 2.17 | 22 | 0.579 / 0.621 / 0.696 | 7 | 0.564 / 0.603 / 0.628 | 24 | 0.59 / 0.617 / 0.667 |
| 1k-3.16k | 175 | 0.204 / 1.16 / 3.01 | 0 | - | 0 | - | 0 | - |
| 3.16k-10k | 167 | 0.616 / 1.68 / 8.13 | 0 | - | 0 | - | 0 | - |
| 10k-31.6k | 194 | 1.68 / 4.95 / 20.7 | 0 | - | 0 | - | 0 | - |
| 31.6k-100k | 210 | 4.39 / 13.8 / 65.4 | 0 | - | 0 | - | 0 | - |
| 100k-316k | 240 | 11.9 / 34.5 / 190 | 0 | - | 0 | - | 0 | - |
| 316k-1M | 233 | 30.4 / 84.4 / 481 | 0 | - | 0 | - | 0 | - |
| 1M-3.16M | 171 | 84.2 / 248 / 938 | 0 | - | 0 | - | 0 | - |
| 3.16M-10M | 222 | 326 / 933 / 1.55k | 0 | - | 0 | - | 0 | - |
| 10M-31.6M | 146 | 1.21k / 2.14k / 3.54k | 0 | - | 0 | - | 0 | - |
| 31.6M-100M | 45 | 2.07k / 3.11k / 5.59k | 0 | - | 0 | - | 0 | - |
| 100M-316M | 15 | 6.31k / 8.37k / 24.7k | 0 | - | 0 | - | 0 | - |
| 316M-1G | 1 | 48.2k / 48.2k / 48.2k | 0 | - | 0 | - | 0 | - |
| 1G-3.16G | 1 | 63.4k / 63.4k / 63.4k | 0 | - | 0 | - | 0 | - |

- planner: within the real p10..p90 band (4 of 6 bins inside, 2 above p90, 0 below p10), median 3.3x the real median (geo-mean over bins)
- control: within the real p10..p90 band (4 of 6 bins inside, 2 above p90, 0 below p10), median 3.3x the real median (geo-mean over bins)
- learner: within the real p10..p90 band (4 of 6 bins inside, 2 above p90, 0 below p10), median 3.3x the real median (geo-mean over bins)

## C. points at matched speed-age (age x speed): fresh universe vs ours at the same speed-hours

real = active players with points > 0 (accounts that registered and never built are left out)

| universe | speed | age d | speed-days | our hour | real n | real p10 / med / p90 / max | planner p10 / med / p90 (med at real pct) | control p10 / med / p90 (med at real pct) | learner p10 / med / p90 (med at real pct) |
|---|---|---|---|---|---|---|---|---|---|
| s185-hu | 1 | 7.3 | 7.3 | 22 | 73 | 3 / 57 / 1.33k / 8.67k | 9.68 / 12.2 / 22.2 (p23) | 9.81 / 12.4 / 21.4 (p23) | 8.76 / 13.8 / 19.8 (p23) |
| s178-tw | 3 | 7.3 | 22.0 | 66 | 61 | 3 / 127 / 7.66k / 41k | 34.1 / 91.8 / 224 (p38) | 35.2 / 99.5 / 217 (p41) | 25.7 / 116 / 249 (p46) |

Verdict (table C, matched speed-age; the only fair real comparison in our points range):
- planner: s185-hu: within p10..p90, 0.21x the real median, 0.0091x the real p90; s178-tw: within p10..p90, 0.72x the real median, 0.012x the real p90
- control: s185-hu: within p10..p90, 0.22x the real median, 0.0093x the real p90; s178-tw: within p10..p90, 0.78x the real median, 0.013x the real p90
- learner: s185-hu: within p10..p90, 0.24x the real median, 0.01x the real p90; s178-tw: within p10..p90, 0.92x the real median, 0.015x the real p90
(fresh universes older than our run's 72 h at speed 8 are not compared)

What the data cannot tell:
- OStats holds one day (8-9 Oct 2026) for a sample that is mostly inactive or vacation accounts in old universes; no player's first days are in it, so table A has no real growth below the bins printed.
- PUB is a single snapshot: table B/C are cross-sections, not trajectories; a late joiner looks slow, so real rates there are lower bounds and the upper quantiles stand for day-one starters.
- Real universes run v13 with lifeforms, classes and Dark Matter purchases, and research speed can differ from economy speed; the PUB 'speed' field is economy speed only. Points/speed is a first-order normalisation.
- Ours count held resources in value/1000; --score-only removes them. Real scores never count held resources.

## Table C with `--score-only` (held resources excluded, as a real highscore counts)

## C. points at matched speed-age (age x speed): fresh universe vs ours at the same speed-hours

real = active players with points > 0 (accounts that registered and never built are left out)

| universe | speed | age d | speed-days | our hour | real n | real p10 / med / p90 / max | planner p10 / med / p90 (med at real pct) | control p10 / med / p90 (med at real pct) | learner p10 / med / p90 (med at real pct) |
|---|---|---|---|---|---|---|---|---|---|
| s185-hu | 1 | 7.3 | 7.3 | 22 | 73 | 3 / 57 / 1.33k / 8.67k | 0 / 3 / 10.4 (p12) | 0 / 3.36 / 10 (p12) | 0 / 2.04 / 5.29 (p10) |
| s178-tw | 3 | 7.3 | 22.0 | 66 | 61 | 3 / 127 / 7.66k / 41k | 18.2 / 70.4 / 189 (p34) | 17.6 / 76 / 187 (p34) | 12.7 / 33.4 / 94.3 (p31) |

Verdict (table C, matched speed-age; the only fair real comparison in our points range):
- planner: s185-hu: within p10..p90, 0.053x the real median, 0.0022x the real p90; s178-tw: within p10..p90, 0.55x the real median, 0.0092x the real p90
- control: s185-hu: within p10..p90, 0.059x the real median, 0.0025x the real p90; s178-tw: within p10..p90, 0.6x the real median, 0.0099x the real p90
- learner: s185-hu: below p10, 0.036x the real median, 0.0015x the real p90; s178-tw: within p10..p90, 0.26x the real median, 0.0044x the real p90
(fresh universes older than our run's 72 h at speed 8 are not compared)
