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
