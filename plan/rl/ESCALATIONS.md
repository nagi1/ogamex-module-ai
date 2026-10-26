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


## 26 Oct — row 5 (S2a) — **F is 41%, so the comparator is broken; and the premise is refuted in the opposite direction**

**Measured** (`rl/ogrl/transfer.py`, 192 accounts over M5's 16 universes x 12, code as of this row; no learner, so
no PPO health):

| measure | IQM | 95% CI | median |
| --- | --- | --- | --- |
| Y yardstick (rule / teacher replay - 1) | **+0.542** | [+0.501, +0.584] | +0.549 |
| SIM_T transfer (e4 / teacher replay - 1) | **+1.267** | [+1.187, +1.348] | +1.289 |
| F fidelity \|teacher replay / PHP - 1\| | **+0.413** | [+0.377, +0.454] | +0.409 |

Replay repair: 26 of 5 698 recorded building choices refused (0.46%, gate <= 1%), 186 decisions after the recording
ended (3.3%). F is one-sided: T/PHP is 1.26-1.58 interquartile, above PHP by >10% on 86.5% of accounts, inside 10%
on 11.5%, below by >10% on 2.1%; by universe the F IQM runs 0.27-0.58, so it is systematic, not noise. On the clean
subset (>= 20 recorded choices and a last decision >= 66 h, n=111) F is still 0.31, T/PHP median 1.34.

**Three causes, each checked:**

1. **The replay's world is richer than PHP's, and mines compound.** The replay spends nothing on the fleet, defence
   and errands the PHP account did (3 367 yard and 1 907 errand choices of 13 050 recorded, 56%), so that budget goes
   into mines, which raise production for the rest of the 72 h. PHP's value *does* count the fleet at price
   (`getPlanetScore` sums buildings, stations and units; untouched by this row), but a fleet does not compound.
   A building-only replay against a whole-account value cannot close this.
2. **`value` is the value at the last decision, not at the end of the run.** Three accounts record a single decision
   and a value of 1 000 (their start), so a 72 h replay is compared with day 0 (ratios 8.4x and 23.8x). Filtering to
   accounts whose last decision is >= 66 h (142 of 192) moves F from 0.41 to 0.35.
3. **The replay is handicap in the login world.** In universe 7101, 25 of 352 replayed choices (7%) were legal but
   unaffordable at that login, so `decide` let the login pass with no build, while the rule arm always picks
   something affordable. Part of Y is that handicap, not the teacher's order.

**The premise behind S2a is refuted in the direction measured.** Y = +0.54 with all 93.8% of accounts positive: the
simulator's player rule ends **above** the replayed teacher, so "the player rule is the weak yardstick" is false in
this world, and the S1 gain is not a yardstick artifact on this evidence. The like-for-like number that survives is
the within-world one: **e4 over the rule, IQM +1.44** (median 1.41, p10-p90 1.19-1.86), against row 4's +104.6% in the plain-start
world. It is smaller here but the same direction, and both arms play identical worlds and login times.

**Worst accounts** (F, for the record): `7102/9`, `7102/12`, `7116/11` (one recorded choice, PHP value = 1 000,
T/PHP 23.79); `7103/3` (2 choices, 8.40); `7110/13` (8 choices, 4.33); `7103/7` (9 choices, 4.31).

**Questions — S2a cannot be kept as written, and each answer changes the next row:**

1. **What is the comparator?** A building-only replay in the economy simulator cannot reproduce a whole-account PHP
   value, because the fleet/errand budget becomes compounding mines. Choose one: (a) accept it and compare on the
   *building* part of PHP's value only (planet score without units) — one line in `transfer.py`, F then measures the
   replay, not the world; (b) replay yard and errand choices too, which needs the world simulator (`World`/`VecWorld`)
   and is a different, much larger row; (c) drop F and judge S2 on the yardstick alone, accepting that Y is measured
   against a handicap.
2. **Is the recorded `value` the right comparator value at all?** It is the value at the last decision; 3 of 192
   accounts never decided again. Do we add an end-of-run value to the recorder (a PHP change of a few lines, and a
   new recording for row 6), or filter to accounts whose last decision is >= 66 h and accept F 0.35?
3. **Does S2 continue?** If the yardstick premise is false and e4 is still +44% over the rule within this world,
   the reading is "the gain is real in the simulator, of unknown size in PHP", which is what S2b (the serving gate)
   was for. Row 5 says S2b/S2c run only if S2a says the gap is real; S2a's F gate failed, but not in a way that says
   the model is bad — it says the *replay* is not comparable. Say whether the next row is S2b on the e4 checkpoint
   (logins world), a fixed S2a per answer 1, or S3.

### Planner's answer to row 5 (26 Oct)

Row 5 was a good measurement, and it refuted my premise: the player rule is not the weak yardstick (Y +54%). It also
showed *why* the replay cannot be the comparator. A PHP account spends 56% of its decisions on the yard and on errands,
and the economy-only simulator has nowhere to spend that budget except compounding mines.

1. **Comparator: none of (a)-(c). The replay comparator is retired.**
   - (a) compares the building part, but the replay's mines are still inflated by the budget it never spent on ships,
     so F would only look smaller.
   - (b) is a large row, and the world simulator answers the same question more cheaply.
   - Y from row 5 is not interpretable, because the replay is handicapped (unaffordable logins). Do not quote it.
   - `transfer.py` stays, as the record of row 5.
2. **The recorded `value`:** do not change PHP now. Any later use of these recordings filters to accounts whose last
   decision is at 66 h or later. An end-of-run value in the recorder waits until a stage needs a new PHP recording
   (S2c).
3. **S2 continues in the simulator, one step closer to PHP: the world (row 6).** The hypothesis that row 5 points at is
   that the economy-only simulator is the wrong world. A building-only gain of +100-140% does not survive when the
   same budget must also buy ships and survive raids. That fits PHP's parity, and it is the same conclusion as the
   north-star reading: the account is the unit, not the build queue.
   - Row 6 measures it with paired twins in `VecWorld`.
   - Row 7 (conditional) adds raiders.
   - S2b and S2c run only if the world does not shrink the gain.


## 26 Oct — row 6 (S2a, the world) — **check 3 fails: the controlled arm never holds a ship, and at 72 h nobody does**

**Measured** (`rl/ogrl/transfer.py --world`, 64 paired worlds x 12 accounts, the first 6 controlled, code f1000b27;
the whole run 27 s):

| check or measure | result |
| --- | --- |
| 1 dims: world (17, 32, 13) against e4 (17, 13) | ok |
| 2 one seed, byte-identical first observation | ok |
| 3 ships on controlled / rule-played accounts | **0% / 0% — FAIL** |
| G_w account value (e4/rule - 1) | **+0.564** [+0.538, +0.590], median +0.554, n 384 |
| economy value | **+0.564** [+0.538, +0.590] — identical, by construction |
| fleet value | -1.000 on all 384 accounts |

**Cause 1: at the stage's horizon the world has no fleet at all.** Horizon sweep, two seeds, 12 accounts, fleet at
the end:

| horizon | rule-played slots | controlled slots |
| --- | --- | --- |
| 72 h | 0 on all 6 | 0 on all 6 |
| 240 h | 1.5k-106.5k | 0 on all 6 |
| 720 h | 136.5k-451.5k | 0 on all 6 |

`Miner::act` orders ships only when its economy pick is None or a store is >= 80% full, and a 72 h speed-8 growth
phase reaches neither.

**Cause 2: the controlled slots can never hold a ship, at any horizon.** `Player::act` is called only from
`VecWorld::play_rest`, and `advance` calls `play_rest` only for accounts with `controlled[a] == false`; a controlled
account's `step` commits the caller's economy object and returns. In both arms the controlled accounts' `account_value`
therefore equals their economy value, so the row's measure — account value with ships at price — is undefined for
exactly the six accounts it is measured on.

**A third thing, which is not row 6's fault but decides how to read later numbers.** The installed `ogsim` wheel was
stale: `take_finished` returned 5-tuples with no fleet column and `units`/`fleet_settings`/`combat` were ignored (the
probe gave the same 239 693 value with and without the rules). I rebuilt it from the repo's own source
(`maturin develop --release`, 40.9 s, `Modules/AI/rl/ogsim`) and re-ran every check on the new build. Nothing under
`Modules/AI` is tracked (`.gitignore:33`), so the rebuild has no commit either.

**The planet sample moves this number further than the hypothesis band.** Same model, same rule, both arms, economy
value only:

| sample | planets | e4/rule - 1 |
| --- | --- | --- |
| row 4, plain starts with logins (eval.json) | 192 | +104.6% |
| row 5 context, M5 universes | 192 | +144% |
| this row, units-parity | 24 | +56% |

An 88-point swing on the same model. A row cannot claim "the world shrank the gain" while the sample is not held
fixed, and row 6's band ("below +50%") sits inside that swing.

**Questions:**

1. **Ships on controlled accounts need a Rust change.** Choose: (a) in `VecWorld::step`, after committing the
   caller's economy object, run that account's ship rule (`Miner::order_ships` is already `pub(crate)`), one call
   plus a test — a row 6b; (b) split `Miner::act` so the caller's pick is passed in; (c) leave it, and state that the
   world's economy is the economy simulator, so row 6 adds nothing and S2a closes on the economy numbers. Row 6
   forbids Rust changes, so the choice is yours.
2. **Horizon.** At the stage's 72 h no account has a ship, so even after (a) the yard cannot matter. Move S2a to
   240 h (a world costs 0.1 s, so 64 pairs are still under a minute), or keep 72 h and leave the yard out of scope?
3. **Which planet sample is the stage's fixed evaluation?** +104.6% (row 4, 192 plain), +144% (row 5, 192 M5) and +56% (24) are the same
   model and rule. Name the sample S2 measures on, and say whether row 4's +104.6% is re-stated on it.
4. **If you take (c), what is G_w?** +56% on the units-parity planets is a real economy number in a 12-account world
   with rule-played opponents and identical login schedules. Is "the world adds nothing over row 5, and the economy
   gain is sample-dependent" an acceptable S2a result, or does S2a need the fleet arm before it can close?

### Planner's answer to row 6 (26 Oct): S2 closes with the gap named; go to S3

Row 6 is decisive, just not in the direction it was set up for. Three measured facts:
- in the simulator at 72 h, no account has a ship;
- a controlled account never runs its yard rule;
- the same model against the same rule reads +56%, +104.6% or +144% depending only on the planet sample.

So the economy simulator's building gain is neither like-for-like with a PHP account (which spends 56% of its decisions
on the yard and on errands) nor stable across samples. Chasing a sharper simulator proxy for PHP would be the circling
we stopped.

1. **(c), for now.** Row 6 adds nothing over row 5, and S2a closes on that. Option (a), the yard rule run for
   controlled accounts, one call plus a test, becomes the **first row of S6**. That is where the building model is
   retrained in the world, and it will need it then.
2. **Horizon.** S2 is closed, so nothing is chosen for it. T rows use the 60-day season. From S6 on, R rows in the world
   use **240 h** at least: below that no fleet exists.
3. **The fixed sample.** Economy-simulator R rows keep `eval.json` (192 plain starts, row 4: +104.6%). Any other sample is
   reported as context and never compared with it. World R rows from S6 on use `econ-u1` with world seeds 1-64,
   fixed, named in the row.
4. **The S2 result, as named gaps:**
   - **(i) A scope gap.** A PHP account's budget goes 56% to the yard and errands, which the economy simulator does not
     model, and its scripted rule builds no ship in 72 h.
   - **(ii) A sample gap.** The gain spans 56-144% across planet samples.

   **Consequence, now a standing rule: no building gain measured in the economy simulator is quoted as a
   real-game gain.** Every R row from S6 on is trained and judged in the world, and each stage exit there ends with a
   PHP closed loop (L4). S2b and S2c are folded into S6's exit.

**Also, the stale wheel:** some earlier runs used an `ogsim` build older than the source. Rows 1-4 used `VecEnv` only,
which that stale build served correctly (the economy parity tests pass on the source). Their numbers stand, but
nothing proves the build matched. From now on the loop rebuilds before any run (LOOP-PROMPT).

**Next: row 7 (the S3 control), then row 8 (colonise).**
