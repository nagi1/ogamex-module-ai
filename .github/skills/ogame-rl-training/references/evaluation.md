# Evaluation and statistics

## Offline (behaviour cloning)

`ogrl.train_bc` writes `metrics.json`: per epoch loss and validation numbers, and for the best epoch:

| Metric | Read it as |
| --- | --- |
| `top1`, `top3`, `mrr` | how often / how high the planner's pick is ranked; MRR = mean of 1/rank |
| `top1_3plus_legal`, `mrr_3plus_legal` | **the headline**: only choices with 3+ legal rows (2-row choices inflate accuracy) |
| `baseline_random_top1` | what uniform guessing over legal rows gets; the model must be far above it |
| `wait_rate_model` vs `wait_rate_teacher` | a model that waits much more or less than the planner is mis-calibrated |
| `payback_log_regret` | how much worse (log hours) the model's pick pays back than the planner's; 0 = equivalent |
| `top1_by_kind/archetype/phase/n_legal` | where it fails; a single weak archetype or phase is a data or feature gap |

The split is **by universe**: validation universes were never seen in training. Never split by row.

## Closed loop (the only number that matters for "does it play")

`rl/scripts/closed_loop.sh` plays each held-out seed twice: A = planner for everyone, B = the model for
`learner_share` of the accounts (same accounts in every run of a seed), then `ogrl.evaluate` compares each
policy account's final value (invested + held at its last choice) between B and A.

- Report `mean` relative ΔV, its 95% CI, `n`, `share_better`, and the by-archetype breakdown.
- The runs are seeded: A-vs-A noise is zero for the same code. Any difference is the policy.
- Number of seeds: `n ≈ 7.8 × (sd / δ)²` for α 0.05 and power 0.8. Run a 20-seed pilot, read `sd`, choose δ
  (e.g. 0.03), compute n, then run that many **new** seeds. Never add seeds until significant.
- Check `RL: N choice(s) fell back` in every `sim-*.log` of B: must be 0.
- Read one policy account's day in human terms (`bash scripts/ogamex account ID` on a saved state, or the
  recorded `objects` sequence): Gate 3 still applies.

## Validity checks before believing a gain

1. Re-run the best and worst seeds: identical results (determinism).
2. Replay the same seeds on MySQL instead of SQLite (`ai:sim` without `--in-memory` on a copy): same digest
   (`plan/rl/bench/digest.php`).
3. No new errors in the sim logs; the host refused nothing unusual (`ai_action_receipts` rejected counts).
4. The gain holds at a second economy speed (e.g. 4 and 8) and in early and mid-game starts.
