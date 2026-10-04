# Evaluation plan

Training reward is not evidence. A policy is "better" only on this protocol.

## 1. Design: twin universes

For each evaluation seed, run the **same universe twice** from the same snapshot:

- **A (control)**: designated accounts play the deterministic teacher.
- **B (treatment)**: the same accounts play the learned policy; everyone else is identical.

The per-account paired difference `ΔV_B − ΔV_A` removes universe difficulty, start position, neighbours and
persona. This needs the host RNG seam (expeditions, battles, counter-espionage, planet creation seeded),
otherwise run-to-run noise appears even with identical policies: measured here, two MySQL runs from the same
day-10 state already differed. Until the seam exists, use more seeds and report the A-vs-A noise floor by
running A twice.

## 2. Metrics (per account, at day 7, 14, 30; and by phase)

| Group | Metric | Source |
| --- | --- | --- |
| Primary | ΔV: invested (score × 1000) + held resources + cargo | host score formulas, planets |
| Score | general, economy, research, military built/lost | `highscores`, `ai_score_samples` |
| Rank | percentile within the universe | `general_rank` |
| Efficiency | idle build-queue hours, idle lab hours, storage-full hours, energy factor < 1 hours | planets + queues |
| Combat | resources lost to raids, raid profit (if the policy controls raids), fleet value | battle reports, fleet missions |
| Survival | accounts destroyed / planets lost | host |
| Behaviour | object mix by archetype (defence share, fleet share, research share) compared with the teacher | queues |
| Robustness | the above at economy speed 1 / 4 / 8 / 16 and with 0% / 25% / 50% learners | |

Report mean, median, the 10th percentile (bad tails matter more than means for believable players) and
per-archetype breakdowns.

## 3. How many seeds

Use a paired t-test (or Wilcoxon signed-rank if skewed) on universe-level means of `ΔV_B − ΔV_A`.

```
n = ((z_{1-α/2} + z_{1-β}) · σ_d / δ)²      α = 0.05, power 0.8 → (1.96 + 0.84)² ≈ 7.8
```

`σ_d` is unknown until the pilot. Procedure:

1. Pilot: 20 twin seeds at 14 days. Measure σ_d.
2. Choose the smallest effect worth having, e.g. δ = 3% of teacher ΔV.
3. If σ_d = 10% of ΔV, n ≈ 7.8 × (10/3)² ≈ 87 twin seeds; if 5%, ≈ 22.
4. Fix n **before** running the confirmation set, and use fresh seeds not seen in training.

Wall time, 16 workers, 14-day episodes, mid-game rate: a twin pair at 24 accounts ≈ 2 × 14 × 0.12 h ≈ 3.4 h
of one worker; 87 pairs ≈ 18 h. Affordable overnight.

## 4. Tournament after E2

| Panel | Purpose |
| --- | --- |
| Teacher (deterministic, same persona) | main comparison |
| Teacher with `idleOverride` and skill jitter off | removes humanising noise from the baseline |
| BC policy | did RL add anything? |
| Simple heuristics: "cheapest first", "highest payback first" | sanity floor |
| Previous RL checkpoints | regression and forgetting |

## 5. Validity checks (every reported win)

1. **Replay on MySQL**: the best and worst 5 seeds replayed with the same actions on the MySQL path; digests
   must match (the parity tool). A win that vanishes is a simulator artefact.
2. **Teacher replay**: the teacher's score in the eval harness equals its score in a normal `ai:sim` run.
3. **Inspect a day**: print one learner account's day as human lines (as the module's acceptance read does,
   `bash scripts/ogamex account`). Gate 3 still applies: a better number from behaviour no veteran would
   recognise is not accepted.
4. **No hidden information**: the feature schema is reviewed against the "a player can see this" rule.
