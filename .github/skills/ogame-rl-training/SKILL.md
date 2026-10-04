---
name: ogame-rl-training
description: Work on the learned economy policy of the OGameX AI module end to end: generate training data from the real game in fast seeded simulation, train and export the candidate-scoring model, serve it to the game, evaluate it against the deterministic planner, diagnose failures, change features, and improve it (imitation first, then reinforcement learning). Use for any task that touches plan/rl, rl/ (Python), app/Domain/Choice, DecideAiEconomyStepsAction, ai:rl-universe, or the ai:sim --record-choices / --choice-policy options.
---

# OGameX economy policy: data → model → game → evaluation

Read this whole file before acting. The references hold the detail:
[system map and file index](references/system.md) · [features](references/features.md) ·
[evaluation and statistics](references/evaluation.md) · [troubleshooting](references/troubleshooting.md) ·
[improving the model](references/improving.md). The design and the reasons are in `plan/rl/` (start with
`plan/rl/README.md` and `plan/rl/next-steps.md`).

## What the system is (one paragraph)

OGameX (the host, Laravel) owns every game rule. The AI module's planners decide what accounts do. At each
login, every **free build queue** of a planet and the **lab** is a *choice point*: the building planner lists the
candidates it would consider (in pass order: wall, doctrine, storage, surplus, routine, ambition), row 0 is
**wait**, and the deterministic planner (the **teacher**) picks the first one it can afford with its reserve. A
**ChoicePolicy** may pick another legal row instead; whatever is picked is queued through the host's normal
actions, which validate it again. The model is a small **candidate scorer** (≈ 0.3 M parameters): it scores each
(state, candidate) pair with one shared network and takes the best legal row. Training data comes from **seeded,
in-memory simulations of the real game code** (`ai:sim --in-memory --seed`), never from a second implementation.

## Rules that are never broken

1. **The host stays the referee.** Never make a policy bypass `QueueAiBuildingAction` / `QueueAiResearchAction`,
   never let it pick an illegal row (the PHP side already falls back to the teacher on an illegal answer).
2. **No object identity as a feature** (Gate 1 in `AGENTS.md`): no object id, machine name or one-hot over
   objects. Describe objects by host-derived numbers (cost, time, production gain, storage, unlocks…).
3. **No language-model or hosted-classifier calls in data generation, training, the reward or play.**
4. **Seeded and reproducible**: every simulation uses `--seed`; the same seed + same code + same model = same
   game. Check it with `plan/rl/bench/digest.php` when you touch anything on the play path.
5. **The teacher path is untouched**: with `ai.rl.policy=teacher` and no recorder, `DecideAiEconomyStepsAction`
   returns `QueueableBuildingPlanner::steps()` exactly. `tests/Feature/EconomyChoiceSeamTest.php` guards it.
6. **Features are versioned**: any change to what `EconomyChoiceEncoder` emits bumps
   `EconomyChoiceEncoder::VERSION`; data of different versions never mixes (`ogrl.data` refuses it).
7. **Evaluation is on the real game**, twin universes, held-out seeds, statistics reported with intervals.
   Training loss or offline accuracy alone never justify a change.
8. **No PPO or self-play before the behaviour-cloning closed-loop gate passes** (see below).

## The workflow (commands)

Run PHP from the OGameX root (host checkout with the module at `Modules/AI`); Python from a venv with
`pip install -e Modules/AI/rl` (CUDA PyTorch on the RTX machine).

```bash
# 0. Health
php artisan migrate --force
bash Modules/AI/scripts/ogamex test-one EconomyChoiceSeamTest        # or vendor/bin/pest Modules/AI/tests/Feature/EconomyChoiceSeamTest.php

# 1. One universe, one simulated day, choices recorded (smoke test)
php artisan ai:rl-universe storage/rl/smoke.sqlite --accounts=12 --seed=7
DB_CONNECTION=sqlite DB_DATABASE=$PWD/storage/rl/smoke.sqlite php artisan ai:sim --in-memory --native-cognition \
  --seed=7 --hours=24 --from=2026-10-05T00:00:00Z --record-choices=$PWD/storage/rl/smoke.jsonl
#   expect: "SIM: 24.0 h played ... 0 error(s)" and ~10 lines per account per day in smoke.jsonl

# 2. Training data (N universes × D days, P parallel processes; epsilon = teacher + 10% random legal choices)
PHP=php bash Modules/AI/rl/scripts/generate.sh storage/rl/bc 32 30 16 epsilon 0.1 24

# 3. Behaviour cloning on the GPU
python -m ogrl.train_bc --data 'storage/rl/bc/choices-*.jsonl' --out storage/rl/bc-model --epochs 30

# 4. Closed loop: the model plays a quarter of the accounts in 30 fresh universes (seeds not used in training)
PHP=php bash Modules/AI/rl/scripts/closed_loop.sh storage/rl/bc-model/model.onnx storage/rl/eval 1001 30 30 16 0.25

# 5. Only the model serving, e.g. for manual runs
python -m ogrl.serve --model storage/rl/bc-model/model.onnx --socket /tmp/ogrl.sock
DB_CONNECTION=sqlite DB_DATABASE=... php artisan ai:sim --in-memory --seed=1001 --hours=24 --from=2026-10-05T00:00:00Z \
  --choice-policy=socket --choice-socket=/tmp/ogrl.sock --learner-share=0.25 --record-choices=$PWD/storage/rl/manual.jsonl
#   the run ends with "RL: N choice(s) fell back to the planner" -- N must be 0 when the server is healthy
```

Every `ai:sim` option: `--in-memory` (copy the DB into SQLite :memory:, source untouched), `--seed`,
`--save-sqlite=path` (snapshot at the end), `--choice-policy=teacher|epsilon|socket`, `--choice-epsilon`,
`--choice-socket`, `--learner-share` (share of accounts the policy decides for, picked by hash of seed+player),
`--record-choices=path` (`{pid}` allowed), plus the existing `--hours --from --accounts --native-cognition`.
The same settings exist as env (`AI_RL_*`, `config/rl.php`).

## Gates (decide what you are allowed to do next)

| Gate | Pass condition | If it fails |
| --- | --- | --- |
| G-data | ≥ 300k choice points with ≥ 2 legal rows from ≥ 24 universes; 0 sim errors | fix the sim errors first (troubleshooting) |
| G-BC offline | validation top-1 on choices with 3+ legal rows ≥ 0.90; MRR ≥ 0.93 | features or data: see improving.md §1–3 |
| G-BC closed loop | twin ΔV of policy accounts within ±5% of the teacher, 95% CI includes 0 or is positive, 0 socket fallbacks | DAgger (improving.md §2); never tune the threshold to pass |
| G-RL start | both BC gates passed and recorded in `plan/rl/next-steps.md` | do not start PPO |
| G-RL success | twin ΔV significantly > 0 at day 30 on pre-registered seeds (evaluation.md) and the top/bottom seeds replay identically on MySQL | stop condition in `plan/rl/implementation-roadmap.md` |

## How to report (every run)

Append a dated row to the Results table in `plan/rl/next-steps.md`: commit hashes (host + module), encoder
version, universes/days/seeds, choice points, train/val split, model params, val top-1 / top-1 (3+ legal) / MRR,
closed-loop mean ΔV with 95% CI and n, socket fallbacks, wall time. Commit only the files you changed, by name.

## When you change something

- **A feature**: edit `EconomyChoiceEncoder` (names list + value, same order), bump `VERSION`, update
  `references/features.md`, regenerate data, retrain. Run `EconomyChoiceSeamTest`.
- **A new choice point** (shipyard, raid targets, saves…): follow improving.md §6; the planner must expose a
  candidate list and the teacher's pick, the action must apply the choice through existing host actions.
- **The model**: `rl/ogrl/model.py`; keep `forward(state, cands, mask) -> (logits, value)` and the export
  signature, or update `serve.py` and `export.py` together.
- **The reward** (for PPO later): `plan/rl/reward-design.md`; `value` per recorded row is the input.
