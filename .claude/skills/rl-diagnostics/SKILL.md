---
name: rl-diagnostics
description: Diagnose and judge reinforcement-learning work on the OGameX simulator (rl/ogsim, rl/ogrl) before changing anything - PPO health signals, invalid-action masking, headroom and saturation checks, reward and constraint design, and the statistics a claimed gain needs. Use whenever a training run is flat, unstable or surprising, before tuning any knob, and before reporting any number as a result.
---

# RL diagnostics for the OGameX simulator

The rules here come from well-known public sources (listed at the end). Where this file and
`plan/rl/NORTH-STAR-PLAN.md` disagree, the plan wins. Never quote a number here as a measurement of our system: measure
it.

## 1. Check the environment before the agent

Most RL failures are in the environment, the reward or the evaluation, not in the learner (Jones, "Debugging RL").

1. **Baselines first.** Before a learner, run a random legal policy and the scripted teacher on the same held-out seeds.
   A learner that does not beat random is broken. One that only ties the teacher may be copying it.
2. **Headroom test.** Search directly for a better outcome than the teacher's, with no learning: best of N perturbed
   action sequences, or an oracle with full information. This was done for the building order: best of 2048 orders
   beat the player rule by +17%. **If the search finds no headroom, no learner will.** Fix the menu, the reward or the
   teacher, not the learner.
3. **Probe the plumbing.** Use a tiny world where the right answer is known (one legal action that pays, all others
   cost). The policy must find it within a few updates. Then check that the reward arrives on the right ticket, that the
   observation has no NaN or constant columns, and that the mask equals the world's own refusals.
4. **A feature that never varies in training is a trap.** Its normaliser divides by about 0 and explodes on unseen data
   (the speed-16 collapse, 25 Oct). Give any column with training std < 1e-2 a std of 1, and train across the
   conditions you will evaluate on.

## 2. Is PPO itself healthy? (read every log line)

| Signal in `train_world` / `train_ppo` logs | Healthy | Meaning when not |
| --- | --- | --- |
| approx KL per update | about 0.005-0.03 | ≈0 for many updates means the policy stopped changing: it converged, or the lr or advantages are too small. That is not "still learning". > 0.05 means steps too large: lower lr, fewer epochs. |
| clip fraction | about 0.05-0.3 | 0 together with KL ≈ 0 means the same frozen policy. Near 0.5 means updates too aggressive. |
| entropy | falls slowly | Collapsing to ≈0 early means premature determinism: raise ent, or check that the reward scale dominates. Flat at its maximum means no signal reached the policy. |
| explained variance (value) | rises towards 0.8-1 | < 0 means the value net is useless and the advantages are noise: check the reward scale, gamma and the episode boundaries (the done flags). |
| invalid actions | 0 | Above 0 means the mask and the world disagree. Stop and fix the mask; never train through it. |

**A flat evaluation number with KL ≈ 0 and clip 0 is a fixed point, not a training-time problem.** A longer run will not
help: the 15-minute errand run equalled the 5-minute one. Go to §1.2.

PPO implementation details that matter (Huang et al., "The 37 implementation details of PPO"). Check that ours has
them before blaming the algorithm:
- GAE (λ ≈ 0.95) with correct handling of done and truncation: a truncated game must bootstrap from V, not from 0;
- advantages normalised per minibatch;
- gradient-norm clipping (about 0.5);
- orthogonal initialisation with a small final policy-layer gain (about 0.01);
- lr annealing optional;
- reward scaling or normalisation so that returns are O(1);
- observation normalisation with running statistics frozen for evaluation;
- several epochs over each batch, shuffled minibatches.

**Invalid-action masking** (Huang and Ontañón 2020): set the logits of illegal candidates to a large negative value
*before* the softmax, compute the entropy and the log-probs on the masked distribution, and store the mask with the
rollout so the update uses the same mask. Masking is a valid policy gradient; sampling an illegal action and punishing
it is far slower.

## 3. Saturation: when every change gives the same number

If several unrelated learner changes (entropy, width, lr, KL anchor, credit assignment) all land inside the same CI,
the limit is upstream of the learner:
- the **menu**: the right action is not offered. W14 showed it;
- the **teacher**: BC plus a KL anchor ties the policy to it;
- the **reward**: it does not reward what you want.

Stop tuning. Measure headroom (§1.2) and the distance to the goal (`rl/scripts/hds.py`), then change the upstream thing
the measurement points at.

## 4. Reward and constraint design

- **Reward what you want, not a proxy that drifts from it.** Our case: value counts ships at price, so a value maximiser
  hoards fleet (x392 the teacher), while real players hold a median 6-16% military share. The north-star constraint
  (stay inside real bands) must be in the objective, not just in the report.
- **Potential-based shaping** (Ng, Harada and Russell 1999): adding γΦ(s') − Φ(s) to the reward does not change the
  optimal policy. Any other dense bonus can, so check it against the undecorated objective.
- **Constraints.** Start with one fixed penalty weight λ for leaving the bands (NORTH-STAR N7). If no single λ gives both
  in-band behaviour and the value floor, use a Lagrangian: λ rises while the constraint is violated and falls when it is
  satisfied (reward-constrained policy optimisation, Tessler et al. 2018). Do not hand-tune many weights.
- **Reward hacking check.** After any reward change, read what the policy *does* (event counts, composition), not just
  the return. A rising return with the behaviour leaving the human bands is a failure.

## 5. Statistics: when is a number a result?

Agarwal et al., "Deep RL at the Edge of the Statistical Precipice" (rliable):
- At least 3 training seeds, and more when the effect is small. Report the spread across seeds, not a single run.
- Prefer the **interquartile mean (IQM)** across runs and accounts with **stratified bootstrap 95% CIs**, over the mean
  (skewed by a few huge accounts, as in M5) or the median alone (throws away information).
- **Paired comparisons** on identical held-out worlds (same seeds, same starting states). Pick the checkpoint by the
  training metric, never by the evaluation set.
- A change is real only if it beats the **3-seed spread** of the baseline. Inside the spread it is noise: revert and
  log it (NORTH-STAR §7).
- Many comparisons inflate false positives. When a sweep tries k configurations, expect about k × 5% spurious "wins" at
  95%. Re-run the winner on fresh seeds before believing it.
- Plot learning curves and performance profiles (the share of runs above each score) rather than quoting one end
  number.

Tools: `scikit-learn` (the classifier test in `hds.py`) and `statistical-analysis` (test choice, effect sizes, power: how
many seeds are needed) skills in this folder; `scipy.stats.bootstrap` for CIs.

## 6. Sources

- A. Jones, "Debugging Reinforcement Learning Systems": https://andyljones.com/posts/rl-debugging.html
- S. Huang et al., "The 37 Implementation Details of Proximal Policy Optimization", ICLR blog track 2022:
  https://iclr-blog-track.github.io/2022/03/25/ppo-implementation-details/
- S. Huang and S. Ontañón, "A Closer Look at Invalid Action Masking in Policy Gradient Algorithms", 2020:
  https://arxiv.org/abs/2006.14171
- R. Agarwal et al., "Deep Reinforcement Learning at the Edge of the Statistical Precipice", NeurIPS 2021:
  https://arxiv.org/abs/2108.13264, library https://github.com/google-research/rliable
- A. Ng, D. Harada and S. Russell, "Policy invariance under reward transformations", ICML 1999.
- C. Tessler, D. Mankowitz and S. Mannor, "Reward Constrained Policy Optimization", ICLR 2019.
