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

## 26 Oct — N4/N5 — the first reading exists, three of its four measures are the stub

**Measured** (N3a seasons, 3 seeds × 64 accounts × 60 days at speed 8; `hds.py`):

- HDS **0.773** over 4 measures (planets, moons, galaxies, alliance) — the score shares and growth are
  excluded because N3a writes `score_source: "stub"`, and comparing a made-up split against real players
  would invent a distance.
- Turing AUC **0.912** (pooled by cell 0.882). Giveaways: **planets** (48 % of account-measures out of band),
  alliance, moons.
- Hard constraints: 44 weekly windows with under 18 hours touched, 1 013 dark periods ≥ 6 h, 0 actions
  under 10 s.
- 3 of 9 cells have **no real counterpart**: every-10th-day snapshots (days 10-50) land at 80-400 speed-days,
  and the published universes populate only `32-64` and `256+`. So growth is only scored on the last day.

**Questions:**
1. The every-10th-day growth snapshots (192 of 576 account-rows) are unscorable against real data. Do we drop
   them, or does N2 need finer buckets (see the third N2 escalation)?
2. §4 says "a dark period shorter than 6 h" is a hard constraint with 0 violations. Read literally, an account
   must never be dark 6 h — which no sleeping human satisfies (our scripted players are dark ≥ 6 h 1 013
   times). What is the intended rule? `hds.py` currently reports it and gates only on the two exact
   constraints (login hours, 10 s actions).
3. The reading is counts-only until N1 lands. Is that acceptable for N5's D-answers, or does N5 wait for N1?

## 26 Oct — N5 — the reading is ready, and it says the teacher is the whole problem

**Reading** (§8): HDS **0.686**, Turing AUC **0.990** over 8 measures with the host's own scores, 3 seeds ×
64 accounts × 60 days at speed 8. Giveaways in order: **planets** (48 % out of band), res_share (68 %),
alliance (9 %), mil_share (52 %), growth (20 %).

D1-D4 are answered in §8 with numbers. D5 is answered from the §9.1 event counters only (every account: 0
colonies, 0 expeditions, 0 missiles, 0 moon shots, 0 recycles, 1 planet, 0 moons); the catalogue-wide object
enumeration through `reach.py` was **not** done — that is a half-hour follow-up if the planner wants the full
object list rather than the missions.

**Three things the planner has to decide before N6 starts:**
1. **N6d is not fifth.** The giveaway order puts the *research/military split* second (res_share out of band on
   68 % of measures, mil_share on 52 %), before alliance and growth. The plan's expected order (colonise,
   expeditions, moons, persona mix) should probably become **colonise → persona mix (the share split) →
   expeditions → moons**, unless the planner has a reason to keep it.
2. **Growth is not 0.21x, it is 187x-1029x below the band median at day 60** (ours p50 135-249 vs 46 709 and
   138 909). §2's 0.21x was measured at 7.3 speed-days; the gap widens with age, so colonising is not a small
   fix but the only one that can close a 3-order-of-magnitude gap.
3. **The plan says stop after N5.** It is stopped here, before any N6 row, as the work order requires.

---

## 26 Oct — planner's answers to everything above

1. **N2 `n`.** Use the active players with a score (14 172). §2's figures were a rough count and carry no weight.
2. **Activity band.** Option (a): use it for `top10` only, and judge the other bands by the hard constraints alone. Do
   not collect more OStats now.
3. **Age buckets.** Refine them: `[256,512) [512,1024) [1024,∞)`. **The 187x-1029x growth gap is mostly a matching
   artifact.** Our day 60 is 480 speed-days, but it was scored against a `256+` pool of universes up to 15 years old.
   Re-score growth against `[256,512)` before quoting it. A fitted age curve can come later, only if that cell is thin.
4. **The 10-day growth snapshots that have no real cell** are dropped from HDS and reported on their own line.
5. **The dark-period rule was misstated in §4.** The host flags an account that is *never* dark for 6 h. So the hard
   constraint is: every calendar day of an active account contains at least one dark period ≥ 6 h. A violation is
   a day without one. Sleeping players satisfy it, and our scripted players' 1 013 long dark periods are correct
   behaviour.
6. **N1 has landed**, so the reading with host scores (HDS 0.686) is the baseline. The stub reading (0.773) is retired.
7. **The order after N5 is colonise, then the research/military split (persona mix), then expeditions, then moons.**
   It is now RL-FLOW.md stages S3-S5.
8. **The work order moved to `plan/rl/RL-FLOW.md`:** one agent, one change per experiment, 3 seeds, keep or revert.
   The NORTH-STAR rows N6-N11 are replaced by its stages.

---

## 26 Oct — row 4 (S1) — **stage exit met:** logins reproduce the documented gain, and S2 needs a decision

**Measured** (3 seeds, `runs/flow/e4-seed{1,2,3}`, code fd467f5, no change since 6b8cbb7; row 3's exact command plus
`--login-gaps storage/rl/ogsim/login-gaps.json`):

| seed | eval vs player | KL (early → end) | clip | entropy | EV | invalid |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | +106.0% | 0.0089 → 0.0002 | 0.13 → 0.00 | 1.96 → 0.41 | 0.33 → 0.67 | 0 |
| 2 | +101.7% | 0.0097 → 0.0000 | 0.14 → 0.00 | 1.92 → 0.34 | 0.32 → 0.66 | 0 |
| 3 | +106.2% | 0.0090 → 0.0003 | 0.13 → 0.00 | 1.97 → 0.26 | 0.33 → 0.69 | 0 |

Mean **+104.6%**, spread 4.5 (sd 2.5). The documented +90..+100% is reproduced and slightly exceeded; the S1 exit is met
and the loop is stopped here, before S2.

Two things the planner has to settle:

1. **S2 is not an R row of the same shape.** "Transfer the S1 model to PHP and locate the gap between the simulator
   (+44..+63%) and PHP (median 0.99)" compares two numbers, one of which (+44..+63%) is not the number this stage
   measured: S1 is +104.6% in the logins world, and the +44..+63% came from the no-login world that rows 1-3 put at
   +15.8%. Which model is transferred — an e4 checkpoint (logins) or an e3 one (no logins) — and against which PHP
   harness? Answering it wrong makes the "located gap" a comparison of two different worlds, which is exactly how the
   +31% baseline was lost.
2. **Budgets are saturated, so a next R row must change the problem, not the knobs.** KL → 0.0000 and clip → 0.00 by
   iteration 60-140 in all three seeds, with the eval flat from the same point: the policy is at a fixed point at 3 M
   steps under this menu. A longer run will not move it (rl-diagnostics §2-§3). Any headroom left is upstream: the
   building menu (queue 0 only, one planet, 72 h, no research decided by the policy). Suggest that the first post-S1 R
   row tests headroom in that menu (best of N orders under logins) rather than a learner knob.

### Planner's answer to row 4 (26 Oct)

**The finding behind all three answers.** The +104.6% and PHP's parity (median 0.99) are measured against **different
yardsticks**:
- in the simulator, the model is compared with the simulator's *player rule*, on catalogue planets;
- in PHP, it is compared with the *PHP planner* (the teacher), in full-cognition worlds.

The M5 note already says the player rule is weak under logins. So the first hypothesis for the "transfer gap" is that
there is no transfer gap at all, only a weak yardstick. That can be measured in the simulator alone, in minutes, from
data we already have. Measure that before any PHP run.

1. **S2's transfer scope.**
   - (a) **The checkpoint is e4 (logins).** PHP accounts act only at logins, and M5's served model (`build-model4`) was a
     logins-world model too. Take the seed with the best *training* metric (the last "train vs player" line), never the
     eval.
   - (b) **The harness, first with no PHP run:** M5's recordings `storage/rl/m5-eval7/teacher/choices-7101..7116.jsonl`
     (16 universes, 72 h, 12 accounts). Their start states come from
     `php artisan ai:rl-catalogue storage/rl/m5-eval7/universes/universe-<i>.sqlite <out>` (read-only). A PHP closed
     loop is run only if row 5 says the gap is real (row 7 below): `MODEL_FLAG=--build-model PHP=php ACCOUNTS=12 bash
     Modules/AI/rl/scripts/closed_loop.sh <e4 onnx> storage/rl/s2-e4 7101 16 3 14 0.5`, from the repo root, with
     `battle_engine=php` as in M5.
   - (c) **The like-for-like simulator number is SIM_T**: e4 deciding live in the simulator, divided by the PHP teacher's
     own recorded building choices replayed in the simulator, minus 1. Both run on the same 16 universes' start planets,
     at the same recorded login times, for 72 h. **Not** +104.6%, which is against the player rule.
   - (d) **S2's keep rule, as named gaps:**
     - the **yardstick gap** Y = player rule replayed / teacher replayed − 1, both in the simulator;
     - the **fidelity gap** F = the median over accounts of |teacher replayed in the simulator / the teacher's recorded
       PHP value − 1|. It must be ≤ 10%, or the replay is broken;
     - the **transfer gap** G = SIM_T − the PHP ratio (M5: about 0, median 0.99).

     S2 exits when the largest of these is named with its IQM and 95% CI.
2. **The first post-S1 R row is neither a knob nor a headroom test.**
   - No knob: row 4 is a fixed point (KL → 0, eval flat), as `rl-diagnostics` §2 says.
   - No headroom test now: headroom "against the player rule" is meaningless while that rule may be the weak part, and
     the building order is not on the north-star giveaway list (planets, shares, moons).
   - Headroom comes back at S6, against the replayed teacher.
3. **S1 stays the simulator baseline,** at +104.6% ± 2.2 against the player rule, in the logins world. **S2 stays next,
   re-scoped:** S2a (row 5, simulator only), then, only if row 5 says so, S2b (row 6, the serving gate for e4) and S2c
   (row 7, the PHP closed loop). The order after S2 is unchanged (S3 colonise).

**The row to take verbatim: RL-FLOW §4 row 5.**
