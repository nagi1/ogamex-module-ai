# Reward design

## 1. What the host already measures

The host's general score is **resources invested ÷ 1000** (`HighscoreService::getPlayerScore`):

- buildings and stations: cumulative cost of all levels (`ObjectService::getObjectCumulativeCost`),
- research: cumulative cost (`PlayerService::getResearchScore`),
- ships and defence on planets: unit price × count (`PlanetService::getPlanetScore`),
- ships in flight: same, from unprocessed `fleet_missions` (`getPlayerFleetMissionScore`).

Sub-scores: economy (buildings 100%, defence 100%, civil ships 50%), research, military built / destroyed /
lost. The module copies these hourly into `ai_score_samples` (`RecordAiScoreSamplesAction`), with
`general_rank`.

Not in the score: **resources held** on planets, cargo in flight, debris owned but not collected.

## 2. Primary reward: Δ account value

```
V(t) = score_general(t)·1000                       # invested, from host formulas
     + Σ planets (metal + crystal + deuterium)(t)  # held stock
     + Σ in-flight cargo(t)
r_t  = (V(t+Δ) − V(t)) / (scale(t))                # per decision interval, Δ = time to the account's next decision
```

Use **metal-equivalent** weights for held stock and score alike (metal 1, crystal 1.5, deuterium 2, the ratios
`NativeRaidEstimator` already uses: `CRYSTAL_WEIGHT`, `DEUTERIUM_WEIGHT`) or plain sums, but the same weights
on both terms. Otherwise converting stock into score creates value from nothing.

Why this one:

- **Building does not earn reward by itself.** Spending 10,000 on a mine moves 10,000 from stock to score:
  ΔV = 0 at the moment of purchase. The reward arrives later, as the mine produces. That is the economic
  signal, with no hand weight on "build".
- **Losses are counted automatically.** A lost fleet leaves the score; looted resources leave the stock.
- **Raids pay through cargo.** Loot enters `V` when it lands; fuel spent leaves it. No "+10 per attack".
- **Defence and fleet are neutral when built and negative when destroyed.** This is the known weakness:
  a defence that deters an attack produces an avoided loss, which is a counterfactual reward signal the
  agent can only learn statistically, from opponents that actually attack. Training against
  deterministic raiders in the same universe supplies those opponents.

`scale(t)`: divide by the account's own hourly production at t (or by a running std, as in
`VecNormalize`), so that day-1 and day-60 rewards have similar magnitude. Without it the late game dominates
the gradient.

## 3. Relative term (small, optional at first)

`r_rel = Δ(percentile rank of V among the universe's accounts)`. A cohort-relative signal removes universe
speed and seed difficulty from the reward and makes "everyone grows" worth zero. Start with weight 0. Turn it
on only when the evaluation shows the learner is growing in absolute terms but losing ground to the cohort.
**Do not use raw `general_rank`**: rank changes are discrete, delayed by the hourly highscore job, and can be
gamed by hurting a neighbour more cheaply than growing.

## 4. Shaping, kept minimal

| Signal | Use? | Why |
| --- | --- | --- |
| Idle build queue / lab while affordable | Small penalty (≤ 1% of typical r), v1 only | Learnable on its own, but cheap shaping shortens the early phase. Remove once BC init is in place. |
| Storage overflow (production lost to a full store) | **No.** It is already in ΔV (lost production) | |
| +X per build / raid / colony | **Never** | Directly biases behaviour. |
| Survival / not being raided | No. Losses already in ΔV | |
| Energy factor < 1 | No. Lower production already in ΔV | |

## 5. Horizon, discount, delay

- Decisions in v1 (C1–C3) are spaced minutes to hours of game time apart. Payback of a mine is tens of hours
  of game time (`EconomyUpgrades::paybackHorizonHours`). Discount **by game time, not by step**:
  `γ_eff = γ^(Δt / 1h)` with γ ≈ 0.99–0.995 per hour, i.e. an effective horizon of 4–8 game days. A per-step γ
  would make long waits look free.
- GAE with λ ≈ 0.95 on the same time-scaled γ.
- Terminal: at episode end add nothing extra (ΔV already covers it), or bootstrap with V(s_T) when the
  episode is truncated by length rather than ended (Gymnasium's `truncated` semantics).

## 6. Episodes

| Option | Use |
| --- | --- |
| New account, 30 simulated days | **v1 default.** Covers the opening, the first colonies and the doctrine research path. Day 1–10 at speed 8 measured at ×313–×735 real time per 20-account universe. |
| Start from a snapshot (day 10, 30, 60) of a deterministic cohort, play 14–30 days | Curriculum for mid/late game. The snapshot machinery exists (`scripts/sim-clone.php`, SQL dumps used in this investigation). |
| Random length (geometric, mean 30 days) | Optional. Prevents end-of-episode gaming (e.g. stopping investment before the end). With bootstrapped truncation it is rarely needed. |
| 1 simulated year | No. Too slow per sample for v1, and the reward scale problem gets worse. |

Speed settings: train on a **mix** of economy speeds (e.g. 1, 4, 8) with speed as a state feature, or one
fixed speed with evaluation at others to detect overfitting.

## 7. Reward hacking to expect, and the guard

| Hack | Guard |
| --- | --- |
| Hoard (never spend; stock counts in V) | Stock is valued at par, but it earns no return, so hoarding loses to investing. It wins only if the stock is lost to raiders, and raiders are in the universe. Monitor stock/production ratio. |
| Convert to score via low-value objects (score counts cost, not usefulness) | ΔV at purchase is zero, so there's no immediate gain. Over time useless objects earn nothing. Watch the defence/economy share against the deterministic personas. |
| End-of-episode dumping | Truncation bootstrapping; random lengths if seen. |
| Farming weak deterministic players | Expected and legitimate in OGame, but evaluate against mixed opponents and report raid profit by target type. |
| Exploiting a simulator bug | Every high-reward trajectory above the deterministic baseline is replayed on the MySQL path (parity), see [evaluation-plan.md](evaluation-plan.md). |
