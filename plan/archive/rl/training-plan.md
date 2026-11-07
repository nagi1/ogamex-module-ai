# Training plan

## 1. Shape of the system

```
                        ┌──────────── 16 × PHP worker (one universe each, SQLite :memory:) ────────────┐
                        │ ai:sim loop (virtual clock, sync, native cognition, social lanes off)          │
                        │ real host services + real module planners                                      │
 reset(seed, snapshot)─▶│ learner accounts: at choice point C1/C2/C3 the planner's candidate list +      │
                        │   features + teacher index go to the policy; the chosen candidate goes back    │
                        │   to the same planner code path and is validated by the host as today          │
                        │ deterministic accounts: unchanged code                                         │
                        └───────────────────────────────┬────────────────────────────────────────────────┘
                                                        │ unix socket (88 µs) or rollout files
                        ┌───────────────────────────────▼────────────────────────────────────────────────┐
                        │ Python: PyTorch 2.14 · BC trainer · CleanRL-style PPO · evaluation harness      │
                        │ RTX 3090 for updates; ONNX export of each policy version                        │
                        └─────────────────────────────────────────────────────────────────────────────────┘
```

### What one `step()` is

One **learner choice point**: a learner account's build queue on planet P is free (C1), or its lab is free
(C2), with WAIT as an extra candidate (C3). The environment advances the universe in virtual time (all
accounts, fleets, battles) until the next learner choice point, then returns
`(state, candidate_rows, mask, reward since this account's previous decision, Δt, done)`. Rewards and GAE are
computed **per learner account trajectory** (several learners share one universe and one policy).

### What `reset()` creates

`reset(seed, start)`: load a universe snapshot into a fresh SQLite `:memory:` DB (copy ≈ 0.1 s for 9,000
rows, measured), set the universe speed, assign learner/deterministic roles and personas from the seed,
set the virtual clock to the snapshot's instant. `start` is either "fresh" (newly registered accounts, as
`ai:seed-test-universe` makes them) or a day-N snapshot from a deterministic run (curriculum).

### Virtual time

Keep `SimulatedTime` + the `ai:sim` jump loop. Sessions keep their routine (`SessionPlanner`), so decision
timing stays human-shaped and identical to production. Add the learner hook inside the session; do not invent
a fixed "tick".

## 2. What is disabled in training, and the same switch in production

| Off in training | Production rule for the learned policy path |
| --- | --- |
| Sidecars (FAtiMA, CBRKit, AgentOS, PsychSim), language lane | Already off for gameplay decisions when weights are 0. |
| `ai.cognition.affect.decision_weight` → 0 | Must also be 0 for accounts on the learned policy (or added as a feature later). |
| `ai.cognition.experience.decision_weight` → 0 | Same. |
| Conversation cycle, alliance life | Learned policy must not read alliance state in v1 (C1–C3 do not). |
| Campaign consultation | Off. |
| Highscore generators: hourly | The state uses score samples; same cadence in production. |

## 3. Minimum logging additions

`ai_decision_traces` records only the errand category. Add, for each C1/C2/C3 choice point, one row (or one
JSON line in training, no DB):

| Field | Why |
| --- | --- |
| `player_id`, `planet_id`, `choice_point` (C1/C2), `observed_at` (virtual) | identity and time |
| `state` vector (≈100 floats) + feature-schema version | observation |
| `candidates`: per candidate `object_id` (for analysis only, not a feature), pass name, feature row (≈30 floats), legal flag | candidate set |
| `teacher_index`, `policy_index`, `policy_logits`, `policy_version` | labels, PPO log-probs |
| `outcome`: `V(t)` at this decision and at the account's next decision | reward |
| `persona` (archetype, skill, activity, doctrine, stockpile) and `universe_seed` | grouping |

In training these go to compressed NPZ/Parquet files per worker; nothing is written to MySQL. In production
log only `policy_version`, chosen index, teacher index and score margin into the existing trace JSON.

## 4. Phase 1: behaviour cloning (prove the plumbing)

- **Data**: 16 universes × 24 accounts, all deterministic, 30 days fresh + 30 days from a day-30 snapshot.
  Expected ≈ 2.8 choice points per session; ≈ 5×10⁵–10⁶ samples in under a day of wall time.
- **Model**: the candidate scorer from [state-and-action-space.md](state-and-action-space.md#7-model-size-derived-not-guessed).
- **Loss**: cross-entropy of the softmax over the legal list against `teacher_index`.
- **Split**: by **universe seed** (not by row), so evaluation states come from unseen universes.
- **Metrics** (classification accuracy is misleading when lists have 1–30 rows):
  - accuracy **on decisions with ≥ 2 legal candidates** only, and the trivial baseline "pick pass-order
    first" (which is the teacher, so 100%) vs. "pick cheapest" / "pick highest payback" as sanity baselines;
  - **mean reciprocal rank** of the teacher's candidate;
  - top-1/top-3 by choice point, by archetype, by phase (`GamePhaseMachine`), by number of candidates;
  - **value agreement**: the payback hours of the chosen candidate vs. the teacher's, a regret-like measure
    that is 0 when the model picks an equivalent candidate;
  - **closed-loop check**: put the BC policy in charge of C1–C3 for learner accounts and compare 30-day ΔV
    with the teacher on the same seeds. Offline accuracy can be high while compounding errors drift the
    account into states the teacher never visits; only the closed loop shows that.
- **Pass**: closed-loop 30-day ΔV within ±5% of the teacher's (paired over ≥ 30 seeds), no illegal choices
  (by construction), and ≥ 90% top-1 on multi-candidate decisions. Then the plumbing is proven.

## 5. Phase 2: first RL experiment (E2)

**Smallest experiment with meaningful evidence:** PPO fine-tuning of the BC policy on C1–C3 only, with every
other choice deterministic.

| Setting | Value |
| --- | --- |
| Universes | 16 workers, 24 accounts each: 6 learners (policy), 18 deterministic (mixed archetypes) |
| Episodes | Fresh accounts, 30 simulated days, economy speed drawn from {4, 8} |
| Reward | ΔV (invested + held, metal-equivalent), divided by the account's hourly production; γ per game hour 0.995; λ 0.95 |
| PPO | rollout 8,192 decisions, 4 epochs, minibatch 1,024, clip 0.2, entropy 0.01 → 0.001, lr 3e-4, KL early stop 0.02 |
| BC anchor | KL penalty to the BC policy, decayed over the first 10⁶ decisions (prevents early collapse) |
| Budget | 5×10⁶ learner decisions |
| Success | Learner ΔV at day 30 > deterministic teacher's ΔV on the same seeds by a statistically significant margin ([evaluation-plan.md](evaluation-plan.md)) **without** wins coming from a simulator divergence |

Why economy first: C1–C3 have rich candidate lists, the teacher is good but improvable (pass order is a fixed
priority, payback is greedy), the reward is fast (hours to days), and the decisions barely interact with
other players, so the multi-agent problems wait.

**Is training the full candidate system earlier practical?** The executors exist for every action, but the
*candidate lists* for raids, saves, colonies and shipyard roles are mostly single-plan outputs today
(`RaidPlanner::plan` returns one plan per report, `QueueableUnitPlanner::plan` one order). Turning each into a
scored list is code work per planner, so they enter one at a time (C4 → C5/C6 → C7/C8).

## 6. Curriculum

Useful, but in a different sense than the brief's list. The architecture already plays everything; the
curriculum is about **which choice points the policy owns** and **where episodes start**:

1. C1–C3, fresh accounts (E2).
2. C1–C3 from day-30 and day-60 snapshots (mid-game economy, more planets).
3. + C4 shipyard (defence and cargo choices; first exposure to raids by deterministic raiders).
4. + C6 spy and C5 raid targets (needs the report-based set encoder).
5. + C7 fleetsave/evacuate (safety-critical; train against aggressive deterministic raiders).
6. + C8 colonisation.
7. Populations with several learned checkpoints (self-play, section 8).

Each step starts from the previous policy and keeps the previous choice points trained (no forgetting:
evaluate the old choice points every iteration).

## 7. Personas

**One model, persona as input** (archetype one-hot + the four persona dimensions). Reasons: the doctrine files
already express taste as data; the five archetypes share > 90% of the game; separate models would split the
sample budget five ways. Add per-archetype heads only if evaluation shows one persona's behaviour collapsing
into another's (measure: the action distribution by archetype must stay distinguishable, e.g. turtle
defence share vs. raider fleet share, as the deterministic personas show).

## 8. Self-play (Phase 4, not v1)

OGame is a persistent, mostly economic, weakly interactive game. Self-play matters only when the policy
controls interactive decisions (raids, saves, defence). Plan then: learner vs. deterministic → learner vs.
frozen checkpoints (pool of the last N, sampled with prioritised fictitious self-play) → mixed populations.
Mitigations: keep deterministic personas in every universe as anchors (prevents cycling into a degenerate
meta), evaluate against a fixed panel every iteration (detects forgetting), cap the share of learner accounts
per universe at 50%.

## 9. Population and hardware (Xeon Gold 6254, 18 cores, 64 GB, RTX 3090)

| Quantity | Recommendation | Basis |
| --- | --- | --- |
| Parallel universes | **16** (1 per core, 2 cores for the trainer/IO) | Linear scaling measured on 4 cores; no shared DB server with SQLite. |
| Accounts per universe | **24** (20–32 is fine) | Cost per universe is linear in accounts; 24 gives every archetype ≥ 4 representatives and a real target pool for raiders. |
| Learners per universe | **6 of 24** in E2 (25%); up to 50% later | Deterministic majority keeps the world realistic and gives a paired baseline in the same universe. |
| RAM | < 3 GB for 16 workers | 72–78 MB peak per worker measured + SQLite DB tens of MB. |
| GPU | Mostly idle; one PPO update on 8,192 rows is seconds. | MLP of 0.3–1 M params. |

The brief's 16 × 32 with 28 deterministic + 4 learners is close; the change is fewer accounts per universe
(cost is linear in accounts, diversity comes from universes and seeds) and more learners per universe.

## 10. How long

| Item | Estimate | Basis / confidence |
| --- | --- | --- |
| Learner decisions/s, 16 workers, 25% learners, mid-game | ≈ 25/s today, 50–75/s after the cheap PHP fixes | measured per-process rates; fixes unmeasured (hypothesis) |
| All-account (teacher) samples/s for BC | ≈ 100/s today | measured |
| BC dataset of 10⁶ samples | ≈ 3 h | |
| E2 at 5×10⁶ learner decisions | ≈ 2.5 days today, ≈ 1 day after fixes | assumption: PPO with BC init converges within 5×10⁶; unproven |
| Simulated account-days per real second (16 workers) | early game ≈ 50–80; mid-game ≈ 30 | ×1,150 early / ×332 mid per 20-account universe |

**How many decisions for a useful policy?** Unknown until E2 runs. For a narrow economic re-ranking problem
initialised by BC, 10⁶–10⁷ decisions is a reasonable expectation; for raids/saves/self-play, 10⁷–10⁸, which
is where gate G3 (a faster simulator) may become relevant.
