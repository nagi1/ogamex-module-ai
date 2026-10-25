# North-star plan: from "beats our own rule" to "plays like a human" (26 October 2026)

Owner of this file: the planner (Opus). Implementers (Sonnet, DeepSeek) take **one row of §6 at a time** and follow it
literally. This file overrides the work order in `WORLD-SIM-PLAN.md` §4. That file stays the record of the simulator
and its parity (W1-W16). Binding as always: `Modules/AI/AGENTS.md` (three gates), `.github/skills/ogame-rl-training/SKILL.md`.

Read §1-§5 once (15 minutes), then only your row, §7 (rules) and §9 (schemas).

---

## 1. Why we are held back (the honest answer)

The simulator is not the problem. It replays the host exactly on every mission (3 200 cases, 0 differ). The machine is
not the problem either: a 6 M-step PPO run takes 12 minutes, and the errand policy converges in 4. RL is not broken. It
did exactly what it was asked: the building model is +44% to +100% over the player rule, and the errand model is +1.9%.

**We are held back because the goal is not in the loop.** The north star is "an account a human cannot tell from other
humans across the whole game". No run, reward or gate measures that. Every number we optimise and report is "account
value against our own scripted rule, over ten days". That has four consequences, and each one explains a symptom the
owner sees:

| Symptom the owner sees | Real cause |
| --- | --- |
| "We keep going in circles" | When a relative number saturates (errand +1.91% through W13, W14, W16 and an 8-knob sweep), the only moves left are knob turns. No measurement says *which part of the player is least human*, so no row can be chosen by size of gap. |
| "Good RL needs rewards and feedback, we don't have it" | The reward is value change, and value is not the goal. A value maximiser with ships counted at price hoards fleet: the errand policy holds **x392 the teacher's fleet**, while real players hold a median **6-16% military share**. Nothing penalises leaving human behaviour, so RL drifts towards an efficient bot, which breaks Gate 3. |
| "A powerful machine, yet training takes long" | Training is fast. What is slow is the *iteration*: each cycle adds one mechanism, then judges it on one narrow number. The CPUs sit idle most of the day because there is no question cheap enough to ask them repeatedly. A whole-game season of 64 accounts × 60 days costs seconds per core. It has never been run. |
| "Enormous data we cannot use" | The real data holds **no human actions**. PUB (267 universes) gives snapshots of outcomes: scores by category, planets, moons and alliances. OStats gives 561 hourly curves, OGMem 599 battles, and the forums and wiki are text. You cannot clone a human from it. Its correct use is as the *target distribution* (what humans end up looking like), and we have not used it anywhere in the loop. Our own 15 GB of recorded choices is the planner's behaviour, a weak teacher. |
| "We have the source code and still stuck" | The source code bought exact parity, and that is done. But the scripted players that teach and oppose the models play **4 of 9 missions and never colonise**. Our accounts hold 1 planet and 0 moons at day 10. Real active players hold a median of 2 (bottom half), 10 (middle) and 16 (top 10%) planets. A model cannot learn what is not on its menu: W14 proved the menu is the ceiling. |

Two more structural faults hide those gaps:

- **The models never play together.** Each kind trains with the rule playing every other kind (`python.rs`: errand mode
  plays the rule's economy). No world runs past day 10, so the late game, moons, the jump gate and deathstars never
  happen in the simulator.
- **Simulator to PHP is the slow manual step.** Every kind rediscovers the skew between training and serving: M5 took six
  variants, and W12 is blocked on a layout mismatch. There is no generic feature referee like the mission referee that
  worked.

**The fix, in one sentence:** make the goal a number. Compute it every few minutes in the simulator from the real data,
let the size of each gap in it choose the next row, and add it to the reward as a constraint. Everything in §6 serves
that sentence.

## 2. Where we stand (numbers to cite, 26 Oct)

| Part | State | Number |
| --- | --- | --- |
| Simulator parity | done | 9 missions, fleet 20k/20k, units on 3 universes, economy 0 gap |
| Building model (M1-M5) | served; closed loop passed | sim +44..+63% over the player rule (`bh-s*`); PHP twin worlds ΔV mean +9.7%, **median 0.99** |
| Research model (M6) | gates 1-3, not served | sim +8 to +30% |
| Errand model (W10-W16) | **saturated** | +1.91% (CI +0.2..+4.1), 37-46% of decisions differ, fleet x392 the teacher |
| Our growth against real players | measured once (`human-growth-check.md`) | planner **0.21x** the real median at 7.3 speed-days, ~1/100 of the real p90 |
| Our account shape at day 10 | measured | 1 planet, 0 moons, no colonies, expeditions, missiles or moon shots by scripted players |

Real active players (PUB, status `''`, all universe ages pooled, computed 26 Oct; reproduce in N2):

| Rank band | n | military share p10/p50/p90 | research share | economy share | planets p10/p50/p90 | moons |
| --- | --- | --- | --- | --- | --- | --- |
| top 10% | 5 617 | 0.007 / 0.058 / 0.351 | 0.075 / 0.208 / 0.453 | 0.275 / 0.50 / 0.737 | 11 / 16 / 18 | 9 / 15 / 18 |
| 10-50% | 6 921 | 0.015 / 0.16 / 0.514 | 0.043 / 0.129 / 0.294 | 0.343 / 0.614 / 0.87 | 2 / 10 / 14 | 0 / 6 / 14 |
| bottom 50% | 1 671 | 0.0 / 0.066 / 0.45 | 0.0 / 0.136 / 0.361 | 0.362 / 0.685 / 1.0 | 1 / 2 / 6 | 0 / 0 / 1 |

Caveat: real OGame v13 has lifeforms and Dark Matter, which OGameX does not model. Shares are less affected than totals,
so use shares and counts first and totals only matched by speed-days.

## 3. Is the methodology right? What stays, what changes

| Choice | Verdict | Why |
| --- | --- | --- |
| Whole game in a host-exact Rust simulator | **keep** | It is the reason the loop can be minutes, not days |
| One small model per decision kind | **keep** | Owner direction. It is debuggable and fits the PHP seam |
| BC from the scripted teacher, then PPO with a KL anchor | **keep, but the teacher must become a full player first** | Anchoring to a teacher that never colonises anchors the model away from humans |
| Reward = change in account value | **change**: value **plus** a penalty for leaving the real bands (N7) | Value alone produces an efficient bot, not a human |
| Judge = value against the scripted rule | **demote to a floor** (no worse than -5%) | The north star is the human distance (§4). Value only guards against playing badly |
| 10-day worlds of 8-12 accounts | **change** to 60-day season worlds of 64 accounts with real-like inactives | Shares, planets, moons and percentile rank only mean something at that size and length |
| Train each kind with rules for the rest | keep for training; **evaluate jointly** (N8) | The deployed player is all models at once |
| Adversarial imitation (GAIL) from human actions | **not possible**: no human action data exists | Use outcome-level distribution matching instead (bands plus a classifier test, §4) |

## 4. The north-star measure (every run reports it from now on)

Only what one player can see of another counts (`plan/details/research/account-authenticity.md`): the highscore, the
galaxy view, and what arrives at their planets.

**Measures with a real band (from N2).** Each is computed per account per simulated day:

| Measure | Real source |
| --- | --- |
| total score / speed-days (growth) | PUB score type 0, universe age |
| economy, research, military share of total | PUB score types 1-3 |
| planets | PUB planets |
| moons | PUB `has_moon` |
| galaxies spanned by own planets | PUB planet coords |
| in an alliance (later) | PUB player `alliance_id` |
| share of hours with score growth (activity) | OStats hourly |

**Behaviour measures with a host rule, but no real band:**
- logins spread over 18 or more hours of the day in a week, which the host flags;
- a dark period shorter than 6 h;
- an action less than 10 s after the previous one.

These are hard constraints: their violation count must be 0.

Two numbers summarise a run. Both are computed by `rl/scripts/hds.py` (N4):

1. **HDS, the in-band share:** the share of (account, measure) pairs inside the real p10-p90 of the account's matched cell
   (rank band × speed-days bucket). By construction a real account scores about 0.8. **Target ≥ 0.75.**
2. **Turing AUC:** a classifier tells real accounts from ours on the same measure vector, in the matched cell, with 5 folds
   grouped by universe or seed. It uses logistic regression and gradient boosting (scikit-learn), and reports the larger AUC. 0.5 means indistinguishable. **Target ≤ 0.60.** The measures with the largest weight are *what gives us
   away*, and §6 is ordered by them.

**Floors that block a change regardless of HDS:** value against the teacher no worse than -5% (CI), and 0 hard-constraint
violations.

**Matching rules:**
- A sim account's rank band is its percentile among all accounts of its world, inactives included, exactly as a human
  reads the highscore. The world therefore needs real-like size and inactivity: **64 accounts, about half of them going
  inactive** at a drawn day. Real universes are mostly inactive: en198 has 1 336 `I` of 2 441.
- Speed-days = days × economy speed.

## 5. The feedback ladder (ask the cheapest level that can falsify)

| Level | What | Wall time | Hardware |
| --- | --- | --- | --- |
| L0 | `cargo test --release`, Pest for referees | ~1-3 min | CPU |
| **L1** | **season + scorecard**: 3 seeds × 64 accounts × 60 days, `hds.py` | **≤ 3 min** | CPU, one process per seed |
| L2 | training: BC + PPO, then L1 on the checkpoint | ≤ 20 min | CPU actors, learner on the 4070 |
| L3 | feature referee: PHP vectors equal to simulator vectors | ≤ 10 min | docker |
| L4 | PHP closed loop, scored by the same `hds.py` | hours | docker, ~26 sims |

Rules for the ladder:
- Never run level n+1 to answer a question level n can answer.
- Every change is judged at L1 at least.
- L4 runs only at a milestone the planner names.

## 6. Work rows (N-rows)

Each row gives: goal, why, files (the implementer owns only these), steps, **done when**, **stop when** and a time box.
Lanes A/B/C can run in parallel. Within a lane, one agent at a time.

### Lane B (Python, data and metrics): start now, no dependencies

#### N2 Real bands: `rl/scripts/real_bands.py` → `storage/rl/ogsim/real-bands.json`

- **Why:** the target distribution. Without it nothing in §4 exists.
- **Files:** `rl/scripts/real_bands.py` (new).
- **Inputs:** `Modules/AI/storage/rl/external/sources/PUB-*/normalized/{server,player,score,planet,alliance}.jsonl` and
  `OSTATS/normalized/{score_point,universe_catalogue}.jsonl`. These are module-local (`Modules/AI/storage`), not the repo
  `storage`.
- **Steps:**
  1. Per PUB universe, read the following:
     - `speed` from `server.jsonl`;
     - universe start = **min `found_date` over `alliance.jsonl`**. This is an estimate. Drop a universe with no
       alliances, and print how many were dropped;
     - snapshot time = `captured_at`;
     - speed-days = (captured − start) / 86400 × speed.
  2. Read the player status. Active = `rank_status == ''`. Every other status contains `i`, `I` or `v` and is excluded
     from the bands, but **counted** for the percentile rank.
  3. Per player, read the following:
     - total (score type 0), economy (1), research (2) and military (3);
     - shares = category / total (skip total ≤ 0);
     - percentile = position of type 0 / number of ranked players;
     - planets, moons, distinct galaxies (`planet.jsonl` by `owner_id`);
     - alliance = `alliance_id` not null.
  4. Use these cells:
     - rank band: `top10` (pct < 0.1), `mid` (< 0.5), `bottom` (rest);
     - speed-days bucket: `[0,8) [8,16) [16,32) [32,64) [64,128) [128,256) [256,∞)`.
   - Also write the same cells for the speed-8 universes alone (`speed8|...`). `hds.py` prefers them for speed-8
     seasons.
  5. Per cell, write `n`, and p10, p50 and p90 for each measure, plus `sample`: up to 2 000 raw measure vectors (for the
     classifier).
  6. From OStats, per player with a known universe and a full 24 h: `activity` = share of hours whose score rose. Write
     it as one band per rank band. The age is unknown, so it is not split by age.
- **Done when:** the file is written and the pooled numbers reproduce §2's table within ±0.01 on shares and ±1 on counts.
  Cells with n < 30 are listed as unusable, and the script prints the cell table.
- **Stop when:** the pooled numbers do not reproduce. Report the difference and stop; do not "fix" the data.
- **Time box:** 3 hours.

#### N4 Scorecard: `rl/scripts/hds.py`

- **Why:** the one number of §4, and the ranked list of giveaways that chooses the next row.
- **Files:** `rl/scripts/hds.py` (new). scikit-learn, numpy (both in `.venv-rl`).
- **Input:** one or more season JSONL files (schema §9.1) and `real-bands.json`.
- **Steps:**
  1. Per account, take the snapshot at the **last day**. Also take every 10th day, for the growth measure.
  2. Assign the cell: the account's percentile in its world on day d, and speed-days = d × the world's economy speed.
  3. Print these tables:
     - per measure: ours p10/p50/p90 against the real values for each populated cell, and the in-band share;
     - **HDS**;
     - the hard-constraint violation counts;
     - the **Turing AUC**: logistic regression and histogram gradient boosting, `GroupKFold`(5) by universe or seed.
       Report the larger AUC;
     - the top 5 giveaways: permutation importance from the boosted model, beside each measure's in-band miss rate.
  4. `--baseline file.json` compares with a saved scorecard and exits 1 when HDS drops by more than the 3-seed spread or
     a floor fails.
  5. `--self-test`: split the real samples 50/50 and score one half as "ours". **HDS must come out ≈ 0.8 and the AUC ≈
     0.5.** This proves the yardstick before it judges anything.
- **Done when:** the self-test passes (HDS 0.75-0.85, AUC ≤ 0.55), and the script runs on a fake 3-account JSONL written
  from §9.1.
- **Stop when:** the self-test AUC is > 0.6. That means the features leak the source (for example the universe speed);
  drop the leaking feature and report it.
- **Time box:** 4 hours.

### Lane C (PHP referee): start now

#### N1 Score parity: the simulator's highscore equals the host's

- **Why:** every band is in *score*, and the simulator only knows value. A score computed differently from the host's
  makes every comparison wrong. This is the same referee pattern that gave exact missions.
- **Files:**
  - PHP: `app/Actions/Rl/DrawAiScoreCasesAction.php`, `app/Console/Commands/RlScoreCasesCommand.php`, a Pest test, and
    the provider binding (stage your hunk alone);
  - Rust: `rl/ogsim/src/score.rs` (new), and one line in `lib.rs`.
- **Steps:**
  1. Read `app/Services/HighscoreService.php` (lines 100-340) and the planet methods it calls
     (`getPlanetMilitaryScore` and the like). Write down, in the PR description, the exact rule for each category: what
     counts as military, economy or research; how fleets in flight count; and the rounding.
  2. Write a PHP command `ai:rl-score-cases {universe} {out}`, modelled on `DrawAiMissionCasesAction`:
     - draw 600 accounts with random building levels, research, ships, defence, and 0-3 fleets in flight;
     - call the service for the four scores;
     - write the state and the scores;
     - roll back the writes.
  3. If the units export lacks what the host uses to split military from economy, **add that field to the export** (Gate
     1: never name a unit in Rust).
  4. Rust: `score::of(world, account) -> Score { total, economy, research, military }`, with a test `equals_host` over
     every case.
- **Done when:** 0 differences over 600 cases (integers, to the unit). Pest is green, and `cargo test` reports the count
  of cases, not just a pass.
- **Stop when:** a difference has no explanation after 2 hours. Report the smallest failing case.
- **Time box:** 1 day.

#### N9 Generic feature referee (unblocks serving for research and errands, and replaces W12's one-off)

Starts after N8. The planner may pull it forward if Lane C is idle.

- **Why:** skew between training and serving cost M5 six variants and blocks W12. Make it a referee once, for every kind.
- **Files:**
  - PHP: `ai:rl-feature-cases {kind} {universe} {out}`, which dumps (state snapshot, the PHP encoder's vectors, the mask)
    from real seam calls;
  - Rust: `rl/ogsim/tests/feature_parity.rs`, which loads each snapshot into a `World`, computes the vectors and diffs
    them.
- **Steps:**
  1. Kind 0, then 1, then 2.
  2. For kind 2, first apply W12's decision: build PHP's errand vectors in the simulator's 12+12+7 layout.
- **Done when:** for every kind, max |difference| ≤ 1e-5 and identical masks on ≥ 300 states.
- **Time box:** 2 days.

### Lane A (Rust simulator): one agent at a time on `world.rs`, `players.rs`, `errands.rs`, `python.rs`

#### N3a Season runner (rules only): `rl/ogsim/examples/season.rs`

- **Why:** the whole player over the whole game, in seconds. This is L1.
- **Files:** `rl/ogsim/examples/season.rs` (new); `players.rs` only to add an `Inactive` behaviour if needed.
- **Arguments:** data dir, days (60), accounts (64), seed, catalogue (speed), out JSONL.
- **Steps:**
  1. Build the world as `dump_humanlike.rs` does. Put the coordinates over 3 galaxies (systems spread as in PUB).
  2. Use this persona mix: Miner 40%, Raider 30%, FleetSaver 30%.
  3. **Make about half the accounts inactive:** each draws a quit day from an exponential with a mean of 15 days. After
     it, the account never logs in, and the world's `inactive` flag follows the host's rule.
  4. Call `enable_idle_logins`.
  5. Each simulated midnight, write one line per account (schema §9.1).
  6. Before N1 lands, the four scores come from a `score_stub` (value split by kind). Mark this `"score_source": "stub"`
     so `hds.py` refuses to compare totals and uses counts only.
- **Done when:**
  - 3 seeds × 64 accounts × 60 days at speed 8 finish in **≤ 3 minutes wall time**, one process per seed. If not,
    profile and report the hot spot; do not optimise blindly;
  - the JSONL validates against §9.1.
- **Time box:** 1 day.

#### N5 First reading and diagnosis (implementer runs it, planner reads it)

- **Depends on:** N2, N3a, N4. N1 is welcome but not required.
- **Steps:**
  1. Run L1 on 3 seeds and save `storage/rl/ogsim/runs/season-baseline/`. Paste the `hds.py` output into §8.
  2. Answer each diagnosis with **a direct measurement**, not a guess:
     - **D1 planets:** ours against the real values per cell. Expected: 1 against 2-16.
     - **D2 moons:** expected 0.
     - **D3 growth:** total score per speed-days against the real bands at matched age.
     - **D4 military share:** for the scripted personas. Separately, for the errand model, decompose the **x392 fleet**.
       Plot the teacher's fleet value over time and its ships lost in battle (from `world.events`) against the model's.
       Is the ratio the teacher *losing* its fleet in raids (the model avoids losses), or the model *buying*? Answer in
       one sentence with the numbers.
     - **D5 reach at day 60:** which objects, missions and moons exist at all. Adapt `rl/scripts/reach.py`'s lists to read
       names from the catalogue, not hardcoded ones.
- **Done when:** §8 has the table and five one-sentence answers. The planner then orders N6's sub-rows by the giveaway
  list.
- **Time box:** half a day.

#### N6 The teacher becomes a full human-like player

Scripted players, Gate 3, doctrine as data. One sub-row at a time, **in the order N5's giveaway list gives**. The
expected order is below. Each sub-row is done when its measure moves into the band at L1 and HDS rises beyond the 3-seed
spread, with no other measure leaving its band. If its measure does not move, revert, log it in §10, and go to the
next sub-row.

- **N6a Colonise.**
  - The scripted rule colonises when the host's planet cap from research allows a new planet (read from the export,
    never a number in code).
  - It picks a position from the real position histogram, which N2 adds to `real-bands.json` as `positions`.
  - It builds astrophysics like a player does: the next planet slot is a standing research goal from early mid-game.
  - Add a `colonise(site)` candidate to `errands.rs` so the model can learn it too.
  - **Measure:** planets per cell.
- **N6b Expeditions.**
  - Add an `expedition` errand for a player whose fleet is idle at a login. The world already flies expeditions exactly.
  - **Measure:** score growth, and, indirectly, the military share.
- **N6c Moons.**
  - Diagnose first: real top-band players hold a moon on nearly every planet, and the host makes moons only from debris.
    Measure how often our battles produce a moon chance at all.
  - Then add the scripted "moon shot" a player makes: an attack sized to leave the debris for the maximum chance. Its
    sizing is read from the host's moon-chance rule in the combat export.
  - **Measure:** moons.
- **N6d Persona mix.** Size the personas so the military-share distribution matches the real one per rank band. This is
  data in `resources/doctrine/`, not code.
- **N6e Late game.**
  - Only now, and only if D5 shows missing late objects that real top players hold: phalanx, jump gate and ACS (the open
    remainder of WORLD-SIM-PLAN W8).
  - Each needs its host referee first, as W3 did.

**Time box:** 1 day per sub-row.

#### N3b Joint play: `VecWorld(kinds=[0,1,2])` and `rl/ogrl/season.py`

- **Files:** `python.rs` (one owner), `ogrl/season.py` (new).
- **Steps:**
  1. A controlled account's decisions of every kind come out on one stream (the `kind` field already exists).
  2. `season.py --model 0=path --model 1=path --model 2=rule` plays them and writes §9.1 lines.
- **Done when:** with every kind set to `rule`, the output equals N3a's on the same seed, line for line.
- **Time box:** 1 day.

#### N7 Reward with the human constraint

- **Files:** `python.rs` (the reward at a ticket close and at the end), `ogrl/train_world.py` (one flag).
- **Design:** reward = Δvalue as today, plus, **at the end of the game only**, `-λ · Σ_m d_m`. Here `d_m` is how far the
  account's measure m sits outside its cell's p10-p90, in log units (0 inside the band).
  - One λ, `--band-lambda`.
  - Measures come from N2's file, loaded once.
  - Nothing else changes.
- **Done when:** on L2 with the errand kind (then building), the controlled accounts' HDS ≥ the teacher's HDS, the value
  floor holds, and the fleet decomposition from D4 is inside the military band.
- **Stop when:** at three values of λ (0.1, 1, 10 of the value scale), HDS does not move. Report; the planner decides.
- **Time box:** 1 day.

#### N8 Joint evaluation, then retrain on top of the full teacher

1. Run L1 with every trained model at once (N3b). Report HDS and the floors.
2. Retrain BC, then PPO + KL, then the band reward, for each kind, **with the N6 teacher**. Do it in the order of the
   giveaway list, not in the order kind 0, 1, 2.

The work is done when the joint player's HDS is ≥ 0.75, its AUC ≤ 0.6 and the floors hold, on 3 seeds.

#### N10 PHP closed loop judged by the same scorecard (milestone L4)

1. Write an adapter that turns a finished PHP world (`end.sqlite`, the same reads as `world_scorecard.py` and `reach.py`)
   into §9.1 lines.
2. **The fidelity gate:** with the same policy, HDS from PHP is within 0.05 of HDS from the simulator. Then M-gate 4 as in
   `next-steps.md`.

#### N11 Opponent league

This row comes last. Opponents become past checkpoints mixed with the personas, so a raid model does not overfit to one
scripted victim. Do it only after N8 passes.

## 7. Rules every implementer follows

1. **Before writing code**, write two lines in your report: the §4 measure your row moves, and its current value (from
   the latest L1 run).
2. **Measure, then change.** A gap is diagnosed with a direct measurement before any fix (memory
   `no-circles-diagnose-first`).
3. **The no-effect rule.** A change that does not move its measure beyond the 3-seed spread is reverted and logged in
   §10. Two flat attempts on the same measure mean you stop and escalate to the planner. Never sweep knobs on a flat
   metric.
4. **Time boxes are hard.** At the box, stop and report what you have.
5. **Gates.**
   - Gate 1: no object named in Rust, Python or PHP code. Bands, positions and doctrines are data.
   - Gate 2: the smallest mechanism. A mainstream library is fine when it removes code (§12.3); no new framework.
   - Gate 3: every scripted behaviour is something a player does. Name it in the commit message.
6. **Files and git.**
   - Own only your row's files.
   - `git add <files>`; never `-A`, `stash`, `reset`, `clean` or a force-push.
   - Do not touch the owner's modified files (`app/Domain/Decision/*`, `rl/ogrl/serve.py`, `train_ppo.py`,
     `closed_loop.sh`, `plan/tasks/*`).
   - Commits end with the attribution line the session gives.
7. **Hardware.**
   - CPU up to 90% of 72 threads; parallel processes; never pin cores.
   - GPU: learners on cuda:1 (the 4070) with `CUDA_DEVICE_ORDER=PCI_BUS_ID`. The 3090 (cuda:0) is the owner's: at most
     about 20%.
   - Never `pkill -f` (it kills your own shell). Find PIDs with `ps` and `awk`.
8. **Report format** (at most 8 lines): row, measure before → after (with the spread), files, commit hash, the next thing
   the number suggests.
9. **Paths.** Artifacts go under `<repo>/storage/rl/ogsim/runs/<row>-<short>/`. Real data lives in
   `Modules/AI/storage/rl/external/sources`.

## 8. Readings (newest last; paste `hds.py` output here)

| Date | Row | HDS | AUC | Top giveaways | Floors |
| --- | --- | --- | --- | --- | --- |

## 9. Schemas (fixed so the lanes do not block each other)

### 9.1 Season line (JSONL, one per account per simulated day)

```json
{"seed": 1, "world_speed": 8, "day": 12, "account": 5, "persona": "raider", "controlled": false, "inactive": false,
 "score_source": "host|stub", "score": {"total": 0, "economy": 0, "research": 0, "military": 0},
 "planets": 1, "moons": 0, "galaxies": 1, "alliance": false, "value": 0.0,
 "fleet_value": 0.0, "logins_today": [3600, 7400], "events_today": {"spy_sent": 0, "raid_sent": 0, "raid_received": 0,
 "save_ok": 0, "save_late": 0, "recycle": 0, "expedition": 0, "colonise": 0, "missile": 0, "moon_shot": 0,
 "ships_lost_value": 0.0, "loot_value": 0.0}}
```

The fields are fixed. Add new fields at the end. Never rename one.

### 9.2 `real-bands.json`

```json
{"version": 1, "made": "2026-10-26", "measures": ["growth", "eco_share", "res_share", "mil_share", "planets", "moons", "galaxies", "alliance"],
 "cells": {"top10|16-32": {"n": 0, "p10": {}, "p50": {}, "p90": {}, "sample": [[...]]}},
 "activity": {"top10": {"p10": 0, "p50": 0, "p90": 0}}, "positions": [/* 15 shares, slot 1..15 */],
 "dropped": {"no_alliance_universes": 0, "status": {}}}
```

## 10. Closed experiments (do not repeat)

| What | Result |
| --- | --- |
| A per-hour reward (errand) | +1.90% against +1.91%: reverted |
| W13 crediting each fleet event to its dispatching ticket | identical +1.91% |
| W14 richest scouted targets on the menu | +1.91% |
| W16 yard order as a quantity choice | +1.89..+1.95%, inside the CI |
| Sweep over ent, KL, width and lr (`sweep1`) | all within +1.8..+1.95% |
| 15-minute run against 5-minute run | identical: convergence is at about 4 minutes |
| Dropping the `order` candidate | no change |
| BC width and balance sweep (18 runs, PHP data) | spread of 1 point: settings are not the lever |

What these say together: on the current menu, teacher and reward, the errand kind is at its optimum. The next gain
comes from the measure, the teacher and the menu (N2-N7), not from the learner.

## 11. Order and parallelism at a glance

```
now ─┬─ Lane B: N2 ──► N4 (self-test) ─┐
     ├─ Lane C: N1 (score referee) ────┼──► N5 first reading ──► N6a..e (by giveaway) ──► N7 ──► N8 ──► N10 ──► N11
     └─ Lane A: N3a season runner ─────┘                            └── N3b joint play ──┘   Lane C: N9 after N1
```

Expected first useful number (N5): about 2 days of agent time. Nothing in Lane A trains a model before N5. Training
resumes at N7, and then every training run ends with an L1 reading.

## 12. Decisions (the planner's; the owner left them to engineering, 26 Oct)

1. **"Indistinguishable" is first judged on the cross-section a player sees:** the highscore by category, the galaxy
   view (planets, moons, positions) and inactivity. That is the only part with real numbers behind it (PUB, 267
   universes). The behaviour a target sees comes next, at the step after N8: login rhythm, reaction latency to a probe
   or attack, save success and raid cadence. For now it is held to the host's hard rules, and its bands come from
   OStats activity. Chat and diplomacy stay out of this plan (rules plus readers, `next-steps.md` §1).
2. **The target speed is economy speed 8.**
   - 196 of the 267 real universes run at 8, so the bands are densest there.
   - Our simulator runs and the M5 closed loop already ran at 8.
   - Seasons run at 8. Speeds 4 and 16 are robustness checks only, and their bands come from speed-days matching.
3. **Tooling: use what does the job.** scikit-learn 1.9.1 is installed in `.venv-rl`.
   - `hds.py` uses `LogisticRegression` and `HistGradientBoostingClassifier`, with `GroupKFold` by universe or seed, and
     reports the larger AUC.
   - For the giveaways it uses permutation importance from the boosted model.
   - An implementer may add a mainstream library (for example pandas or polars) when it removes code. Say so in the
     commit.

## 13. Row status (implementers update this; one line per row)

| Row | Lane | Status | Agent / date | Commit | Note |
| --- | --- | --- | --- | --- | --- |
| N1 | C | claimed | copilot:lane-c 26 Oct |  |  |
| N2 | B | done | copilot 26 Oct | (pending) | real-bands.json: 21 cells (19 usable), pooled table = §2 on all 24 p10/p50/p90; n differs -3/-9/-25 (ESCALATIONS) |
| N3a | A | done | copilot:lane-a 26 Oct | (pending) | season.rs: 3 seeds x 64 accounts x 60 days at speed 8 in 1.32-1.43 s, 3 840 §9.1 lines each, schema exact |
| N4 | B | open (after N2, or against a fake file) | | | |
| N5 | A | blocked on N2, N3a, N4 | | | |
| N6a-e | A | blocked on N5 (order set by the planner from N5) | | | |
| N3b | A | open after N3a | | | |
| N7 | A | blocked on N3b, N6 | | | |
| N8 | A | blocked on N7 | | | |
| N9 | C | open after N1 | | | |
| N10 | C | blocked on N8 | | | |
| N11 | A | blocked on N8 | | | |

Status words: `open`, `claimed`, `done`, `blocked on X`, `stopped: <reason>` (time box or a stop condition hit;
see `ESCALATIONS.md`).
