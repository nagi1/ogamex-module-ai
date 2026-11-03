# The training run: the way out of the circles (1 November 2026)

This file is the work order for training. It overrides RL-FLOW.md's "train_ppo.py is the one trainer": the world the
accounts are judged in only exists behind `ogsim.SeasonWorld`, which `train_world.py` drives.

## Objective

One trained player whose accounts a detector cannot tell from real ones, over a whole 365-day season.

| Number | Now (teacher, e99i) | Target |
| --- | --- | --- |
| Pooled AUC (`hds.py`, 0.5 = cannot tell) | 0.908 | ≤ 0.60 |
| HDS (in-band share) | 0.771 | ≥ 0.75, must not fall |
| Health checks | PASS | PASS |

Nothing else is a result. Value against the teacher is not reported as a gain.

## Why we circled

1. 100 rows patched the scripted teacher by hand: HDS 0.757 to 0.771 in a week. Each fix exposed the next gap.
2. Every learner was rewarded for something other than the goal, and judged on that same thing.
3. A trained model could not reach the scorecard at all: `hds.py` reads season lines, and only personas wrote them.

The way out: the goal goes into the reward, the scorecard judges the model directly, and the gap closes by training
instead of by a new rule per gap.

## Mistakes found in the trainer (all read from the code or the raw e99i lines)

| Mistake | Effect | Fix |
| --- | --- | --- |
| Reward counts unspent stock at full price | Hoarding pays as well as spending; accounts hold about 170 days of production (host score counts no stock) | Reward is the host's score |
| Reward and result read the capital only | A transfer to a colony reads as a loss; colonies invisible | The whole account |
| Model decides one queue, capital only | Research share cannot move on a buildings model; 2% against a real 12% | Both queues, every planet |
| Model sees one planet | Cannot know its age, rank or shares, so cannot aim at a band (N0a named this 26 Oct) | Eight account columns |
| Discount per decision | 330 decisions is days late in a season with 12 planets; a mine's payback is weeks | Discount by game time |
| A season's end treated as game over | The real game does not end; teaches end-of-season dumping | Bootstrap from V at the horizon |
| BC collected the first N rows | Only the opening is ever cloned | Whole seasons, sampled |
| Value head untrained after BC | First updates are noise on a good policy | Value warm-up |
| No explained variance logged | Cannot tell a useless critic (N0a) | Logged |
| Calibration 64 × 365-day worlds in one process | Hours before training starts | Scale saved by BC |
| Evaluator defaults to the owner's 3090 and Python | Against the GPU rule, too slow | Off; Rust season judges instead |

## The reward

    r = Δ ln(1 + score) + λ · Σ over midnights crossed of clip(logit D(account's measures), ±5)

**From the OGameX code.** `score` is `score::of`, equal to `HighscoreService` on 600 accounts, 0 differ. It is
resources invested / 1000 in buildings, research and units, moons and fleets in flight included; a store counts
nothing. It falls when a fleet is lost and does not rise when one is built from nothing, so nothing needs adding for
either.

**From how the game is played.** The highscore is what players compete on and the only thing another player sees.
Growth compounds, so `ln`: doubling is worth the same in week 1 and month 10, where raw points make the first week
1/1000 of the last and the opening invisible to the gradient. Resources in a store earn nothing and invite a raid:
spend them. Mines pay back over weeks, so the discount horizon is about 66 game days (γ 0.985 per day). The universe
goes on after our season, so its end is a cut, not an ending. An account that quits ends its own stream.

No bonus per raid, colony or build, ever (`reward-design.md` §4): an idle queue, a full store and an energy shortfall
already cost growth, and the ledger shows 3% waste, so they are not the gap.

**From the data.** `D` is a small network trained on `real-bands.json`'s samples: 267 universes, up to 2 000 real
accounts per cell of rank band and universe age, against our own accounts at the same rank and age. It reads the eight
measures `hds.py` reads, standardised inside the cell, and answers "does this look real". It is the detector itself,
so it learns what gives us away instead of a planner naming it. Days 15-63 have no real universe, so they carry no
term. Retrained on the policy's own seasons each round, so the policy cannot settle on one trick (adversarial
imitation; here only outcomes are real, which is what the data holds).

Not used, and why: OGMem's 599 battles describe fleets, and the model does not decide the yard yet. OStats' hourly
curves are 46 varying series of one day. The forums and wiki are text, and they reach the players as doctrine.

## Steps

| Step | What | Passes when | If not |
| --- | --- | --- | --- |
| A | Clone the teacher, play it in Rust, judge with `hds.py` | HDS and AUC within 0.02 of e99i; BC top-1 reported | The chain is broken: fix before any PPO |
| B | Three arms, one change each, same BC start: λ 0 (growth only), λ 0.02, λ 0.1 | Health clean; invalid actions 0; KL 0.005-0.03; EV above 0.3 | KL ≈ 0: BC too deterministic, raise entropy once. EV stays low: reward too noisy, report |
| C | Each arm's model through 10 seeds × 365 days, `hds.py` | **Keep:** pooled AUC below e99i by more than the seed spread, HDS ≥ 0.75, health PASS | See below |
| D | Retrain D on the best arm's seasons, continue from its model | AUC keeps falling | Stop rounds at the first round with no fall |

**Decided in advance, after C:**
- AUC falls with HDS held: run rounds of D until it stops falling, then a PHP closed loop on the best model.
- λ 0 grows and the others do not move AUC: the menu is the limit. Next the yard joins the menu (`mil_share` is the
  persona's today). No learner knobs.
- Nothing moves in any arm: read what the model does (stock, research share, passes) before changing anything.

**Step A's first reading (1 Nov), kept as the reason for the `advised` column.** The clone reached 93.6% top-1 and still
sat on 1 planet all year (HDS 0.696, planets 19% in band, teacher 86%). The teacher through the model's door scored the
same as the teacher (HDS 0.767), so the door is sound. The teacher reads what no column says, the research that opens a
planet slot and the prerequisites of the ship it wants, so the model gets the teacher's pick as one candidate column and
learns when to leave it. PHP serving needs that pick too (`DecideAiEconomyStepsAction` already has the planner's).

## How an arm runs (measured 2 Nov)

Rust plays and decides on every core (`SeasonWorld.collect`, `season::Policy`); Python only moves finished rows to the
learner. Through a GPU batch one arm made 10.7k decisions a second with the CPU mostly idle waiting on the round trip;
this way 17 cores make 31k alone, 16-23k each with three arms running (about 50k together).

| Number | Value |
| --- | --- |
| One arm of 34 worlds on 17 cores | about 7-9 GB, of which learner 2.5 GB |
| Start-up, worlds warmed to random days | about 3 minutes |
| A judge (10 seasons at once) | about 5 GB, up to 5 minutes under load |
| Learner | 5% busy; 3-5 GB of GPU memory |

Sum the processes before launching and stay under 30 GB in `rl.slice`; `rl/scripts/guard.sh` runs throughout.

## Trainer faults found by reading the first arms (2 Nov)

| Fault | Reading | Fix |
| --- | --- | --- |
| Reward scale sized on one decision (0.0114), returns add many | Value loss rose to 205, KL 1e-5: the value gradient filled the shared clip, the policy did not move | Scale is the measured RMS of a rollout's returns: 0.2 (λ 0, 0.02), 1.0 (λ 0.1). Value loss 0.3-1.8, EV 0.94 |
| Logged losses kept their graphs | GPU out of memory at 262k rows; small batches hid it | Detached |
| Warm-up midnights billed to an account's first decision | Single returns of -155 at λ 0.1 | The detector does not judge warm-up days |
| 128 decisions per world per hand-over is 2 per account | GAE cut to one or two steps | A rollout is 8192 decisions per world, 128 per account, about 17 game days |

Entropy rises from 0.25 while KL stays low. It rose before the fixes too, so "shared layers" was not the whole cause.
Measured by queue and season phase (`runs/train1/pays.py`): same-queue choices differ by about 0.03 in advantage against
a spread of about 0.6, the order of the entropy bonus's pull at low probabilities, so where the reward barely separates
choices the bonus can win. Arm `arm02e0` (λ 0.02, no bonus) tests this against `arm02`.

## The judge plays as training plays (2 Nov)

A checkpoint played greedy left the teacher on 0.0000 of rows while sampled play left it on 20%: the greedy judge cannot
see what the policy has learned, so every checkpoint read as the clone. `judge_policy.sh ... sampled` plays it sampled.

The clone itself, unchanged, moves with this alone:

| Clone played | HDS | Pooled AUC |
| --- | --- | --- |
| Greedy | 0.767 | 0.931-0.943 |
| Sampled | 0.805 | 0.905 |

Sampled clone is the baseline for step C. One reading so far; its spread needs more than one run before a small gain counts.

## First arms: no gain over randomness (2 Nov)

Every line is 10 seeds x 365 days, sampled, against the lifeform-free real bands (below).

| Player | Entropy | HDS | Pooled AUC |
| --- | --- | --- | --- |
| Teacher (rule-base) | - | 0.768 | 0.909 |
| Clone | 0.27 | 0.810 | 0.900 |
| Clone, temperature 2, untrained | 1.63 | 0.873 | 0.805 |
| Clone, temperature 3, untrained | 2.11 | 0.872 | 0.823 |
| λ 0, update 120 | 1.3 | 0.871 | 0.803 |
| λ 0.02, update 160 | 1.3 | 0.877 | 0.815 |
| λ 0.1, update 120 | 1.3 | 0.874 | 0.823 |
| λ 0.02, no entropy bonus, update 320 | 0.07 | 0.770 | 0.942 (read on the old bands) |

A trained arm sits where an untrained clone of its entropy sits: the gain is randomness, not learning. With no entropy
bonus the policy returns to the teacher's greedy play. Near-random buying outscores the teacher, so the teacher's build
order is not what limits the score; randomness is not the fix, since the goal is a good player.

**Why the detector still tells us apart: one style across all accounts.** Against real, with the clone at temperature 2:

| Measure | Ours | Real |
| --- | --- | --- |
| Moons, mid band, day 128-256 | 9 on 9 planets | 5.5 on 10 |
| Moons, bottom band | 1 on 1 planet | 0 on 4 |
| Growth, mid band, p10-p90 | 3.8k-6.1k (1.6x) | 0.9k-46k (48x) |
| Top growth | 38k | 99k |
| Top research share | 1.8% | 12.6% |

**Tried and removed (teacher, 10 seeds):**

| Arm | Change | HDS | AUC | Reading |
| --- | --- | --- | --- | --- |
| `effort` | login gaps scaled per account, p90/p10 46x | 0.781 | 0.944 | 46x in gaps gives 2.7x in growth: logins are not the lever |
| `labtime` v1 | a research over a week waits for a lab upgrade | 0.756 | 0.914 | Top lab 8 to 13, research +53%, median jobs last 10 hours so it rarely fires |
| `miltaste` | military share drawn per account, 5% to 58% | 0.848 | 0.778 | Worse than `moontaste` alone (0.869, 0.760) |
| `labtime` v2 | research waits while share is under 12% and a lab can rise | 0.729 | 0.942 | Capital lab 27 by day 30, network still 0 at day 200: starves the research that opens the network |

Research points a day are capped near 24 x 8 x (1 + pooled labs). 12% of a top score needs about 170, reachable only with
the network and colony labs; the teacher's median account has no network level until day 300 under v2. Open.

**Kept: `moontaste`.** Mid accounts mooned nearly every planet (9 on 9) against a real 5.5 on 10. Each account now wants
its own share of its planets mooned; the top tenth wants them all. On the temperature 2 clone: AUC 0.800 to 0.760, HDS
0.873 to 0.869. The draw runs a little low (median 4), so the moon band fell from 0.89 to 0.84. Open.

**Kept: `netchain`.** An account with at least 2 colonies and 60 days of production idle in its capital works through the
prerequisites to the research network, one level per colony; colony labs rise to the capital's; a research over a day
waits for a payable colony lab. On the teacher with `moontaste`: HDS 0.769 to 0.786, AUC 0.894 to 0.897, top accounts at
day 364 104M to 209M points, stock 134B to 34B, pooled labs 10 to 180. Without the surplus gate mid accounts lost their
economy (median score 6.7M to 1.4M). Top research share stays 1.4%: their labs sit idle 47% of the time. Open.

**Kept: `merchant` (row W16, the trade only).** A capital with a resource piled at more than `MERCHANT_IMBALANCE` times its
scarcest pays 3,500 dark matter and swaps at a rate drawn the way the host draws it. Against `rule-net3` on 10 seeds x 365
days: HDS 0.788 against 0.786 and AUC 0.902 against 0.897, no change on the scorer; top research 3.89M against 2.59M and top
labs idle 25% against 47%. Kept because it is a real mechanic that hurts nothing. The first run of it (top 221M, research
3.54M) is void: the trade did not write the stored stock, so the next delivery undid the payment and the merchant printed
resources. The health check caught it (`unexplained_over_cap` 6); a test now holds the case.

**Kept: `techtree`, and two sim faults it exposed (2 Nov).** On 10 seeds x 365 days, the teacher alone, against the merchant
run: HDS 0.878 against 0.788, AUC 0.828 against 0.902, research share in band 72% against 32%, top labs idle 0% against 25%,
health PASS. The levels now look like a player's (top at day 200: plasma 14, weapons 17; before: plasma 0, weapons 7).
- `next_research`: the lab walks requirements to what raises output, and a rich account takes the cheapest payable research.
  `purpose` only looked at research it could start, so plasma, behind laser 10, was never wanted and astrophysics took it all.
  Colonisation research comes first up to `SETTLED_PLANETS` (8, real mid at 64-128 speed days).
- **Sleeping lab (sim fault).** With a research held by a lab upgrade on another planet, the queue slept until every queue did,
  which a busy account never reaches. This, not the hold itself, was the idle lab of every earlier reading: earlier research
  numbers were all understated by it, and any run before this fix read a world with a broken lab.
- **Energy prices (sim fault).** The catalogue had no energy column, so graviton was free and terraformers had no limit. The
  exporter now writes `energy_price`, and `stall_of` holds an object short of it: buildings against energy made, research
  against energy free (`ObjectService`). The installed catalogue was patched from the host's own prices, not re-exported.
  Fields now cap near 300-350 as the terraformer's energy runs out.
- **Frozen planets (fault in the player).** With energy in force, a planet holding its last field for a terraformer it could not
  power waited for good, and a colony lab it would never build held the account's research too. `power_by_units` now buys the
  energy the chain asks for and `chain_powered` releases the field when the planet cannot pay. HDS 0.888, AUC 0.810, top
  research 28.6M (14%), mid 4.36M (19%), health PASS. Still open: top stock 106B and top military 41% against 7-24% real.

**Removed: `latejoin` (2 Nov).** Accounts signing up on a uniform day of the year, against the teacher's HDS 0.888, AUC 0.810:
60% late 0.831, 0.872; 30% late 0.869, 0.834. Real snapshots carry no sign-up date, so the share was a guess. It fixed the low
end of mid growth and emptied the top. The comparison it came from is the lesson: at 256-512 days real mid growth is
343 / 3,383 / 41,409 (p10 / p50 / p90) against the teacher's 4,076 / 5,774 / 7,425, real top 20,967 / 112,963 / 354,414 against
8,152 / 53,184 / 69,713. The missing spread is at the upper end: real strong players grow far faster than ours.

**Noise, measured (2 Nov).** The same model on seeds 1-10 and 11-20: `ct2` HDS 0.826 and 0.818, AUC 0.788 and 0.775; `b10` update
160 HDS 0.863 and 0.846, AUC 0.771 and 0.781. A difference under about 0.015 is no difference. `b10` beats `ct2` on HDS in both
seed sets (0.03-0.04) and ties it on AUC. With the 0.003 entropy bonus the arms drift towards uniform (2.48 of 2.53); started from
the temperature 2 clone with no bonus, entropy falls instead (`c0` 1.74 to 1.06 by update 81): the reward alone moves the policy.

**The economy that froze at day 200 (2 Nov).** Levels of top and mid accounts were identical at days 200 and 364 and stock piled to
106B, because of four faults. Teacher alone, 10 seeds x 365 days: HDS 0.920, AUC 0.788, top 294M, research share in band 89%, health PASS.
- `chain_powered` compared satellites with buildings, but a building needs the field being held. Energy for the chain now comes from units,
  and the field goes only if they cost more than `CHAIN_POWER_DAYS` (30) of production.
- **Sim fault:** the export's satellite table ends at 1,000, and output stopped there (39,600 energy). The host is linear (`floor((tmax + 140) / 6) * n`);
  `own_row` scales past the last row. A re-export is not needed.
- `power_by_units` spent the stock the login had just committed to a research, which then logged off. It now keeps the pick's price.
- The `netchain` hold waited on colonies that would never raise a lab, being down to their chain's fields. They no longer count.
- `next_research` takes the cheapest payable research under the real share too, not only with surplus: money now goes to buildings, and mid
  accounts had weapons 0 at day 200.
The no-bonus arms `c0`, `c02`, `c10` were stopped on the older world: AUC 0.775-0.823 against the temperature 2 clone's 0.788, no learning shown.
Entropy fell to 0.17 with lambda 0.02 and held near 1.8 with lambda 0.1. Next: stronger realism weights on this world, run `train3`.

**`train3` (2 Nov).** The teacher's clone at temperature 1, no entropy bonus, detector-0 (teacher and clone seasons), and three arms at
lambda 0.1, 0.3 and 1.0 (`d10`, `d30`, `d100`). Baselines: the sampled clone `bc0` HDS 0.885, AUC 0.763. KL per update stays at 1e-4 to
3e-4 with clip 0: entropy is 0.15-0.2, so most rows are near-certain. `d10` update 80: HDS 0.896, AUC 0.790, research share in band 0.70
to 0.76. That is no gain: HDS inside the noise, AUC worse by more than it.
The clone's top tenth holds research at 1.3% of score against the teacher's 11.6%, with no research network or graviton. The teacher
waits on about half its decisions to save for research, and a sampled pass that turns into a cheap building never saves.

## The yardstick was noisy (fixed 2 Nov)

`hds.py`'s boosted model ran unseeded, with early stopping on a random split: byte-identical seasons read pooled AUC 0.749,
0.756, 0.762, 0.765 and 0.778. The ±0.006 used above came from three readings and is too small; differences under about
0.03 before this fix are not results. It now averages five seeded fits and identical seasons read identically. This fixes
scorer noise only: the spread across season seeds is still unmeasured.

## The real bands had kept lifeform points (fixed 2 Nov)

The owner's 29 Oct rule never reached the yardstick: `real_bands.py` still used the published total, which counts
lifeform points. Totals and ranks now read `lifeform_free`. Top growth 117k to 99k, top research share 10.6% to 12.6%. The
old file is `real-bands-with-lifeforms.json`. `detector-*.json` were trained on the old bands.

Learner settings are not experiments (NORTH-STAR §10). Fidelity gaps (replay) are read alongside and do not block
training; the rule fixes go in between rounds, and a changed world means a rerun of step A.

## train4: the finished world (2 Nov, `storage/rl/ogsim/runs/train4`)

The world now flies joint attacks and defence, wreck fields, moon raids and planet moves. On it, 10 seeds x 365 days:

| Player | HDS | pooled AUC |
| --- | --- | --- |
| rule (teacher) | 0.898 | 0.791 |
| clone `bc0`, sampled, after the clock fix | 0.880 | 0.775 |

- **Per-account skill** (`skill=LO:HI`, each account its own sampling heat) does not help. On the nine shared healthy
  seeds the plain clone scored 0.883 / 0.752; one heat of 2 for all scored 0.857 / 0.771, spread 0.5-4 0.872 / 0.772,
  and spread 1-8 0.849 / 0.762. Not trained on.
- **Clock fault fixed** (`16d3f5f8`): a slot whose next event moved later jumped there in one step past the world's
  schedule. One seed in ten failed the health check on it. The colonise and expedition case replays had been failing
  since 30 Oct (missing page load at the landing); all 14 missions are exact again.
- **Realism reward hacked** (round 1, `collapsed/`): realism 0.3 with the train3 detector and no anchor, from the
  clone at heat 2 or 4. By update 320 both arms stopped building: HDS 0.84 -> 0.30, median score 6.9 M -> 0.14 M,
  one planet. Most real accounts barely grow, so the detector reads doing nothing as human. The realism term needs an
  anchor or a floor on growth.
- **Round 2** (running): `g0` growth alone with KL anchor 0.05 (4070); `r3` realism 0.3 from this run's
  `detector-0.json` with anchor 0.3, and `r1` realism 0.1 with anchor 0.1 (3090). Judged every 80 updates.
- **Owner, 2 Nov: win first.** Realism is parked: train for the highest score using everything in the game, then tune
  for human-likeness. Round 2's realism arms were stopped. Running: `g0` (score only, anchor 0.05, gamma 0.985/day),
  `w0` (score only, no anchor, gamma 0.995/day: the final score weighs more), `w1` (score only, no anchor, 0.985).
  Arms are ranked by median and top score against the clone (6.96 M median, 270 M top). The reward telescopes to
  ln(final score) - ln(start), and the host score counts only resources spent, so stock and timing cannot game it.
  The model decides buildings and research only; ships, defence and fleet missions are still the personas'.
- **Fleet errands join the season (2 Nov, `c0a9cc41`, `5d0220df`).** `SeasonWorld(queue=2)` gives the fleet errand at
  every capital login of the first `controlled` accounts to a model (`fleet-model=N`: their persona keeps economy and
  yard); `train_bc_world --season --errands` clones the scripted errand; the judge plays `errand-policy=<weights>`.
  Plumbing check with the teacher through the model's door for all 64 accounts: HDS 0.788 / AUC 0.870 against the
  normal mix's 0.898 / 0.791 - every account becomes a miner with the raider's errands, so the mix is not the same
  world; the errand model is judged against this check, not the normal mix. The errand path ran 7x slower (a scan of
  the whole event log per login); an index made it as fast as a normal season.
- **Health false alarms fixed:** a raided planet above its cap gets salvage (the host's collector rule) with no
  delivery; the over-cap check now counts it. Two judges (w0, w1 at 80) had failed on it and were requeued.
- **Memory:** a trainer takes ~10 GB, a season ~0.7 GB. Three trainers plus 20 extra seasons hit the 46 GB cap and
  the slice killed `g0` (resumed from its update-131 save). Keep three trainers plus one judge at most.
- **Unanchored score arms lose (2 Nov).** w1 (no anchor, gamma 0.985) at 160: median 5.99 M, top 287 M; w0 (no anchor,
  gamma 0.995) at 160: median 4.99 M, top 228 M; against the clone's 6.96 M / 270 M and g0 (anchor 0.05) at 80:
  7.56 M / 234 M. Entropy fell to 0.46-0.62 without the anchor; planets stayed at 7, so not gaming but drift. Stopped.
  Running: g0 (anchor 0.05, 4070), g1 (anchor 0.02, 3090), e0 (the fleet errand model from its clone, 99.7% top-1 on
  333 k rows, anchor 0.05, 3090). The fleet clone `bce0` is judged first as e0's baseline.
- **The median was measuring quitters (2 Nov).** Half the accounts quit by design, so the all-account median sits on
  the edge between quitters and players and swings with nothing (the "+9 %" and "-28 %" above were that). The judge
  now reads the accounts still active at the end (`judge.sh`, `look.py`). Re-read on active accounts, median / top /
  planets: clone bc0 20.98 M / 270 M / 11; g1 (anchor 0.02) at 80 19.94 M / 251 M / 11; w1 at 160 21.21 M / 287 M / 11;
  w0 at 160 20.17 M / 228 M / 11; g0 resumed (~291 updates) 6.69 M / 213 M / 9 (collapsed: the resume anchored to the
  drifted checkpoint). PPO lands within +-5 % of the clone: a fixed point, KL ~1e-3 per update.
- **Next arm g2** (4070): anchor 0.05, gamma 0.995 per day and GAE lambda 0.97 - credit that reaches the slow payoffs
  (astrophysics, colonies, large mines) that a 0.985/0.9 horizon of ~65 days barely sees. g1 and e0 continue (3090).
- **Suspected blocker: the advice flag (2 Nov, 22:00).** The season's build state carries the persona's own pick as a
  candidate column. The clone copies it (100 % top-1 in 52 s) and every PPO arm from it sits at a fixed point. On the
  single-planet economy (25 Oct), with no such flag, PPO beat the player by +28 to +34 %. Test: arm `noadvice` drops the
  column in training and judging; `bc-noadvice.pt` clones from the state alone, is judged as `bcn0`, then PPO from it.

- 2 Nov 22:30. g2 (KL .05, gamma .995) judged at 80: active median 20.6M, 11 planets. Same as the copy model (21.0M); stopped at a fixed point.
  The judge script was edited while a queue was running it, so g2's scoring died. Rescored from the saved seasons. Never edit judge.sh under a running queue.
  DAgger (`rl/ogrl/dagger_world.py`) starts from bc-noadvice: the clone plays, the persona labels every state it reaches. Smoke test: on its own states the clone matches the persona about 80% of the time, against 94% on the persona's own games.
  Run d1: 4 rounds of 600k rows, on the 4070. dqueue.sh judges each round when no other judge is playing. Bar to beat: bc0 20.98M.

### 2 Nov 23:15 — root cause of the stalled training: the world's shared dice

Every PPO arm since the clone (g0, g1, g2, l1, w0, w1, e0) ended on the clone's ~21M. The training mechanics were healthy (value net
explains ~90%, KL 1e-3, entropy falling 1.5 to 0.3), so the signal was the suspect, not the learner. The per-day discount (0.995 = ~200 days) was ruled out.

Measurement (6 seeds x 7 variants, persona only, `skip=A:D` and `jolt=D` probe arms). Change in an account's final score:

| change | median | p90 | max |
| --- | --- | --- | --- |
| same season twice | 0% | 0% | 0% |
| one extra draw of the world's dice on day 30 (luck only) | 7.3% | 30% | 11x |
| an account passes on one build on day 30 (a decision) | 0% | 2.9% | 26% |

One shared stream fed every fleet event, colony draw and the model's own samples. Any change re-dealt the luck of the whole world, so a
decision's return was ~all luck and PPO learned noise. Fix: `keyed` dice. An account's k-th fleet event, colony field and site draw, and the
model's samples, come from its own stream. Same distributions; off in the parity replays (all 14 missions still exact, 86 lib tests pass).
With keyed dice the luck-only change drops to median 0%, p90 5.9%; what remains is real interaction (raids between accounts).

Now standard in SEASON_ARMS and judge_policy.sh; judged.txt is marked where keyed starts. The bc0 bar is rejudged on keyed dice.
Test of the fix: g2's exact settings on seeds 1 and 2 (k1 on the 4070, k2 on the 3090). If they rise above the rejudged bc0, the root cause is confirmed.
Second reading: one skipped build barely moves an account's year, because the persona builds it next login. The build model's leverage is in
its systematic choices, not in single picks.

### 2 Nov 23:40 — what the score is made of (keyed dice, 6 paired seeds, persona only)

Keyed dice did not unblock the build model: k1 at 80 updates scores 20.87M against bc0 rejudged on keyed dice, 21.01M. So I measured what moves the score.
Drop one rule, change in the world median:
- expedition off: -37% to -42% (all seeds)
- factory off: -2% to -12%
- netchain, labfirst, doctrine, balance, techtree off: within a few %, either sign

Sending every cargo ship on expeditions (`expofull`) changes nothing (-4% to +2%).

The real split is the player class, which the simulator copies from the donor planet's host account and no player or model chooses:

| class | active (3 seeds) | median | over 100M |
| --- | --- | --- | --- |
| 1 Collector | 45 | 25M | 0 |
| 2 General | 37 | 22M | 0 |
| 3 Discoverer | 14 | 236M | 12 |

The host's Discoverer: expedition finds x (economy speed x 1.5) = x12 at speed 8, +2 expedition slots, Pathfinder (find doubler).
`app/GameMissions/ExpeditionMission.php` does the same, so the simulator is faithful.
Every account forced to Discoverer (`class=3`): world median x3.5 to x4.8 on all six seeds; former Collectors 24.5M -> 79M, Generals 21M -> 92M.
Caveat: the donor planet's own tables, such as a Collector's mine bonus, stay.

**Root cause for "win first":** the models train on the smallest lever. The build plan is worth a few %. The two big levers have no decision anyone takes:
- the class: x4. The host lets a player choose it (`CharacterClassService::selectClass`, first pick free).
- the expedition fleet: about 40%. It is not in the errand menu; a fixed rule flies it.

Next, in order:
1. Class choice as the account's first decision, with one candidate per host class, read from the host's class exports (gate 1: a modded class is reachable).
2. An expedition errand in the fleet model's menu.
3. Then score-only training of all three decisions on keyed dice.

The human class mix comes back with the human-likeness tuning.

### 2 Nov 23:55 — the class as a choice (faithful)

`class=N` now starts each account on the class-N donor home nearest its own home's temperature, so production rows, research time and fleet rules
all follow the class. The rules-only probe is gone. Without the arm, account a still starts on home a mod 24 (3 of 24 homes are Discoverers:
accounts 4, 9, 23, 28, 33, 47, 52, 57 every season). Earlier training controlled accounts 0-5 (one Discoverer); the fleet model 0-20 (three).

World median, 6 paired keyed seeds, persona only: as recorded 21-24M; all Collector 19-21M; all General ~17M; **all Discoverer 251-269M**.
Choosing Discoverer is x11 in this universe (economy speed 8: expedition finds x12, +2 slots, Pathfinder). No build-plan change comes near.
To verify against the original game: the Discoverer's resource find multiplier is economy speed x 1.5 in the host (`ExpeditionMission.php:1115`).

Win-first runs play `class=3`, chosen by this trial over the classes the donors have. The trial is the choice; no model needed for one pick.
The class mix comes back with the human-likeness tuning. Next: the expedition errand on the fleet model's menu. Then re-measure the build
levers in the Discoverer world (expedition slots and cargo hold research should matter more there).

### 3 Nov 00:10 — levers in the Discoverer world; it is the win-first yardstick now

World median change, 6 paired keyed seeds, all Discoverer, one rule off:
- expedition -95%
- factory -69/-70%
- doubler -50/-63%
- techtree -20/-56%
- colonies 0/-9%
- labfirst ~0
- all cargo flies (`expofull`) +2 to +8% on every seed

Here the build plan carries tens of percent and expedition sizing has measured headroom. `class=3` joins SEASON_ARMS and the judge; judged.txt is marked.
Runs dw1 and dw2: g2's settings (bc-t2, KL .05, gamma .995, lam .97), seeds 1 and 2, on both cards. The bar is bc0 judged in this world.
Root cause each run addresses: the decision now has leverage (measured) and the dice no longer drown it (keyed).

### 3 Nov 00:30 — new methods instead of more probes (owner: stop measuring, change the approach)

Bar: bc0 in the Discoverer world, 251M active median (top 314M).

1. **Evolution strategies on the build model** (`ogrl/es_world.py`, Salimans et al. 2017). This is the standard answer when a fast simulator exists and credit is long and sparse.
   - Each generation plays 18 fresh seeds twice: every account plays the policy nudged by +sigma, then -sigma, times its own noise row, on the same keyed dice.
   - The ln(final score) difference is the account's vote. The normalised votes give an Adam step. No credit assignment, no value net.
   - The objective is the final host score itself, so nothing in it can be gamed.
   - `es=NOISE:SIGN:SIGMA` in the season binary; `season::Policy::nudged` walks the trainer's parameter order.
   - Sigma 0.08: at 0.02 no decision flips (the clone follows the persona's flag by a wide margin); at 0.3 play collapses.
   - Run es1: from bc-t2, 36 seasons per generation on 36 cores. Checkpoint every 5 generations.
2. **Net-worth reward for PPO** (`networth` arm). The potential is ln(1 + score + income per hour x hours left / 1000), with income measured over the last 24-48 h (production kept, loot, expedition finds).
   - A mine earns its worth when it is built, as the OGame amortisation tables value it.
   - The hours left are 0 at the end, so a season's rewards still sum to ln(1 + final score).
   - Runs nw1 and nw2: g2's settings, seeds 1 and 2, 12 worlds each, on both cards.
3. dw1 was killed by the OOM killer when es1 started; dw2 and dw3 were stopped for es1's cores.

### 3 Nov 00:34 — the reward, from OGame practice and the real data we hold

Sources: `plan/details/research/veteran-play.md` (forum and guide claims, each verified against its page), the 267 public-API universes in
`storage/rl/external/sources/PUB-*` (26 of them at speed 8, as ours), and the host's `ExpeditionMission.php`.

**What winning is in OGame.** The highscore: total points, resources spent / 1000, falling when ships are destroyed. It is the game's own
measure and cannot be gamed by timing, so it stays the objective (win-first).

**How veterans judge a decision.** By payback, three sources independently:
- "prioritize mines with lowest amortization first"
- "Payback (hours) = Upgrade Cost ÷ Hourly Production Gain", "accept paybacks up to about 2-3 real days"
- "treat [research] like they are mines… build whatever has the shortest return on investment time"

The `networth` reward is that rule as a potential: ln(1 + points + income per hour × hours left / 1000). A build is credited with what it will
earn before the season ends. Expedition income is in the income because "expeditions are at the lowest estimate comparable to your mine income".

**What winners' points are made of** (real, lifeform points stripped; median shares):

| | economy | research | military |
| --- | --- | --- | --- |
| real top 10, speed 8 (26 universes) | 46% | 31% | 16% |
| real top 1%, speed 8 | 44% | 35% | 14% |
| real middle (p40-60), speed 8 | 73% | 18% | 1% |
| ours, top 10%, recorded classes | 63% | 11% | 28% |
| ours, top 10%, all Discoverer | 64% | 10% | 30% |
| ours, middle, recorded classes | 78% | 13% | 14% |

Real winners put a third of their points into research. Our personas, the teachers the clones copy, put a tenth. The models start from a
research-poor habit, and that is where the payback reward should pull them. Research composition is not rewarded directly (that is
human-likeness, parked), but it is the first thing to read on any checkpoint that beats the bar.

**To verify against the original game (owner):** the host gives the Discoverer economy speed × 1.5 (×12 at speed 8) on expedition resource
and ship finds (`ExpeditionMission.php:1115`, `:1154`). The guide sources say "×1.5". The brackets match the published table. If the
original is ×1.5, the Discoverer world (×11 median) is a host artefact, and the yardstick goes back to the recorded classes.

### 3 Nov 00:49 — decided: the Discoverer bonus is the original game's; the Discoverer world stays

The OGame wiki ("1.5×universe economic speed" on resources and ships gained during expeditions) and the Gameforge forums ("(Baseloot +
Classbonus) × ecospeed", worked example (1 + 0.5) × 5 × 2 = 15) agree with the host's `ExpeditionMission.php`. The host is accurate, so a
winning player in a speed-8 universe is a Discoverer, and win-first training stays in that world. The research gap stands as a weakness of
the personas: real speed-8 winners, many of them Discoverers, still hold a third of their points in research.

### 3 Nov 00:55 — two host gaps in expeditions, and two teacher fixes from the forums and the data

The forum's advanced expedition guide ("Expeditions & You") gives two original-game mechanics that `ExpeditionMission.php` does not have:
- the find scales with the fleet's expedition points, "(Fleet Structural Integrity * 5) ÷ 1000", capped by the top player's bracket;
- positions deplete, shared by every player, and "high depletion can drastically reduce the amount of rewards".

Both make OGameX expeditions richer than the original's, which fits our accounts' 30% military share against real winners' 16%.
**Host tasks, not AI work.** Training stays on OGameX as it is, and is retrained if the host gains them.

Teacher fixes (the clones and ES start from the teacher):
1. `RESEARCH_SHARE` 0.12 -> 0.33. With `balance` the persona stopped researching at 12% of its points. The 0.12 read real totals with lifeform
   points in them. Stripped, real top players at speed 8 hold 31-35%, and real middle players 17-18%.
2. `exposhield` arm: each expedition takes one of the account's strongest ships (most hull, from the host's tables), as the guide advises
   ("one combat ship (the strongest you have)"). In OGameX the ships a find can hold are capped by the best ship type in the fleet.

One paired check (6 Discoverer seeds) decides whether both join the win-first arms. Then the teacher is re-cloned and ES restarts from that clone.

### 3 Nov 01:55: sealed planets and the missing labs (root causes of the hoard and of research at 1%)

Read from the raw season lines of the Discoverer world (bc0, seeds 1-10), then confirmed on the rule alone (seed 1).

- **Sealed planets.** A third of the active accounts' planets ended the season full. The terraformer takes a field itself, so a
  full planet never grows again (`BuildingQueueService`, as in the original game). Two leaks:
  - The model's sampled builds took the fields the persona's reserve held: 131 sealed colonies, against 0 under the rule. Now
    `observe` leaves the model only the reserve's chain step or a pass while the reserve binds.
  - Under the rule, a capital short of terraformer energy for a moment released its last fields, and was sealed a day later
    with the satellites on order (traced on account 1, day 54.5). The building queue now always keeps the field; energy only
    gates research starting the chain early.

  Masked model, 10 seeds: median 250M -> 260M, median hoard 37B -> 20B. The fix for the last field is judged next.
- **No labs under the model.** At day 365 the rule's median account has 185 lab levels and a level-12 research network (11%
  research); the model's has 9 and 0 (1%). The clone copies the everyday builds but not the rare long-horizon ones, so DAgger
  from bc0 runs in the current world (`d2`, 3090; the stuck net-worth run `nw2` was stopped for it).
- **Rejected.** `labahead` (raise the lab between researches) never fired: identical to the base, as the capitals were sealed.
  Dropping `cap` (ships from all surplus): median 65M, military 66%, research 0.4%.
- **Net-worth PPO rejected.** Judged at update 80 in the Discoverer world: nw1 197.7M, nw2 196.6M, against the clone's 251.3M
  (-21%). Entropy fell and KL went to ~0 in both: PPO drifted to a worse fixed point. Both stopped; ES (final score itself as the
  objective) and DAgger carry the training.
- **Rule alone with both field fixes, 10 seeds: 266.5M** (research 11%, hoard 18.6B): the score the model now has to beat.

### 3 Nov 03:00: every model played without the lab (harness bug, fixed in 09c3c320)

- Greedy bc-t2 and ES generation 5 judged to the same number to the unit (267,316,254): five ES generations flipped no
  greedy decision. The advice input decides; ES restarts from the fixed world and is watched for a greedy change.
- DAgger round 1 matched the persona on 100% of the rows: nothing for it to teach, stopped.
- Under a model, research stayed at 1% (lab 8, no network) even played greedily. The persona's own picks through the model's
  path (`teacher-pick`) did the same: 8 lab levels, network 0, -12% at day 120. Cause: the model's legal set, the advice it
  reads and the training path's action check used "can start now", while the persona commits to the lab under a running
  research (hundreds of times a season) and the world starts it when the hold ends, as the host's queue does. Now all three
  read `World::committable`; the persona through the model's path matches the rule (labs 149.5, network 10, 48.5M vs 48.9M).
  Every clone, PPO and ES judgement before this commit played under that handicap.
- **New yardstick (fixed world, 10 seeds):** bc0 sampled 268.8M (research 11%), rule alone 266.5M, bc-t2 greedy 265.3M. From
  250.5M this morning by the two fixes alone.
- `labahead` on the fixed world: 265.8M against 268.8M, research unchanged (10.9%): rejected. The lab is busy every day and the
  capital's lab level is not what holds research at 11%.
- **Greedy ES stopped (es1).** Checkpoints 5 and 10 judge to the same unit as bc-t2 greedy (265,306,554): ten generations
  flipped no greedy decision, the advice input's margin is far wider than ES steps. ES now runs sampled only (`es2`, from bc0,
  30 seed pairs, judged sampled by `esjudge2.sh`), where a small weight change moves the probabilities and so the score.
  The no-advice clone is judged on the fixed world to see whether ES can work on a policy that decides by itself.
- ES no longer idles the machine between generations: finals read the tail of each season (0.09 s, was minutes on one core),
  and a generation's seasons all play at once (threads = seasons).
- No-advice clone on the fixed world, sampled: 60.3M median (military 52%, hoard 37B) against 268.8M: not a base for ES.
- Sampled ES (es2) checkpoint 5: 268.4M against bc0's 268.8M on the judge seeds: flat; its training medians (271-275M) were the fresh seeds' luck. Judged every 5 generations; flat through 20 means ES finds nothing around this clone, and the next step is a direct headroom search over the teacher's play.
- **First learned gain over the clone (3 Nov 05:45).** Sampled ES (es2), checkpoint 10: median 275.4M against bc0's 268.8M on the
  judge's seeds. Paired by account: mean ln ratio +0.044 (seed-bootstrap 95% CI +0.029 to +0.059), every one of the 10 seeds up,
  71% of 320 accounts up. Composition unchanged (economy 62%, research 11%, military 29%): the gain is play, not a shifted share.
  ES carries on; checkpoints are judged every 5 generations.
