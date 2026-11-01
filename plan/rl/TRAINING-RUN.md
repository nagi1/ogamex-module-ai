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

Sampled clone is the baseline for step C. Slight randomness makes accounts differ the way real players do. One reading so
far; its spread needs more than one run before a small gain counts.

Learner settings are not experiments (NORTH-STAR §10). Fidelity gaps (replay) are read alongside and do not block
training; the rule fixes go in between rounds, and a changed world means a rerun of step A.
