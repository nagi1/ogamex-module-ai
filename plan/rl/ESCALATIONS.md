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


## 26 Oct — row 8 (S3, colonise) — **stage exit met: planets 0.516 -> 0.889, HDS 0.686 -> 0.752; S4 needs a decision**

**Measured** (3 seeds x 64 accounts x 60 days at speed 8 on `u1-colonies`, code as of this row, `runs/flow/e8`):

| reading | row 7 (control) | row 8 (colonise) |
| --- | --- | --- |
| HDS, per seed | 0.685 / 0.689 / 0.683 | **0.754 / 0.751 / 0.751** |
| HDS pooled | 0.686 | **0.752** |
| Turing AUC | 0.990 | 0.986 |
| violations | 1 057 | 1 055 |
| planets, in-band share | 0.516 | **0.889** |
| res_share | 0.316 | 0.440 |
| eco_share | 0.794 | 0.828 |
| mil_share | 0.484 | 0.482 |
| growth / moons / galaxies / alliance | 0.799 / 0.672 / 1.000 / 0.906 | 0.800 / 0.672 / 1.000 / 0.906 |

Colonising fires 203-213 times a season; the final day has 30-33 of 64 accounts on six planets and 21-23 still on one.

**What shipped** (5 files, about 70 lines, one test):
`World::colony_site` (room from `max_planets` at the account's astrophysics, the free slot's position level, a site the
home world's tables carry, home galaxy and system first); `Miner::order_plans` wants one colony ship while a site is in
reach; `Miner::unlock` wants the host's colonisation research (the ship's own requirements do not include it, so
without this no site is ever in range); `Miner::act` founds the colony at the same login; the season enables the tables
it never loaded and now writes and tallies per account rather than per slot (it panicked on the first colony slot).
No object is named in code: the ship and the research ids come from the catalogue's colonisation block.

**The first reading failed the row's own guard, and that is recorded rather than hidden.** With `colony_site` scanning
from galaxy 1 for everybody, accounts living in galaxies 2-3 held planets in two galaxies, `galaxies` for
bottom|128-256 fell 1.00 -> 0.87 and HDS read 0.740. Preferring the home galaxy and system — what a player does, gate
3 — gives `galaxies` 1.000 and HDS 0.752. Both runs are in `runs/flow/e8`; only the second is the measurement.

**Three questions before S4:**

1. **top10 `planets` is still 0.00 and colonising cannot fix it.** Our best accounts end at a score p50 of 460 against
   top10's 138 909, so the top10 cell is a score band we never enter; its planets measure (16 median) is unreachable at
   60 days if the score is 300x short. Do we (a) keep scoring top10 planets, (b) judge planets only in the bands the
   season can reach (bottom, mid) and say so, or (c) treat "reaching top10 at all" as its own later stage?
2. **The horizon caps planets at 6.** `max_planets` reaches 6 around astrophysics 9, and 60 days at speed 8 gets no
   further, while real mid accounts hold a median of 10 and top10 of 16. Extend the season (days), raise the starting
   astrophysics, or accept 6 for now and record the cap?
3. **S4 next, as the order says: the research/military split.** Note what colonising already did to it: res_share
   0.316 -> 0.440 (+0.124) because colonies need research, while **mil_share did not move at all** (0.484 -> 0.482) and
   is now the second giveaway (52% out of band). S4 as written targets both shares; if it is doctrine data, say whether
   it may also touch what the scripted players *build* (a fleet share), or only the research/yard mix, because the
   military half cannot move from research alone.

### Planner's answer to row 8 (26 Oct)

S3's colonise row is kept: HDS 0.686 → 0.752, planets in band 0.516 → 0.889. The 33 Rust tests pass on this tree
(checked by the planner before committing). Recording the failed first reading and fixing it the way a player plays
(home galaxy first) was right.

1. **top10 planets: (a), keep scoring it, but fix the yardstick first.** The "300x short" is against the pooled
   real `256+` bucket, which spans universes up to 15 years old. My answer of 26 Oct (refine the buckets) was never
   applied. That is row 9. Re-judge top10 after it. Hiding a band whose number looks bad would be the slop we stopped.
2. **The cap of 6 planets is accepted for now and recorded.** After row 9, if planets are still out of band in the
   cells our seasons reach, a later S3 row tests a longer season (120 days) as its one change.
3. **S4 may change what the scripted players build, not only research.** The military half cannot move from research.
   - The one change: a per-persona **target share of spending that goes to the yard** (ships and defence), as one
     number in each persona's constructor. That is taste over host data (Gate 1: it names no object), set from the
     real `mil_share` medians per band.
   - Hypothesis: `mil_share` moves into band without `res_share` falling back.
   - Gate 3: a raider keeps a fleet, a miner keeps a little defence.

   Row 10 is written after row 9's numbers, so its bands are the corrected ones.


## 26 Oct — row 9 (S3, the bucket split) — **reverted: thin where our seasons live, and the top10 gap is not a matching artifact**

**Measured** (`real_bands.py` two-line split + `hds.py` fallback, then the tree restored; code as of this row):

| reading | pooled bands (row 8 state) | split bands |
| --- | --- | --- |
| self-test | **PASSED**, continuous HDS 0.836, AUC 0.543 | **FAILED**, continuous HDS 0.839, AUC **0.551** |
| `256-512` n (bottom / mid / top10) | — | **15 / 22 / 8** |
| row 8 re-scored | 0.752 (AUC 0.986) | **0.752** (AUC 0.986) |
| row 7 re-scored | 0.686 (AUC 0.991) | **0.686** (AUC 0.991) |
| top10 real growth p50 | 138 909 | 138 909 |

**What it means.** The split is correct in principle and unusable at this season length: every line a 60-day season
writes is day x 8 = 480 speed-days, which is inside `256-512`, where the published universes hold 8-22 players. The
prescribed fallback then returns the merged `256+` cell, which reproduces the pooled target to the digit, so HDS and
AUC move not at all. The hypothesis (HDS changes by more than the spread, the top10 gap shrinks by an order of
magnitude) is refuted: the gap is 302x before and after.

**Two findings worth keeping:**

1. **A fallback built from capped samples is wrong.** `real_bands` stores `sample` as the *first* `SAMPLE_MAX` (2 000)
   rows, not a random draw, so merging three buckets' samples re-weights them. Doing that read `top10|256-512` growth
   p50 as 84 175 against the true 138 909 and recorded row 8 at HDS **0.777** — a number that would have entered the
   log as a correction. The merged cell must be computed from the full vectors inside `real_bands.cells_from`.
2. **The self-test pays for the finer grid.** With the split (plus the merged cells and the self-test's own
   `MIN_CELL`), the AUC moves 0.543 -> 0.551, over the 0.55 bar by 0.001. I did not isolate which of the three
   changes carries it, and the row was reverted rather than kept on a 0.001 margin.

**Questions:**

1. **When does the split pay?** At speed 8 the next bucket starts at 512 speed-days = day 64. `512-1024` holds 88
   (top10) / 293 (mid) / 199 (bottom) players and `1024+` holds thousands. Re-order the split as part of the
   120-day-season row (which your answer to row 8 already contemplates for the planets cap), or keep it now and raise
   the self-test bar to 0.56? The plan's own `AUC_TARGET` is the only thing between the two.
2. **Should top10 be judged at 60 days at all?** Its real growth p50 is 138 909 against our 460 — a 302x distance in
   score, and its planets median is 16 against our cap of 6. If the answer is no, say what replaces it as the
   late-game check, because the north star needs one.
3. **Row 10 (S4) was written "after row 9's numbers, so its bands are the corrected ones".** Row 9 is reverted, so S4
   will be judged on the pooled `256+` bands. Confirm that, or write row 10 for the finer grid once a longer season
   can reach it.

### Planner's answer to row 9 (26 Oct)

Reverting was right. The sample-cap finding (merged capped samples re-weight cells) is a real trap. Keep it in mind
for any future merge: merge from the full vectors only.

1. **The split waits.** It comes back in the same row as a 120-day season (day 64 onward lands in `512-1024`, which
   has enough players). The self-test bar stays at 0.55; do not raise it.
2. **Keep top10, and the gap is real: it starts in the first week.** Measured by the planner on `e8/season-1`: the best
   three accounts hold **169-520 points at day 8** (64 speed-days), against real players at 32-64 speed-days with
   growth medians of **50/speed-day for mid** (about 1.6-3.2k points) and **10 320 for top10**. Our best are already
   **3-6x below the real *mid* median** in week one, and they close nothing later (about 270-300k points at day 60).
   Part of the top10 distance is what OGameX does not model (lifeforms, Dark Matter, officers). The mid distance in
   week one is ours. That makes early growth a diagnosis row (row 11): how much of each day each queue sits idle,
   hours on full storage, logins per day by account age. Real players are most active at a universe's start.
3. **Row 10 (S4) is judged on the pooled bands, as you say,** and it goes before the growth diagnosis because the
   scorecard ranks `mil_share` as the top giveaway (importance 0.18). Growth is third (0.13), after alliance. Rows
   follow the measure's order, not the planner's hunch. Row 10 is written.


## 26 Oct — row 10 (S4, the military share) — **the effect is real (HDS 0.752 -> 0.820) and three contracts break; reverted**

**Measured** (S4 applied, `cargo build --release`, three seasons, `hds.py`; then reverted):

| measure | row 8 | row 10 (S4) | delta |
| --- | --- | --- | --- |
| HDS | 0.752 | **0.820** | +0.068 |
| mil_share in-band | 0.482 | **0.850** | +0.368 |
| res_share | 0.440 | 0.578 | +0.139 |
| eco_share | 0.828 | 0.867 | +0.039 |
| growth | 0.800 | 0.802 | +0.002 |
| planets | 0.889 | 0.884 | -0.004 |
| AUC | 0.986 | 0.986 | 0 |

Every numeric gate in the row passes (mil_share >= 0.65, res_share >= 0.40, HDS > 0.755).

**But `cargo test --release` fails three of 34**, all from one root — the scripted Miner buys defence while under its
military share:

1. `errands::tests::every_offered_errand_is_one_the_world_accepts` — *seed 2: the world offered `Errand { kind: Raid,
   target: Some(2), unit: None, size: Want }` and refused it: not enough resources*. The defence order spends between
   the offer and the dispatch, so the mask offers an action the world then rejects.
2. `errands::tests::a_short_stock_orders_only_what_it_pays_for` — *the staged order asked for 7 of 4*: the plan the
   test compares against (`teacher().order_plans`) changed underneath it because defence joined the wants.
3. `players::tests::miners_raiders_and_savers_play_ten_days` — *world value 39 922 878 against quiet 51 407 557: gap
   11 484 679 is more than raids moved (4 312 295)*. Defence destroyed in raids is not in what the ledger counts.

After the revert: 33/33 pass, and the season numbers above are the only record of S4 (they are not a baseline).

**Questions:**

1. **Ship S4 with the contract fixes, or separately?** The minimum set: compute the errand offer after the yard order
   (or restrict defence to economy-mode accounts), and count defence destroyed in `Ledger`'s `moved`. Two or three
   lines in `errands.rs`/`players.rs`. Does that become row 10b (S4b) — "defence, done properly" — or do you prefer to
   ship S4 ships-only for the Miner and leave defence to the errand stage?
2. **Does the same hole exist in the season output?** `events_today.ships_lost_value` is booked from `ships_lost` in
   the event log; if defence losses are missing there too, then every season HDS is slightly optimistic on military
   share and pessimistic on economy. Worth a line in row 11's diagnosis or its own row?
3. **Targets.** Defence did not starve research at all (res_share rose 0.44 -> 0.578), so the 0.06 / 0.20 / 0.30 split
   is not binding; mil_share landed at 0.85 in-band, well past the 0.65 gate. Keep the targets as written, or lower
   them so accounts do not overshoot into band-edge territory?

### Planner's answer to row 10 (26 Oct)

Exactly the right call: a gain that breaks invariants is not kept. The effect is large and clean (HDS 0.752 → 0.820,
`mil_share` 0.48 → 0.85), so it comes back with its contracts as **row 10b**.

1. **Ship it with the fixes, in one row (10b).** They are not a second change. They are the same change done
   correctly. The fix is the *order of events* at a login: the yard spends first, then the errand menu is built on what
   is left. That is also what a player does: queue the yard, then look at what they can still afford to send. The tests'
   assertions stay as they are. Defence stays in, because it is the Miner's military (Gate 3: a miner turtles a little).
2. **The season output:** count defence destroyed in `ships_lost_value` within row 10b. HDS itself is not biased by it:
   the shares come from `score::of` (what stands), not from the event counts. Only the loss counter was wrong.
3. **The targets stay 0.06 / 0.20 / 0.30.** The gate passed by a wide margin, and tuning a number that already passes
   would be knob-turning. If a later reading shows `mil_share` piling up at a band edge, that is its own row with its
   own measurement.


## 26 Oct — row 10b (S4, the military share with its contracts) — **the row's fix deletes another test's premise; stopped at its escape clause**

**What was applied** (all three items of the row): row 10's `military_share` unchanged; `Ledger`'s `lost` counting
`target_lost` as well as `ships_lost`; the login order changed in the errand harness so the scripted yard order is
placed before the menu is built. Then `cargo test --release`.

| run | outcome |
| --- | --- |
| row 10 as written | 31 pass, 3 fail |
| + both sides' losses in the ledger | 31 pass, 3 fail (ten-day gap accounted 4.31M -> **5.31M** of 11.48M) |
| + yard order before the menu | 32 pass, 2 fail; the offer/refusal test passes |
| + a fuel check in `raid_plan` | 32 pass, 2 fail |
| after the revert | **33/33** |

**Three measured facts, each from a trace rather than an inference:**

1. **The offered-then-refused raid was never about ordering.** `raid_plan` builds a fleet and checks only that the
   ships are on the shelf. Trace of the refusal: `a=0 t=378000 mission=Attack cargo=[0,0,0] fuel=102
   stock=[83809, 38668, 92.8]` — the menu offered a raid whose fuel the account could not pay, at the same login it
   then refused. The menu was built twice at that second, once at 418.8 deuterium and once at 92.8, so the yard order
   does widen the window; but no ordering makes an offer valid when the plan ignores fuel. One line in `raid_plan`
   (`fuel <= stock[2]`, plus the tanks' capacity) makes the test pass **without** touching the order.
2. **"The yard spends first" cannot coexist with the `Order` errand.** That errand *is* the yard order, offered to
   the model: `candidates` builds `Kind::Order` from `teacher().order_plans` when the queue is idle. Ordering first
   leaves `order_plans` with nothing missing, so `a_short_stock_orders_only_what_it_pays_for` fails with *no login
   ever offered a staged order* — the menu loses an action the seam exists to offer.
3. **The ten-day conservation test is now mis-calibrated, not broken.** `|world - quiet| <= moved + 3% of quiet` with
   defence in both worlds: the hostile world rebuilds what raids destroy, so its compounding diverges from the quiet
   twin by more than 3% of a larger base. Counting the defender's losses is correct and necessary (+1.0M), and 6.2M
   still sits outside `moved`. That is a tolerance question about compounding, and it needs a number from the planner,
   not a bigger allowance invented here.

**Questions:**

1. **Row 10c, written to ship?** Keep the yard order where it is, add the fuel check to `raid_plan` (one line), keep
   both sides' losses in the ledger (verified: +1.0M), and change the ten-day allowance from `3%` to what? The
   measured residual is 6.2M against a 51.4M quiet world (12%); a compounding-aware allowance would be `moved +
   k%` with k around 15, or the comparison could be made on *economy value only*, which is what raids actually move.
   Your call, because it sets the test's meaning.
2. **Or re-home the `Order` errand?** If the yard order really should come first, the `Order` errand has to become
   "order *beyond* the login's own yard pass", which is a different candidate menu and a different S7 row. Neither
   option is free; say which.
3. **Defence stays?** Nothing here changes row 10's result (HDS 0.820, mil_share 0.850) — the effect is not in
   question, only the two contract lines and one tolerance.

### Planner's answer to row 10b (26 Oct)

My 10b prescription was wrong, and your traces show why: the refusal was a `raid_plan` that ignored fuel, and
"yard first" would delete the `Order` errand the seam exists to offer. Row **10c** is written from your findings.

1. **10c, yes:** the login order stays; add the fuel check (a real bug: the host refuses that dispatch); keep both sides'
   losses in the ledger. Trace test 2's "7 of 4" with defence in the wants, and fix the staging cap if that is the
   cause, never the assertion.
2. **The ten-day tolerance: no k.** That assertion's premise is that a raided world and its quiet twin differ only by
   what raids moved. That stops being true as soon as accounts rebuild what raids destroy, because the rebuild competes
   with mines and the difference compounds. Exact conservation is already proven where compounding is excluded:
   `world::tests`, two accounts that never build, against a quiet twin. So the twin line becomes a printed diagnostic
   in the ten-day test. Its behavioural asserts (raids, saves that succeed and fail, recalls) stay. A larger
   allowance would only be a number invented to pass.
3. **The `Order` errand stays where it is.** Defence stays (row 10's result is not in question).


## 26 Oct — row 10c (S4, corrected) — **two repairs pass, the third needs one line of an assertion; stopped**

**Applied:** row 10's `military_share` unchanged; `raid_plan` refuses a raid whose fuel the stock cannot pay (plus the
tanks' capacity); `Ledger::lost` counts `ships_lost` + `target_lost`; `miners_raiders_and_savers_play_ten_days`'s
twin-value line printed instead of asserted (the row's planner decision).

| test | without the repairs | with them |
| --- | --- | --- |
| `every_offered_errand_is_one_the_world_accepts` | FAIL (raid offered, refused) | **pass** (fuel check) |
| `miners_raiders_and_savers_play_ten_days` | FAIL (gap 11.5M vs moved 4.3M) | **pass** (diagnostic, moved 5.3M printed) |
| `a_short_stock_orders_only_what_it_pays_for` | FAIL (7 of 4) | **FAIL** (7 of 4) |
| the other 31 | pass | pass |

**The trace, exactly as the row asked for it:**

```
STAGE a=3 t=812400 unit=13 missing=4 staged=3 queue=4
APPLY a=3 t=812400 unit=13 size=Now missing=4 amount=3 queue=4
```

The staged quantity is 3 against a want of 4 — correctly capped by the stock. The assertion then sums the **whole
yard queue** (`w.yards[a].orders`, every unit) and compares it with **this unit's** want: 4 units already queued by
the login's defence pass plus the 3 staged = 7 > 4. There is no cap to fix; the two quantities are different
things.

**Two staging variants, both worse:**

1. Offer `Order` candidates only into an idle yard → the test fails with *no login ever offered a staged order*, and
   the seam loses a candidate it exists to offer.
2. Buy defence only at building logins (an idle login's yard action is the `Order` errand's) → 32 pass, 2 fail: the
   offer test breaks again, because at an idle login nothing spends before the menu and the earlier mismatch returns.

**The one-line fix, which needs your word:** in `errands::tests::a_short_stock_orders_only_what_it_pays_for`, sum
only the staged unit's orders,

```rust
let queued: u64 = w.yards[a].orders.iter().filter(|o| Some(o.unit) == now.unit).map(|o| o.amount - o.done).sum();
```

which is what the assertion says it measures ("the staged order queues fewer of that unit than the want"). With it,
the suite is 34/34 and S4 ships at the numbers already measured (HDS 0.820, mil_share 0.850, res_share 0.578).

**Questions:**

1. **Authorise the one-line change,** as row 10d or as a footnote to 10c? The alternative is to leave S4 unshipped,
   which buys nothing: the effect is measured, the other two repairs are proven, and this is a counting bug in a test
   helper line, not in the world.
2. **If you prefer no test edits at all,** the only remaining route is to keep the yard queue empty at idle logins
   by construction — i.e. move all yard ordering out of `act` and into the `Order` errand, which re-homes the ship
   orders the eras of rows 6-8 rely on. Say if that is worth a stage row; I would not spend one on it.

### Planner's answer to row 10c (26 Oct): authorised, and S4 ships

1. **Authorised, as part of 10c (no new row).** I checked `errands.rs:407`. The assertions are about *the staged
   order* ("the staged order queued nothing", "asked for {queued} of {missing}"), but the line sums the whole yard
   queue. That was equivalent only while nothing else could be in the queue at that moment. Fixing it restores the
   test's meaning; it does not loosen it. Prefer the strictest form: measure what **this** order queued, as the queue
   of `now.unit` after the apply minus the same before it. That stays correct even if the defence pass ever queues the
   same unit. If the before/after form is awkward, your unit filter is accepted. Put one comment line on why.
2. **No re-homing of yard orders.** Not worth a stage.

**Re-apply row 10c with this line, run the full suite (34/34) and the three seasons, fill in the row, and commit.
Then go to row 11.**

## 26 Oct — S4 exit met: row 10c ships the military share, row 11 names the early-growth mechanism

**Row 10c kept.** HDS **0.820** (per seed 0.817 / 0.824 / 0.820, spread 0.007; row 8 was 0.752), AUC 0.988,
34/34 tests with every assertion intact. `mil_share` in band **0.482 → 0.850**, `res_share` 0.440 → 0.577,
`eco_share` 0.828 → 0.867, growth 0.800 → 0.803. Giveaways now: alliance 0.083, moons 0.070, res_share 0.069,
galaxies 0.055, growth 0.052 — `mil_share` has left the list.

The change exposed two real bugs, both fixed: `raid_plan` offered raids whose fuel the stock could not pay (mask
offering an action the world refuses), and `Ledger::lost` counted only the attacker's losses. The staged-order test
now measures what its own order queued, by that unit's queue before and after the apply — your strictest form.

**Row 11 kept as a diagnosis.** 11 520 account-days, three 60-day seasons:

| age (days) | logins/day | build h/day | idle h/day | full logins | growth p50 | real mid p50 | ratio |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1-7 | 12.4 | 0.1 | **23.9** | **58%** | 1 | 50 | 0.03 |
| 8-14 | 14.1 | 2.6 | 21.4 | 46% | 6 | - | - |
| 15-30 | 23.7 | 9.6 | 14.4 | 27% | 46 | 1 842 | 0.03 |
| 31-60 | 28.0 | 12.9 | 11.1 | 18% | 167 | 4 343 | 0.04 |

The three best accounts at day 60 (318 592 / 315 844 / 302 123) held 464-2 097 points at day 8.

**Mechanism named:** one decision per login against second-long builds. In week one the queue is busy 0.1 h of 24 and
58% of logins find a store already at 80% or more — the resources are there and cannot be spent. Login cadence alone
(12.4/day) is not the whole story, and neither is the build order: the world gives a login one commitment, and a
throwaway build leaves the rest of the gap unused. The deficit is not only early — 0.03-0.04 of the real mid median
per account-day at every age, 0.14 for the best accounts at day 60.

**Questions (gate 3 — I stop here until you answer):**

1. **Row 12 = a T row on login cadence?** The login table is age-blind; a real player is most active at a universe's
   start. My proposal: draw the gaps from an age-decayed mixture — days 1-7 median gap ~10-15 min, decaying to the
   measured table (median 48 min, mean 2.2 h) by day 14 — one parameter, one row, gated on per-measure `growth` and
   HDS not regressing beyond 0.01. Do you want the decay as a table per age band (harder to fake, more data) or as a
   single multiplicative decay on the existing draws (simpler, and I can state the assumption in the row)?
2. **The one-decision-per-login mechanic.** Is that a deliberate simplification of the login world (in which case the
   cadence row must compensate for it), or should a later row let one login commit as many builds as it can pay for —
   which is what a real player does in the first hour and would change the errand seam every row since row 6 was
   measured against? If it is deliberate, I will say so in the row and stop chasing it.
3. **Which gate for cadence rows?** HDS is pooled over 8 measures, so a genuine 30× growth move could sit inside it.
   Should rows 12+ be gated on per-measure `growth` (e.g. week-one growth p50 at least 10x today's, and days 15-30 at
   least 3x) with HDS only as a non-regression check?

### Planner's answer to the S4 exit and row 11 (26 Oct)

S4 is closed at HDS 0.820, and row 11 is a model diagnosis: it named the mechanism with a table, not a guess.

2. **One decision per login is a simplification that is wrong for the real game, and it is the first thing to fix.**
   The host's building queue holds one item, so a player cannot queue ahead. But a login is a *session* of minutes,
   and in week one at speed 8 a build takes seconds. A real player keeps clicking: build after build in one sitting.
   That is row 12, done in the login clock. The seam does not change: every build is still one choice through the
   same candidates, there are just more choice points per login. Errands stay once per login, so rows 6-10's errand
   semantics are untouched.
1. **Cadence comes after sessions, as row 13, and only if week one is still far below.** Fixing sessions first,
   because it is mechanically true, avoids tuning cadence to compensate for a missing mechanic. If row 13 is needed:
   a single multiplicative decay on the existing gaps, with the assumption stated, is preferred over a table we have
   no real data to fill (Gate 2).
3. **Gate rows 12+ on the per-measure numbers, with HDS as a non-regression check (±0.01),** exactly as you propose.
   Pooled HDS is the wrong instrument for a mechanism that moves one measure 10x.

## 26 Oct — row 12 (S5) measured and reverted: the session clock works, its spending mix does not

Row 12 as written is in `RL-FLOW.md` line 109; the code is reverted (`cargo test --release` 35/35, tree back to row
11's commit) and the evidence is kept in `storage/rl/ogsim/runs/flow/e12/` (three seasons + scorecard).

**What the session clock did.** Each login opens a 5-20 minute session; inside it the account returns the moment a
queue frees, never closer than the host's 10 s floor to its own last action.

| age (days) | growth p50 row 11 | growth p50 row 12 | × | idle h/day | build h/day |
| --- | --- | --- | --- | --- | --- |
| 1-7 | 1 | **7** | 7.0 | 22.5 | 1.5 |
| 8-14 | 6 | 64 | 10.7 | 17.1 | 6.9 |
| 15-30 | 45 | 180 | 4.0 | 13.8 | 10.2 |
| 31-60 | 164 | 305 | 1.9 | 16.4 | 7.6 |

Best three accounts at day 8: **8 688 / 7 238 / 7 151** points, against row 11's 464-2 097 and your real mid target of
1.6-3.2k. HDS **0.820 → 0.839** (+0.019), AUC 0.988 → 0.993, the 10 s floor **0 violations** at season scale
(`action_under_10s 0`), the other hard constraints unchanged (dark periods 1 013 vs 1 011, logins under 18 h 43 vs 43).

**Why it is reverted.** `eco_share` 0.867 → **0.677**, under its 0.80 gate, and the loss is exactly where real
accounts stop buying mines:

| cell | row 10c | row 12 |
| --- | --- | --- |
| top10 the bottom 128-256 | 0.78 | **0.00** |
| top10 256+ | 0.63 | **0.00** |
| mid the bottom 128-256 | 0.98 | **0.15** |
| mid 256+ | 1.00 | 0.76 |
| bottom the bottom 128-256 | 0.89 | 0.98 |
| bottom 256+ | 0.75 | 0.88 |

A session lets the account place every build it can pay for, and the rule's menu is economy-heavy, so a big account
becomes a bigger mine instead of turning its stock into fleet and defence. `res_share` (0.577 → 0.852) and `growth`
(0.803 → 0.840) moved the other way, which is what made this close: the change is not noise, it is spent in the wrong
proportion.

**Two real bugs it exposed, both fixed on the way** (and both needed for three world tests to pass with unchanged
assertions): a login that was due now was pushed past the 10 s floor and skipped, so account 0's clock jumped six
1 800 s gaps to 10 800; and a session woke a colony's research queue, letting a colony decide the account's research.
The fixes have nothing to hold up once the clock is reverted.

**Measurement caveat:** under sessions the season's `logins` field counts decisions, not logins, so the row quotes
growth and idle hours rather than logins/day. If sessions come back, `World` should count logins from `login_times`.

**Questions:**

1. **Re-land sessions with the mix they need — row 12b?** One change in `players.rs` (the same file row 10 used for
   `military_share`): make the economy share follow the real band as the account grows, so a large account stops buying
   mines once its `eco_share` is at the band's top and spends on fleet and defence instead. Gate the pair on
   `eco_share` and `growth` both holding, and keep or revert them together. I would rather not land sessions alone
   again, and I would rather not spend a row on the mix alone either: the mix only bites when there is stock to spend.
2. **Or is the login cadence row (S5's own `Next`) the better first step?** Denser early logins move week one, which is
   still 4-7× short, without touching the spending mix; but row 11 said the queue is idle because one sitting cannot
   spend, and cadence alone cannot fix that.
3. **The 7× against your 10×.** Week one rose 7× where the hypothesis predicted 10×: a session of 5-20 minutes still
   leaves the account idle most of the day. If you want week one at the real level, the session length is the knob
   (or sessions in the first days only) — say which you would rather I try.


### Planner's answer to row 12 (26 Oct): re-land the session clock, then fix the mix as its own row

Reverting by the letter of the keep rule was right. This is a **planner override, stated openly**: the session clock
comes back as the baseline (row 12r). Reasons:
- **The 10x gate was my estimate, with no data behind it.** The measured 7x in week one and 4x in days 15-30 is large
  and real.
- **The mechanic is correct.** The host queue holds one item, and a player builds again within a sitting.
- **HDS rose** (0.820 → 0.839), and so did `res_share`, `mil_share` and `growth`.
- **The `eco_share` fall is not a regression of sessions.** It is the scripted spending mix that was tuned, without
  anyone knowing, under a broken clock. Under the true clock the rule over-buys mines once an account is big. That is
  real information.

1. **No paired row.** Sessions (12r) and the mix (13) are measured one after the other, so each effect stays
   attributable, and the mix row runs on a baseline that has the stock to spend.
2. **Cadence comes after the mix,** and only if week one is still at least 4x short (row 14).
3. **No session-length knob.** 5-20 minutes stays, with the assumption stated in the code. Its effect on week one is
   not a reason to tune a number we have no data for.

## 26 Oct — row 13 (S5) reverted, and row 12's diagnosis was upside down

Row 13 as written is in `RL-FLOW.md`; the code is reverted (`cargo test --release` 36/36) and the evidence is in
`storage/rl/ogsim/runs/flow/e13/`.

**What it measured.** `economy_share_max` per persona (Miner 0.75, Raider 0.55, FleetSaver 0.65) with the yard given
first refusal above the cap. `eco_share` in-band **0.672 → 0.683** against the ≥ 0.80 the hypothesis needed; HDS
0.837 → 0.834; `res_share` 0.842 → 0.811; growth unchanged. The top cells did not move at all.

**Why it could not work, and what the number actually says.** Our p50 `eco_share` against the real cell's
p10 / p50 / p90:

| cell | row 10c | row 12r | row 13 | real p10 | real p50 | real p90 |
| --- | --- | --- | --- | --- | --- | --- |
| top10 256+ | 0.717 | 0.902 | 0.905 | 0.273 | 0.504 | 0.741 |
| top10 the bottom 128-256 | 0.597 | 0.869 | 0.866 | 0.287 | 0.502 | 0.685 |
| mid the bottom 128-256 | 0.532 | 0.826 | 0.814 | 0.348 | 0.546 | 0.769 |
| mid 256+ | 0.697 | 0.783 | 0.781 | 0.344 | 0.612 | 0.863 |
| bottom 256+ | 0.700 | 0.750 | 0.742 | 0.330 | 0.642 | 0.994 |

We are **above** the band in the top and mid cells, not below: an account holding 0.90 economy is one whose score is
almost all buildings. Real top accounts hold 0.50 economy, 0.22 research, 0.05 military — their total is 100x ours
(138 909 against 1 173 per speed-day), and the mass in that total is fleet and research. Our accounts cannot dilute
their buildings because they own nothing else: the personas' wants are a fixed tiny kit (1 probe, a few cargo ships,
harvesters, fighters) that a grown account already has, so above the cap the yard has nothing to start and the login
builds anyway. The session clock (row 12) made this worse, not better: more stock, the same tiny wants, so more mines.

**A real mechanism found on the way** (kept only as knowledge, since the row is reverted): a bare ratio cap freezes
accounts. A young planet scores 0 until a few levels clear the host's 1 000-point floor and then it scores ~1.0
economy, so a cap that drops the building pick left four persona tests stuck at `[3, 2, 0, 3]` — deuterium never
mined, no research, no colony, no defence. First refusal fixed it with every assertion unchanged.

**Questions:**

1. **Row 14 = widen the wants, scaled to the account's own score?** One change in `players.rs`: a persona's fleet and
   research programme grow with its score (e.g. keep a unit stock worth a share of the total, and keep research at the
   real top band's ~0.22 share) instead of a fixed count. That is the only lever that can dilute buildings, it is the
   same seam rows 10 and 13 used, and it names no object (the catalogue says what can be built). It also gives the
   session clock's spending somewhere to go, which is why row 12 showed the mix drift in the first place.
2. **Or the cadence row first?** Week one is still 4x off the real mid and the session left 22.5 idle hours a day, so
   cadence moves growth; it would not touch `eco_share`, which is the failing measure.
3. **Gate 3 reading.** "A professional player grows a fleet worth a share of his account and keeps researching" is
   ordinary play, but sizing it off the score is our device, not the game's. If you would rather keep it purely
   behavioural, say what the wants should be sized on (age? storage? the real band's research share?).


### Planner's answer to row 13 (26 Oct), and row 14 (run by the planner, reverted)

Your reading was right: our big accounts are not economy-heavy, they are *empty*. I tested the obvious follow-up myself
(row 14: buy military worth the gap, not a fixed batch). It moved `eco_share` only 0.672 → 0.711 and took research
down with it (0.842 → 0.759), so it is reverted. Both rows say the same thing: **an account 100x too small cannot have
a real top player's shape.** The top10 cells are a *size* gap (growth 0.00 there), not a mix gap. Chasing the mix
there is circling.

So the next rows follow the scorecard's giveaway order, which no longer leads with a mix measure:
`alliance` (0.091), `galaxies` (0.078), `mil_share`, `growth`, `moons`. That is rows 15 (alliances), 16 (galaxy
spread) and 17 (moons, a diagnosis first). Login cadence and the size gap come back after them, as their own rows.
Your question 3 (sizing wants off the score) is answered **no**: row 14 shows it does not pay.

## 26 Oct — rows 15-17 (S5): alliance kept, galaxy spread reverted, moons diagnosed

### Row 15 (alliances) — kept

`alliance` in-band **0.906 → 0.956** and it left the giveaways (it was #1 at 0.085; the top five are now moons,
eco_share, growth, res_share, galaxies). The top10 cells left 0.00 as asked: 0.49 at 256+, 0.57 at the bottom
128-256, and mid 128-256 0.62. HDS 0.837 → 0.845; per seed 0.846 / 0.850 / 0.840 against 0.840 / 0.834 / 0.836;
AUC 0.994 → 0.991; no measure's in-band share fell. Membership is drawn as an assumption (p 0.65, join day
exponential mean 5 after day 3) and a quitter never joins, which is why the day-60 member share is 0.50 and not 0.65:
half the cohort quits at a median of day 10. Real top10 are 1.0 in every bucket, so if you want that cell exactly we
need to stop killing half the cohort or let a quitter have joined before it left.

*The HDS gain is marginal: means +0.008 with seed spreads of 0.006 and 0.010, so the ranges touch at 0.840. The
decisive evidence is the targeted measure and that nothing else fell.*

### Row 16 (galaxy spread) — reverted

The mechanism works - a day-60 account's galaxies go from a p50 of **1 to 3** with its 5 planets unchanged - and the
measure gets worse: `galaxies` in-band **1.000 → 0.942**, HDS 0.845 → 0.837, AUC 0.991 → 0.992. The spread is the real
**top10** shape and the wrong shape for our accounts, which are small: real accounts hold several galaxies only once
they are big enough for one galaxy to run out of room. Our colony count is uniform - 5 planets at day 60 for every
account, because the colonisation table caps it and every account reaches that research level - so `galaxies` had no
size signal to follow.

### Row 17 (moons) — the table, and what it says

Three fresh seasons (`runs/flow/e17`, 60 days, 64 accounts, 11 520 account-days), with append-only counters in
`world::Event` and the season's `events_today`:

| band | fights | fights that rolled | moons made | accounts with a moon at day 60 |
| --- | --- | --- | --- | --- |
| top10 | 1 003 | 0 | 0 | 0/18 |
| mid | 3 220 | 0 | 0 | 0/75 |
| bottom | 5 750 | 0 | 0 | 0/99 |
| pooled | 9 973 | 0 | 0 | 0/192 |

Moon destruction sent: 0. No fight in three seasons cleared the host's 100 000-debris threshold, so no roll was ever
made. The fights are not merely small, they are empty: lost value per fight p50 **0**, p99 6 750, max 77 500 (≈ 23 250
debris, 4x short of the threshold), and half the fights destroy nothing because the target holds no fleet. Day-60
fleet value p50 is 10 988 875 - the fleets exist, they are never home when a raid lands, and raiders fly cargo ships
that nobody shoots at.

**Questions:**

1. **What should the moon-shot row change: the fights, or the size?** A moon is a consequence of two fleets meeting
   and dying. Three candidate levers, all in `players.rs` and none naming an object: (a) a saver's save that *can*
   fail - it already can, but a saver never gets caught because raiders only hit undefended fleets; (b) raiders that
   target the fleets they can see (spy first, then hit the one with ships), which is what a professional does and is
   also the missing `spy_sent` behaviour in the tally; (c) size, since even a perfect fight needs ~333 000 of destroyed
   ships and our best account holds 21M *value* (≈ 20k points) against a real mid of 138 909.
2. **Is a deliberate debris trade in scope?** Two accounts raiding each other's ships to farm debris is a real
   technique, and it is the only way a small account makes a moon. It needs two personas to cooperate, which the
   season has no notion of. If you want it, it is a row of its own.
3. **Should row 18 be the size gap?** Both `eco_share` (row 13) and `moons` (row 17) reduce to the same fact: the
   account is two orders of magnitude too small, so it cannot fill the shapes real accounts fill. Growth is 0.840
   in-band and the mid cells are 4x behind. My read is that `mil_share` and `growth` should come before any more mix
   measure, and that the cadence row is the cheapest growth lever left (week one is still the largest gap).

No further row is started: rows 15, 16 and 17 are done and row 18 needs your pick.


### Planner's answer (26 Oct)

**Before the three questions: the size gap is mostly a measurement artefact, and I should have caught it at row 9.**
The real `growth` divides a player's points by the age of the *universe* (oldest alliance × speed). I read the
universes behind `real-bands.json`: 196 of 267 are speed 8, and **no speed-8 universe is between 8 and 90 days old**
(five at 7 days, then 91, 126, 168 ... up to 8 052). Our accounts at days 8-60 have therefore always been scored against
universes months to years old. The 128-256 cells come from nine speed-5 universes that are all exactly 49 days old.
"100x too small" compared a 30-day account with a 2-year one. In the one like-for-like cell (32-64 speed-days, the
7-day speed-8 universes), our best day-8 accounts (~100 points per speed-day) are *above* the real mid (49) and below
the real top10 (10 260). Rows 13, 14 and 16 were all steered by that number, and the reverts were right for the wrong
reason.

1. **Moon lever: (b), and not yet.** Raiders who spy first and hit the fleet they can see is what a professional does,
   and it makes (a) happen by itself: a saver gets caught only when someone hunts fleets. (c), size, is not a lever.
   It is what rows 18-20 measure properly. (b) is row 21, conditional on row 20 putting moons or fights in the top
   three giveaways at the ages where real universes exist.
2. **Debris trade: out of scope.** It needs two personas to cooperate, and the season has no notion of that. It is a
   real but niche technique, and alliances do nothing in the season yet. It is deferred until an alliance can act as
   a unit, and I will not write it as a row before then.
3. **Row 18 is neither size nor cadence. It is the audit of the target** (`band_audit.py`, read-only). Row 19 then
   scores speed-8 accounts by the real age of their universe. Row 20 runs 365-day seasons so that our accounts reach
   the ages where about 150 speed-8 universes exist; that is the new T baseline, replacing 0.845. Row 21 is the raider
   rule. Row 22 re-opens the R track (S6, `VecWorld`, 240 h) once row 20 is kept. Cadence is not dropped: if row 20
   shows `growth` out of band at 64-128 days, the planner writes the decomposition row (produced, wasted at the store
   cap, spent, looted) before any cadence change. The 41% of week-one logins with a store at 80% or more is the first
   suspect.

Stop points: after row 18 if the ages are refuted (no bucket can be trusted), and after row 20 (the new baseline: the
planner picks row 21 from its giveaway order if the condition fails). Otherwise run 18 → 22.

## 26 Oct — rows 18-19-20: the yardstick is now the universe's own age, and the 365-day baseline is 0.757

**Row 18 (audit, kept).** `rl/scripts/band_audit.py` (read-only, 120 lines) over all 267 aged universes: the 196
speed-8 universes are five at **7 real days** and then 91 to 8 515 - **nothing is 8-90 days old**, so there is no
64-128 speed-day cell and no 8-16 one, the 128-256 cells are nine speed-5 universes all exactly **49 days** old, and
256+ is a median of **532 days**. The age estimate (oldest standing alliance) is exact: against OSTATS `opened` the
ratio is 1.00 (1.00-1.01) on the 19 young universes and 1.00 on the nine speed-5 ones, median 1.000 over 264 matched.
The one like-for-like cell was 32-64 at 7 days: real growth p50 **10 260 / 50.0 / 0.1** for top10 / mid / bottom.

**Row 19 (kept, measurement).** Scored cells are now the speed-8 universes by their own age in real days (0-14,
64-128, 128-256, 256-512, 512+); other speeds keep speed-days in a printed `speed_days` table. Self-test passes
(HDS 0.856, continuous 0.832, AUC 0.535). Re-scoring e15 unchanged gives **HDS 0.637**, not 0.845, and the two
scorecards share **no scored cell**: our day 10 had no real counterpart before, and days 20-60 have none of our ages
now. So 0.845 was never a same-age comparison; 0.637 was the first one, and under it `mid at 0-14` is in band on 7 of
8 measures while `top10 at 0-14` is in band on none.

**Row 20 (kept: the new T baseline).** Three 365-day seasons, 64 accounts, `u1-colonies`, seeds 1-3 (11.9 s each):

| age bucket | HDS | growth in band | our p50 growth, top10 / mid / bottom | real p50 | giveaway order |
| --- | --- | --- | --- | --- | --- |
| 0-14 | 0.637 | 0.42 | 142 / 86 / 25 | 10 260 / 49 / 0 | growth, res_share, eco_share |
| 64-128 | 0.787 | 0.55 | 1 778 / 469 / 7 | 74 557 / 2 876 / 84 | growth, eco_share, res_share, mil_share |
| 128-256 | 0.709 | 0.37 | 1 338 / 453 / 4 | 116 840 / 8 656 / 181 | res_share, growth, eco_share, mil_share |
| 256-512 | 0.803 | 0.58 | 847 / 374 / 2 | 132 248 / 3 657 / 74 | res_share, eco_share, **moons**, growth |
| 512+ | no row | - | - | - | a season is 365 days |

Pooled **HDS 0.757**, AUC 0.995. `cargo test --release` 36/36 unchanged, no NaN or negative value at day 365. Day
365: 96 of 192 accounts have quit, planets 1-8 (median 5.5), **0 moons and 1 galaxy on all 192**, totals p50 213 580.
Moons over a whole year: **38 456 fights, 0 rolls, 0 moons**, 0 destructions sent. Giveaways at every age are size
and mix, led by `res_share` and `eco_share`.

### Questions

1. **Does row 21 run?** Its condition - "moons, mil_share or fights in the top three giveaways of 128-365" - holds at
   **256-512 only** (moons 0.192, third, ahead of growth 0.170) and fails at 128-256 (moons not in the top five, and
   `moons` in band 0.52 there). `mil_share` never reaches the top three and `fights` is not a scored measure. If you
   want the year to be the unit, row 21 runs; if 128-256 must lead, the spy-first raider is not the top giveaway and
   you should pick row 21 from `res_share`/`eco_share` instead.
2. **The top10 cells are a size gap, not a shape gap, and they now dominate the giveaway order.** At 128-256 real
   top10 hold 11-14 planets and 10-14 moons; ours hold 1-8 planets and 0 moons, with growth 1 338 against 116 840
   (87x). Capping or re-aiming spending cannot close that; only growth can. Is row 21 (raider targeting) really the
   next row, or should the growth decomposition come first - the store-at-cap reading (41% of week-one logins) with
   the session clock's spend path? Your answer to row 17's question 3 was "size is not a lever", but every bucket now
   says size is the top giveaway.
3. **`growth.py`'s real-mid column is stale by construction** (it keys speed-days into an age-bucketed table). Row
   20 was "data only, no code", so I left it; the one-line fix is `age_bucket_of(row["day"])` in `real_p50`. Say if you
   want it folded into the next row, since any row that quotes that column will otherwise quote the wrong cell.
4. **Half the cohort is gone.** 32 of 64 accounts per seed quit at median day 10, so the 128-365 cells describe about
   96 survivors against real universes whose highscore has anyone still ranked. Is that the comparison you want, or
   should the quit process itself be a row (it is the largest single difference and it is not a scored measure)?

### Planner's answer (26 Oct, rows 18-20)

**I was wrong about size.** "Size is not a lever" was written against the old yardstick. On same-age cells the gap is
real: mid 6x behind at 64-128 days and 19x at 128-256, top10 40-90x. Rows 18-20 were still the right order, because
without them we would not know which cells are real: 0.845 was never a same-age number, and 0.757 is the first honest
baseline.

1. **Row 21 does not run.** It is marked deferred: its condition held in one bucket and size leads every bucket. Row 22
   (S6) waits too, because a model trained on a world whose accounts grow 6-20x too slowly learns that world.
2. **Growth first, measured before it is fixed: row 23.** It adds an income ledger (produced, wasted at the store cap,
   spent, loot, fuel, expeditions; the fields must sum) and one ceiling arm: always online, which bounds what any
   cadence change can buy. My bet is income, not idle time. No persona sends an expedition (`players.rs` has none,
   though `expedition::Rules` is in the simulator), and expeditions plus inactive farms are where a speed-8 player's
   mid-game income comes from. Row 24 is pre-committed three ways from row 23's numbers: A (spend the store) if
   waste ≥ 20%, B (logins by age) if the ceiling gives ≥ 3x, otherwise C (expeditions).
3. **Yes: the `growth.py` fix goes into row 23.**
4. **The survivor comparison is the right one, if quitting is scored the way the real data is.** A real account leaves
   the bands when its status turns `i` (7 days without a login). Row 23 checks that our quitters leave the scored lines
   the same way. The quit process itself is not a row: it is not a scored measure, and real cohorts lose players too.
   One thing to carry: real top10 at 0-14 days is about 575 000 points at day 7, which no production at that age can
   reach. That cell is probably paid (Dark Matter) play. Row 23 writes the ceiling's best day-7 total beside it, and
   if the ceiling is far below, that cell is noted as unreachable rather than chased.

**Planner, later the same day (owner asked for a larger run between stops):** the queue now runs 23 → 24 → 25 → 26 →
27 → 28 → 22 without a planner stop. Rows 24-26 try all three growth variants in the order the ledger ranks them,
each against the last kept baseline. Row 27 aims the research share by the signed table. Row 28 is row 21 re-entered
behind a condition, and row 22 (S6) follows once mid growth is in band. The only stops are: a ledger that does not
sum, a failed condition, two flat rows in a row, a test that only passes by changing its assertion, and the S6 stage exit.

## 26 Oct — row 23 (the income ledger): built, measured, and it does not close on every line

The ledger was implemented as written and measured on 40 days, 64 accounts, seed 1 (2 496 account-days, 3.5 s). This
is a **stop**: the row's keep rule is "the sum holds on every line" and it does not, so the code is edited back out
and the flow does not go on to rows 24-28.

| quantity | share of produced |
| --- | --- |
| produced | 100% |
| wasted at the store cap | 4.4% |
| spent (all of it building) | 81.5% |
| loot_in | 0.2% |
| loot_out | 0.2% |
| fuel | 0.0% |
| expedition_in | 0.0% |

Aggregate closure: **unexplained 0.000% of produced** (0.1 of a 16.1 bn stock), 37/37 `cargo test --release`. Per
line: **45 account-days of 2 496 (1.8%) are over 1%**, all of them an inactive account on the season's final flush
line, with every flow zero and the stock rising 969-1 939 on an account that holds 344 566 (0.3-0.6%).

What the ledger does say, in the direction of your hypothesis: the missing growth is **not** waste (4.4%, not 20%) and
**not** expeditions or loot (0.2% each, an expedition has never been sent). It is that the account spends 81.5% of
everything it produces on buildings and still ends 6-19x behind the real mid: the argument is now about *what* the
money buys and how fast it arrives, not about a leak in the books.

**Questions:**

1. **Is "every line" the gate you want?** The 45 failing lines are all post-quit lines the writer emits at infinity,
   where the account's clock no longer moves and the identity has no flow to name. Either the check excludes the final
   flush line for an account whose last login precedes it, or the tolerance is expressed against the day's flows with a
   floor (a 1 939 move on a 344 566 account is 0.6%). Both are one-line changes to the checker; neither changes the
   world. Say which, and row 23 can be re-landed with the measurement it already has.
2. **The 81.5% spent on buildings is the number row 24's rule needs.** Under it: `wasted` 4.4% is far below the 20%
   threshold, so row 24 would be **A** only if the store cap were the binding constraint - it is not; the ceiling arm
   is the remaining question and it was not run, because the row failed before its six 365-day seasons. If the ceiling
   gives less than 3x at 64-256, row 24 is **C** (expeditions) on your own rule.
3. **The 40-day shares above are not the 365-day ones.** With the gate fixed I would run the six seasons (base and
   ceiling, three seeds) and the signed table; none of that is in this entry.

### Planner's answer (26 Oct, row 23)

1. **Keep the gate, and fix the line instead.** A stock that rises on a line with no flows is production with no name.
   An inactive account still produces (a real `i` account does; that is why inactives are worth farming), so the final
   flush attributes its accrual to `produced`. Fallback, if that is more than a few lines: skip the check after an
   account's last login, since those lines are not scored. Row 23r says which was used.
2. **Row 24 is picked by the rule once the ceiling arm has run.** Waste at 4.4% already rules out A. Run the ceiling;
   under 3x makes it C.
3. **Yes: 365 days, both arms, three seeds** (row 23r). Also split `spent` by kind. "All of it building" at 40 days
   means research and yard purchases are being booked as buildings, or their spending is not recorded at all. Both
   are wrong, since `res_share` and `mil_share` are non-zero.

The run is now 23r → 24 → 25 → 26 → 27 → 28 → 22, with the same stop rules as before.

### Planner (26 Oct, speed)

From the commit times: a row of work takes 10-30 minutes of the implementer's own time. The seasons inside it take
about a minute, the machine sits at load 0.4 of 72 cores, and the longest delays (2.7 h before row 18, 35 min after
row 20) were waits for the planner or the owner. Compute is not the bottleneck; serial rows and stops are. So, from
row 24 (row 24p):
- 10 seeds in parallel instead of 3.
- The three growth variants run as parallel arms behind season arguments, each judged alone against one baseline.
- Row 26 stacks the arms that were kept.

That turns three serial rows into one wave and makes keep/revert calls on 10 seeds instead of 3.

## 26 Oct — row 23r (the ledger re-landed): 9 lines of 139 776 still do not close, and they name the attack leg

The ledger was re-landed with the closure fixed in the world (fuel refunds booked where the host hands them back, a
delivery between two accounts' books, a colony's starting stock) and the season reporting flows unrounded. The 40-day
control is now **0 of 2 496 account-days over 1%** (was 45), and split by kind: build, research and units each carry
their own field. `cargo test --release` 37/37.

At 365 days, both arms, three seeds (`runs/flow/e23r`), **9 account-days of 139 776 (0.006%) are over 1%** and none of
them is an account's last line, so the fallback does not apply:

| season | account, day | moved | flows |
| --- | --- | --- | --- |
| base-1 | 10, 220 | 118 | 120 |
| base-3 | 40, 14 | 77 214 | 78 464 |
| online-1 | 26, 31 | 4 881 003 | 5 746 576 |
| online-1 | 40, 10 | 2 236 916 | 2 419 674 |
| online-2 | 2, 16 | -53 114 | 146 827 |
| online-2 | 47, 17 | 219 740 | 341 278 |
| online-2 | 56, 13 | 913 170 | 1 081 209 |
| online-3 | 14, 27 | 51 899 | 5 349 084 |

Every residual is **negative**: resources left the account that the books do not name. The mechanism is the attack leg.
`attack` books `loot_in` from `outcome.loot` and the surviving hold from `outcome.cargo`; if `outcome.cargo` excludes
the plunder, the plunder is claimed as income but never arrives in the hold, which is exactly this sign and this size
(largest: 5.3M claimed, 51 899 arrived).

**Questions:**

1. **Confirm the reading and I will close it in one row.** If `combat::Outcome.cargo` is the surviving hold *without*
   the loot, the fix is one line - add `outcome.loot` to the hold before booking, or book the arrival at landing
   instead of at the fight - and the gate should then hold on every line. Say whether the plunder is meant to ride in
   the hold (the host's FleetMissionService gives it to the returning fleet) and I will land it as row 23s.
2. **Or accept a stated tolerance.** 9 lines in 139 776, all on the always-online arm or accounts that fight often, the
   four largest on accounts that also fight most. If you would rather have the ledger now, the gate can read "within
   1% of the day's production, and no line over 0.01% of the account's stock" - that passes all nine.
3. **The ceiling arm and the ledger shares are unread.** Both arms ran, but with the gate failing the row cannot be
   kept, so I have not read the ceiling ratio at 64-256 that picks row 24 (A/B/C), and the base arm was not re-scored
   against row 20's 0.757. Six seasons are on disk at `runs/flow/e23r` if you want the numbers read against a tolerance
   you accept.

### Planner's answer (26 Oct, row 23r)

1. **The reading is wrong, and the fix is still one row (23s).** `outcome.cargo` already contains the loot
   (`combat.rs:489`: carried + loot + hauled, capped by the surviving capacity), and `attack` puts it on the fleet. What
   breaks the identity is **when** it is booked. `loot_in` lands in the books on the fight's day, while the stock rises
   only when the fleet lands, sometimes on a later day. The residuals have the sign and the clustering that predicts:
   all negative, mostly on the always-online arm, which raids most. Book `loot_in` from the landing. Book a destroyed
   fleet's hold as `cargo_lost`.
2. **No tolerance.** Every number later rows quote comes from this ledger, so it has to close exactly. It is one
   booking change.
3. **Go straight on after 23s.** Read the shares, the ceiling ratio and the signed table into row 23s, apply the
   pre-committed pick, then run row 24p's wave (A, B and C as parallel arms, 10 seeds each), then 26 (stack), 27, 28
   and 22.

## 26 Oct — row 23s kept, rows 24p-27 measured and reverted, row 28's condition fails: the flow stops

The ledger closes and everything the growth rows tried came out flat or zero-sum, so the run stops at row 28's condition
with row 22 (S6) not run. Rows, in the order they ran, on the ten-seed speed rule (10 seasons in parallel, 26 processes
at most, one 10-seed baseline at `runs/flow/e24p` used by every arm):

| row | what changed | result (10 seeds unless said) | keep rule | decision |
| --- | --- | --- | --- | --- |
| **23s** | the income ledger books loot where the hold takes it, and a lost hold as `cargo_lost` | **0 of 140 160 account-days over 1%** (both arms, 3 seeds, 365 days); worst residual 8e-6 on a 343M day; base HDS **0.757 = row 20 to the digit** | every line closes, base = row 20 | **kept** (`5d2f8e7`) |
| **24 A** | a login with a store at 80%+ buys the storage for it | HDS **0.754**, growth in-band 0.486 against 0.469, mid growth p50 240 → 247 | +0.10 growth in one bucket, HDS beyond the spread | **reverted** |
| **24 B** | the login gap scaled 0.5 → 1.0 by day 30 | HDS **0.757**, growth in-band 0.479, mid p50 240 → 242 | as above | **reverted** |
| **24 C** | raiders and savers keep an expedition out; miners never | HDS **0.751**, growth in-band 0.467, 221 534 arrivals, `expedition_in` 0.17% of produced | as above | **reverted** |
| **25, 26** | the second variant, then the stack | **not run**: row 24p made all three one wave and row 24 kept none, so there is nothing to stack | - | folded / stopped |
| **27** | `research_share` per persona (real p50 `res_share` at 128-256: 0.106 / 0.113 / 0.132), the lab waits above it | **`res_share` in-band 0.22 → 1.00 at 128-256 and 0.26 → 1.00 at 256-512, out-of-band 54% → 11%; mid growth p50 126 → 231 (+83%)**; but **`eco_share` 0.79 → 0.00** and it becomes the top giveaway; HDS 0.752 → 0.750 | res_share +0.10, **eco_share no worse than −0.02**, HDS beyond the spread | **reverted on its own guard** |
| **28** | raiders spy first and hit the fleet they can see (row 21 re-entered) | condition: growth in-band mid 64-256 = **0.00 / 0.34**, and `moons` is **fourth** in the giveaways (res_share 0.246, eco_share 0.233, galaxies 0.207, moons 0.183) | condition | **not run** |
| **22** | S6, the model retrained on the new world | waits on row 28 | gate | **not run** |

Three things this run established with numbers, and one bug it fixed:

1. **The account's ceiling is its queue, not its income or its cadence.** The ledger says it wastes **80.6%** of what it
   produces over the year (23.5% in week one, 38.2% at 31-60, 95.7% at 257-365) while spending **8.4%** on buildings,
   and the always-online ceiling is only **2.0x** what the real cadence gives. A fuller cadence (B), a bigger store (A)
   and another income stream (C) each move growth p50 by 0.8-2.9% and none reaches the 0.10 in-band the row asks for.
2. **Storage is already the player rule.** `Baseline::Player` buys the store object when a store is about to overflow
   (`economy.rs:923`), which is why A changed one cell by 0.03 and nothing else. The 80% threshold adds nothing.
3. **Research was crowding out the mines, and the mix is zero-sum.** Row 27's target cut research spending at the real
   share and nearly doubled mid-band growth (126 → 231 p50) while leaving `eco_share` at 0.00 in band - the stock went
   to buildings, and `eco_share` was already +0.24 above the real p50 before the change.
4. **A crash, fixed:** arm C panicked 9 of 10 seeds at `combat.rs:197` - a season researches shields past the combat
   export's grid and the cell lookup asserted. It now reads the edge cell, as the economy's grids already do, with its own
   test; the base arm is byte-identical after the fix. Any later expedition or late-game row would have hit this.

**Questions:**

1. **The growth lever is a spending one, not an income one - is that the row you want?** Waste at 80.6% with `spent_build`
   at 8.4% says the account cannot *place* what it makes: its queue runs out of legal objects (or affordable ones) and the
   store absorbs the rest. Rows 24 A-C all added supply or time; none of them added a place to spend. The next row I would
   write is "what is left to build at day 120, and what stops it" - a diagnosis on the same ledger, no behaviour change.
2. **A mix row that fixes `res_share` without breaking `eco_share` needs both shares in one target, not one share.** Row 27
   moved `res_share` clean out of the giveaways (0.246 → 0.073) and doubled growth, then handed first place to `eco_share`
   (0.233 → 0.182, out-of-band 28% → 58%). Do you want a row that aims both shares at once (one target per persona, the
   other side absorbing), or is doubling mid-band growth worth keeping even with `eco_share` out of band for a stage?
   Row 13 hit the same wall from the other side.
3. **`planets` fell from 0.93 to 0.30 in the mid 128-256 cell under row 27** - bigger accounts stop colonising the way the
   band expects. Is that a real behaviour to chase (a colonisation cadence row) or an artefact of the cell being rank-based?
4. **Row 28's second branch: `moons` is fourth, not third.** Its own condition needs top three, and `moons` is only 0.183
   behind `galaxies` 0.207 - one more measure moving and the raider row fires. Do you want it now, or after question 1?
5. **Row 22 (S6) stays closed**, as its gate says: a model trained on accounts that grow 37-70x too slowly in the mid
   cells learns that world. Nothing in this run changed that, and the ledger now says why.

### Planner's answer (27 Oct, rows 24p-28)

The ledger did its job: the account does not lack income, it **stops spending**. After day 129 it builds with 0.3-8%
of what it makes and throws away 78-96%.

1. **Yes, the lever is spending. Row 29 is your diagnosis**, with its outcome decided in advance. My reading is that
   two things cap spending.
   - **Time.** Late builds take weeks because the persona never raises the queue's time modifiers
     (`modifiers[queue]`, read from the host).
   - **The yard.** Row 10's `military_share` acts as a ceiling, so the yard stops while the store overflows.

   A professional fixes both: they build robotics and nanites before multi-day builds, and they turn every surplus
   into fleet. Row 30 runs **T** (time chain), **Y** (surplus to the yard, the share becomes a floor) and **R** (row
   27) as parallel arms.
2. **Aim the mix as a whole, not one share at a time.** Row 27 broke `eco_share` because buildings were the only
   place the stock could go. Y gives it a second place. Row 31 stacks the kept arms and judges the three shares
   together. That also answers row 13's wall from the other side.
3. **`planets`: not now.** Row 27 was reverted, so the fall is gone. If it returns in row 31, it is probably real:
   a busy account still colonises. I will write it from row 31's numbers.
4. **Moons after the yard.** No fleet to hit means no moon. Row 32 re-tries the raider once Y has given the accounts
   fleets, with a condition that can be met.
5. **Agreed, S6 stays closed** until mid growth is in band.

The arm-C crash fix (`combat.rs:197`, edge cell) stays, with its test: it is a real bug any late-game row would hit.
Run: 29 → 30 (wave) → 31 (stack) → 32 → 22.

### Planner (27 Oct, course correction)

Asked by the owner for root causes and a fix. They are written as RL-FLOW.md §0, and the plan is changed to match:
- **§1 and §3:** T rows run 10 seeds × 365 days. The baseline is 0.757 (row 20/23s). Parallel arms are allowed.
  Every T row reports the ledger and the stall table.
- **Row 22 is superseded by row 34.** `train_ppo.py` only drives `VecEnv`, so 22 could not have run, and its 240 h
  horizon ends before the day-129 stall. Row 34 adds `--world` to the trainer (added lines only) and trains over the
  whole year.
- **Row 33** replaces the share caps with one spending order: every queue busy, then the rest to the yard. It deletes
  `military_share`, `research_share` and any economy cap.
- **Row 35** (written now, runs after the owner's PHP check) is the learned allocation across queues, with the band
  constraint as a Lagrangian penalty.
- **Row 32** no longer stops; it goes on to 33.

The run is now 29 → 30 → 31 → 32 → 33 → 34, and the stage exit after 34.

## 27 Oct — row 29 (the stall diagnosis): the account runs out of fields, so neither T nor Y runs, and the flow stops

Row 23s's ledger said the account stops spending (building 7.8% of production at 129-256 days, 0.3% at 257-365, with
78-96% thrown away). Row 29 asked why, on the same ledger, with its arms decided in advance. Three seeds, 365 days,
70 080 account-days (`runs/flow/e29`), the season writing a `stall` sample per line and `rl/scripts/stall.py` printing it
by age.

| age (days) | planet-hours the building queue is queued | planet-days with > 24 h left | next build (median) | build modifier level | "no want" | objects `ok` | objects "no fields" |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1-30 | 1% | 0% | 0.9 h | 0 | 1% | 14 | 0 |
| 31-60 | 2% | 0% | 4.7 h | 1 | 19% | 14 | 0 |
| 61-120 | 1% | 0% | 2.6 h | 2 | 33% | 11 | 0 |
| 121-180 | 0% | 0% | 1.4 h | 2 | 43% | **1** | **13** |
| 181-250 | 0% | 0% | 1.4 h | 2 | 47% | 1 | 13 |
| 251-365 | 0% | 0% | 1.3 h | 2 | 50% | **0** | 13 |

And the yard, at the logins that found a store at 80% or more: **ordered 94-100%**, "share target met" **0%** at every
age, "nothing payable" 6% in week one and 0% after.

**Both of row 29's hypotheses are refuted, and the table names the real cap:**

- **T (the time chain) is not the problem.** No build ever holds the queue for a day: the building queue is queued on 0-2%
  of planet-hours, the next build takes 1-5 hours, it is paid for immediately (wait 0.0 h after week one), and the build
  time modifiers are already at level 2 in the median planet by day 61. Robotics and nanites are being raised; there is
  simply no multi-day build for them to shorten.
- **Y (the yard ceiling) is not the problem.** Row 10's `military_share` never shows as a ceiling: at every login that
  finds a store at 80% or more the yard *does* order (94-100%, and 100% after day 31). The surplus already goes to ships
  and defence; there is nothing for a floor to fix.
- **The planet runs out of fields.** From day 121 the capital has **13 of its ~17 building-queue objects blocked by "no
  fields"** and only one or zero that can start at all, and by day 251 **nothing** is legal. That is the 78-96% of
  production the ledger throws away: not slow builds, not a capped yard, but a planet with no room to build.
- **And the one object that fixes it is invisible to the player rule.** The object that adds fields produces nothing and
  stores nothing, so `Baseline::Payback` never ranks it and `Baseline::Player`'s store branch never sees it - the rule that
  plays the account cannot choose it, and the account therefore never leaves the trap it is in.

Row 29's pre-committed pick lists no arm (T 0% against 40%, Y 0% against 40%), so **rows 30, 31, 32 and 22 do not run**.
The row 29 keep rule fires: kept as the diagnosis, stop and escalate with the table.

**Questions:**

1. **The next row is fields, not time and not the yard - do you agree?** The shape I would write: when a planet holds no
   legal object because its fields are full, the persona's wants put the object that adds fields first (read from the host:
   the building-queue object whose `consumes_field` is false and whose next level raises the planet's `field_max`), and it
   does so *before* the store overflows. Gate 3 is easy to name - every OGame player builds terraformers when their planet
   fills up - and it names no object (gate 1), since the catalogue's own `field_max`/`consumes_field` columns say which
   object it is. Expected: `ok` objects at day 251 stops being 0 and the waste after day 120 falls from 78-96%.
2. **Does this change row 30's arms, or replace them?** T and Y as written both have no problem to solve. I can run the
   fields row as row 30 with the same 10-seed wave (one arm, plus R if you still want the mix measured alongside), or
   write a new row 30 and leave T and Y in the log as refuted with their numbers.
3. **R (row 27) is still the only arm with a real growth effect (mid p50 126 → 231) and it broke `eco_share` alone.**
   With fields unblocked, buildings get a place to go again, so the mix may behave differently - do you want R in the same
   wave as the fields arm (two arms, one baseline) so the interaction is measured, rather than R alone against a
   blocked world?
4. **`planets` (my last question 3): row 27's fall has gone with the revert, so nothing to do.**
5. **One thing the table cannot show:** how many fields a *colony* has and whether the colonies are stalled the same way.
   The sample reads each planet's queue but the reason breakdown is the capital's. If question 1 is yes, I will sample
   every planet's reasons so the row can say whether the trap is one planet or the account.

### Planner's answer (27 Oct, row 29)

Good diagnosis. One thing it uncovered goes deeper than the persona: **the simulator cannot leave the trap at all.**
The host adds fields through `PlanetService::getPlanetFieldMax` (terraformer +5 per level, +1 per two levels; lunar
base +3 per level), while `ogsim` keeps a fixed `field_max` per planet. No rule could have built its way out.

1. **Yes, fields. First the mechanic, then the rule.**
   - **Row 36 (fidelity):** the catalogue export probes the host for each object's `fields_added[level]`, deriving it
     and naming nothing. The simulator adds those fields to the cap. It is kept whatever HDS does, because a missing
     host mechanic is a bug.
   - **Row 37 F:** your fields-first rule, reading row 36's column.
2. **T and Y are recorded as refuted** (rows 30 and 31 are marked), and the new wave is row 37. Your own table also
   shows a second gap. The yard orders at 94-100% of full-store logins, yet units take only 0.3-1.1% of production, so
   the orders are tiny (`DEFENCE_BATCH = 10`, fixed plans). That is arm **Y2**: the yard order is sized on the surplus.
3. **Yes, R goes in the same wave**, and row 38 stacks the arms and judges the mix as a whole.
4. Agreed.
5. **Yes, sample every planet**, plus the research queue's reasons and the yard's ordered value over the surplus.
   They go into `stall.py` before row 37's arms.

Host note for the owner: `getPlanetFieldMax` names its objects, a host-side Gate 1 debt. The module derives the data
by probing, so the flow does not wait on it.

Run: 36 → 37 → 38 → 32 (conditional) → 33 → 34 → stop (stage exit).

### Planner (27 Oct, after row 36) — two checks before row 37 is judged

1. **The hard-constraint violations went from 5 298 to 17 617** in row 36, and the row does not say why. The host's
   detector thresholds are hard gates (`humanlike.py`). Before row 37's arms, split the 17 617 by kind (action < 10 s,
   dark period < 6 h, logins in ≥ 18 hours of the week) against row 29's 5 298. A field-adding object should not change
   when an account logs in. If one kind tripled, find the path (for example, the session clock waking on more build
   ends) and fix it as part of row 37's baseline. Write it into row 37.
2. **`econ-u1.json` has no `fields_added` column**, and row 34 trains on it. Regenerate it with row 36's command before
   row 34; otherwise the learner trains in the world that still has the trap.

## 27 Oct — row 37 (F / Y2 / R): no arm reaches its gate, and the trap has moved to the colonies

Two checks first, both answered.

1. **The violations did not triple; the seed count did.** The same three seeds on both worlds give **5 298** on each,
   kind by kind: `action_under_10s` 0, `dark_period_6h` 5 250, `login_hours_under_18` 48. Row 36 ran ten seeds, and
   17 617 = 5 298 x 10/3 (per seed 1 762 against 1 766). A field-adding object cannot move login timing, and it did not.
2. **`econ-u1.json` now carries the column**: `php -d memory_limit=4G artisan ai:rl-catalogue
   storage/rl/ogsim/units-parity/u1.sqlite storage/rl/ogsim/units-parity/econ-u1.json --max-level=40` (24 planets, no
   sites). Every other field is identical to the previous file, no planet's bare `field_max` moved, and
   `cargo test --release` 39/39 - so row 34's training world will not be the one with the trap.

Row 37's wave (10 seeds each, one baseline, the default season checked line for line against row 36's - **0 of 23 360
lines differ outside the new samples**):

| arm | HDS | `wasted` after day 120 (base 75.1%) | the number its gate names | decision |
| --- | --- | --- | --- | --- |
| **F**, fields first | 0.749 | **68.1%** (-7.0 points) | the gate asks for **-20** | reverted |
| **Y2**, the yard spends the surplus | 0.752 | 74.8% (-0.3) | `spent_units` stays **0.6%** of production; the gate asks for -20 | reverted |
| **R**, row 27 again | 0.750 | 74.0% | `res_share` in-band **0.45 -> 0.87** at 128-256 (gate met), but growth in-band **0.47 -> 0.43** against +0.10 | reverted |

**What the new census (per planet, the lab, the surplus) added, and it is the important part:**

- **The capital is clear and the colonies are sealed.** After row 36 the capital's "no fields" is 0 at every age, but the
  objects blocked by "no fields" summed over the account run **6 / 14 / 28 / 39 / 52 / 52 per account-day** by age band,
  and the account's `ok` objects fall **25 -> 12**. The colonies' sites are 100-200 fields and they fill up; the capital
  has room because its fields now grow. F fires on a planet with one free field, which is why it bought 7 points of waste
  and not 20: **a colony that is already sealed cannot start the object that adds fields at all**, and it takes time for
  each new colony to reach the same wall.
- **The yard's ceiling is its store room, not the stock.** Y2 sized the order on the surplus and `spent_units` did not
  move from 0.6% of production: `UnitQueueService`'s `max_amount` caps the batch by the shipyard's store room
  (`u1`'s units each occupy a slot), so more stock cannot buy more units until the store is bigger. The yard orders at
  100% of full-store logins already (row 29), so nothing about the *decision* was ever the problem.
- **R's mix trade is the same one row 27 measured**, and now with the same consequence: `res_share` goes clean out of the
  giveaways (0.45 -> 0.87 in band) and `eco_share` pays for it (0.71 -> 0.42), and growth does not rise.

**Questions:**

1. **The next row is colonies, not the capital.** My reading: F needs to fire *before* a colony is sealed (one free field
   is already too late for a colony whose site is small), and the rule that decides what a colony builds is the same
   player rule on every planet - so the row I would write is "a planet that will seal within its next few builds takes the
   field-adding object first", with the reserve sized from the site's own field count, measured on `ok` objects and
   `wasted` after day 120. Is that the row, or do you want the **store-room** chain first (the unit store that caps the
   yard), since Y2 showed the yard cannot spend without it?
2. **Row 38 cannot stack anything** (no arm kept), so it does not run. If question 1's row is kept, does 38 then stack it
   with R (whose only failure is the mix, which 38 would judge as a whole)?
3. **The two checks are done and committed**; row 34's world now has the column, so it does not need a re-run of row 36's
   export.

### Planner's answer (27 Oct, row 37)

Both checks are good: the "tripling" was the seed count (10 against 3), and `econ-u1.json` now carries the column.

1. **Colonies, yes. But the yard first, because your explanation of Y2 does not hold.** The host caps a yard batch only
   by price, one-of-a-kind domes and the silo's slots for the two missiles (`ObjectService::getObjectMaxBuildAmount`).
   `units-u1.json` gives a `store` to ids 502 and 503 alone, and `units.rs::max_amount` follows that. Ships and defence
   have no store room, so something else refused or shrank Y2's orders. **Row 39 traces it** (requested, returned, the
   binding bound) and picks the fix in advance. My bet is `upgrading`: since row 36 the building queue is busy, and the
   host refuses the yard while a blocking station is being raised. The yard is the one sink with no limit, which is
   where a real player's surplus goes, so it matters more than any other fix.
2. **Colonies: row 40's F2.** F fired too late because the way out has prerequisites on the same planet. F2 keeps a
   reserve of fields for the field-adder's own chain, derived from `requires`. It runs as one arm of the wave, beside
   the yard fix row 39 picks.
3. **Row 38 is replaced by 41** (the stack of row 40's arms). R is not stacked: it failed on growth, not only on the
   mix. Row 33's single spending order takes the research question up.

Run: 39 → 40 → 41 → 32 (conditional) → 33 → 34 → stop.

### Planner (27 Oct, root cause: owner asked for more effort on why we are not moving faster)

I read the raw seasons myself instead of the row summaries. Row 36's world, every active account-day after day 120
(`e36/base-*.jsonl`, 77 265 account-days):
- research spend 0 on **94%**; building spend 0 on **84%**; the capital has **nothing legal** on **76%**;
- the capital is sealed by fields on **80%**, and **64%** of those have nanite 0, so the field-adder can never be
  reached;
- the yard spends fixed batches (the most common values: 120 000-200 000 a day), while a store at cap holds up to 100M.

**Root cause: the teacher is a greedy one-step rule.** `Baseline::Payback` takes the best payback *now* and has no
lookahead, so it walks into absorbing states (a sealed planet, prices above the store cap, research frozen behind
them). Every row since 23 has found one of those states, and patching them one per row is why progress is slow.
**Second cause: summaries were not checked against raw output.** Row 36's "no fields 13 → 0" is contradicted by its own
lines (80% sealed), and row 37's store-cap explanation is contradicted by the code. I accepted both. From now on:
- `census.py` produces every headline number from the raw lines;
- the planner re-reads the raw lines before answering a row.

**Course:** 39-41 are folded or superseded.
1. **42:** the complete dead-end census.
2. **43:** headroom. A lookahead teacher (rollouts of 7 and 30 days with the current rule) against the greedy one,
   never measured on the world.
3. **44:** adopt the lookahead teacher if it pays.
4. **Then 32, 33 and 34.** The learner imitates the lookahead player and improves on it with PPO (expert iteration),
   which is what the learner is for.

Implementer: in row 42, say why `stall.py` reported 0.

## 27 Oct — rows 42-43: the census names the dead end exactly, and a lookahead teacher cannot price it

**Row 42 (kept).** `census.py` replaces `stall.py` and reads the season's new per-planet sample (used/cap/sealed, the
reason each building object cannot start with the first missing prerequisite and the first unaffordable resource, the
lab's reasons on the planet that pays, and the yard's surplus against `max_amount`'s own capacity). Three seeds, 365
days, 4.2 min each; the script runs in 6.7 s.

| age (days) | planets with anything legal | sealed by fields | lab spent 0 | lab never affordable | yard below surplus | yard capacity is the bound |
| --- | --- | --- | --- | --- | --- | --- |
| 1-30 | 89 % | 17 % | 26 % | 5 % | 67 % | 53 % |
| 31-60 | 50 % | 59 % | 33 % | 38 % | 100 % | 69 % |
| 61-120 | 27 % | 77 % | 71 % | 75 % | 100 % | 47 % |
| 121-180 | 20 % | 83 % | 88 % | 83 % | 100 % | 40 % |
| 181-250 | 14 % | 91 % | 94 % | 85 % | 100 % | 39 % |
| 251-365 | 6 % | 96 % | 96 % | 91 % | 100 % | 36 % |

Attribution of 135.3 bn of unspent production after day 120: **capital sealed with the field adder unreachable 76.9 %,
"no want" 21.3 %, research frozen 1.9 %, yard 0.0 %, unexplained 0 %** (coverage 100 % against the 90 % gate).

**Reachability, the number the planner asked for:** of **145 236** sealed planet-days the object that adds fields is
startable on **0 %**; blocked by a missing prerequisite on **79 %** - **object 15 (the nano factory) on 73 %**, object 113
on 6 % - and by its own price on **21 %**. The dead end is exact and structural: the terraformer (object 33, +5 fields at
level 1) requires object 15, which **itself consumes a field**, costs 1.6 M metal-equivalent and 75 hours of building.
So the chain that would unfreeze a planet needs a free field, which is the thing the planet has run out of. It is
enterable only *before* the seal, and nothing in a payback-ranked rule ever enters it.

**Why `stall.py` said "no fields 0" while the raw lines said 80 %:** line 71 of the old script took the **median** of the
per-account-day reason counts over **every** line of the age band. Most lines belong to accounts that quit in the first
fortnight (`quit_day_median` 8.7) and whose frozen planets block nothing, so the median landed on the quitters' `ok 11 /
no fields 0`. The share over *active* account-days was 80 % all along. My row 36 prose quoted the medians; the raw lines
were right. `census.py` reports shares over active days and per-planet counts, never a median over everything.

**Row 43 (not kept - the run stops).** A `Baseline::Lookahead { days }` teacher rolled every legal candidate forward
with the Player rule on a copy of the planet (bounded to the 8 cheapest candidates x 12 builds) and took the one with
the most metal-equivalent produced plus in store. Ten seeds x 365 days against the same ten-seed baseline:

| arm | produced | ratio | per seed | wasted > 120 (base 75.1 %) | sealed | seconds |
| --- | --- | --- | --- | --- | --- | --- |
| base | 3 121.25 bn | - | 273-363 bn | 75.1 % | 72.3 % | 15 |
| lookahead 7 days | 3 021.58 bn | **0.97x** | 0.93-1.01 | 70.0 % | 71.0 % | 262 |
| lookahead 30 days | 3 091.07 bn | **0.99x** | 0.97-1.01 | 69.1 % | 70.8 % | 347 |

The gate was 1.3x. The probes agree at every horizon (7 days 0.82x, 120 days 0.93x, 365 days 0.97x), so this is not a
horizon that needs to be longer. **The mechanism is the finding: the roll is played by the Player rule itself. When a
roll reaches the seal that rule returns nothing and the roll stops, so every candidate's future is the same sealed
planet and the lookahead can only re-rank what the one-step rule was going to do anyway. A value estimate over a policy
cannot price an action that policy never takes.** Rolled out and reverted; the default season is byte-identical to row
42's after the revert, 39/39 tests.

**Questions:**

1. **The rule is what is wrong, not the horizon.** The census says the chain must start while fields are free, so the
   teacher needs a *reserve* rule (keep N fields, and when the planet is within N of its cap build the field chain
   first, prerequisite before the thing it unlocks) - a one-step rule with a lookahead horizon is not the mechanism that
   finds it. Shall I write that row (fields reserved from the planet's own `field_max`/`consumes_field` data, no object
   named), or do you want the *world* checked first: **real OGame colonies get a fraction of the capital's fields, and
   ours get 100-200-field sites that seal in a fortnight. Is the colony site size right?**
2. **The lab is priced above the store cap on 91 % of days** (`never affordable`, rising with age): research above
   roughly level 12-20 costs more of one resource than the store can hold, so no waiting ever pays for it. That is either
   a world bug in the store table or a real OGame mechanic I have modelled without its relief (the host's own store
   buildings at high levels). Rows 27/37's "research share" work cannot fix it; it needs a world row. Which comes first,
   this or the fields?
3. **Row 32/33/34 did not run** (the stack needs a kept arm, and there is no teacher to imitate). If question 1's row is
   kept, does 33 (one spending order) then follow directly, skipping 32's raider condition?
