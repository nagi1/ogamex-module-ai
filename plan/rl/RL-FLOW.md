# RL flow: start small, change one thing, measure (the work order from 26 Oct 2026)

Owner direction, 26 Oct: follow a proper RL flow. Start from the smallest problem that works, get a result, change
**one** thing, measure it, and keep going in that direction. No more parallel lanes and big rows. The code must stay
small enough that the owner and the planner can read all of it.

This file is the work order. `NORTH-STAR-PLAN.md` stays the background: why we circled (§1), the human-distance
measure we work towards (§4), and the skills assessment (§14). One agent works at a time.

## 1. The loop (every experiment, no exceptions)

1. **Write the experiment row first** in §4, before running anything. A row has:
   - the stage;
   - the one change;
   - the hypothesis: what number moves, which way, and by roughly how much;
   - the command;
   - the keep rule. The default keep rule is that the 3-seed mean moves beyond the baseline's 3-seed spread in the
     expected direction, with no floor broken.
2. **Make the one change.**
   - One flag, one feature, one reward term, or one menu item. Never two.
   - A code change is at most about 50 lines and readable top to bottom. If it is in Rust, it comes with a test.
3. **Run 3 seeds** on the stage's fixed evaluation set, at the stage's fixed budget. They run as background processes;
   no extra agents.
4. **Fill in the result:** the per-seed numbers, mean ± spread, and the PPO health readings (KL, clip, entropy,
   EV, invalid).
5. **Record the code version.** Put the commit (`git rev-parse --short HEAD`, plus "dirty" if there are uncommitted
   changes) in the row. A number without its code version cannot be reproduced: that is how the +31% baseline was
   lost (rows 1-3).
6. **Decide:**
   - keep (it becomes the new baseline of the stage), or
   - revert (`git revert` or delete the change, and the row says "reverted").
   - There is no "keep it anyway".
7. **Move on.** The next row may only build on a kept change.

Health stop: if `invalid` > 0, or EV stays below 0 at the end, or KL is 0 from the first update, the run is broken,
not a result. Fix the plumbing first; the `rl-diagnostics` skill, §1-§2, says how.

## 2. The code (what exists, what to use, what not to grow)

| File | Use |
| --- | --- |
| `rl/ogrl/train_ppo.py` (272 lines) | **The one trainer.** Synchronous PPO over `ogsim.VecEnv`, readable top to bottom. Every stage uses it, extended one flag at a time. The owner has uncommitted edits in it: read `git diff --cached rl/ogrl/train_ppo.py` first, and only add, never rewrite. |
| `rl/ogrl/model.py` | the candidate-scoring network |
| `rl/ogsim` (Rust) | the simulator. Host-exact; it changes only with a test |
| `rl/ogrl/train_world.py` (701 lines, actor-learner) | **Frozen.** Built for throughput we do not need: runs converge in minutes. Do not extend it. Delete it once a stage reproduces its errand result with the simple trainer. |
| `rl/ogrl/train_bc_world.py` | behaviour cloning for errands; used again at stage S5 |
| everything else in `rl/scripts` | tools, not part of the loop |

New code goes into one of these files or into one new file per stage, named for what it does. No new framework, no
second trainer, no helper scripts that only wrap a command.

Run from the repo root:

```bash
cd /home/nagi/code/ogamex-next
CUDA_DEVICE_ORDER=PCI_BUS_ID PYTHONPATH=Modules/AI/rl .venv-rl/bin/python -m ogrl.train_ppo \
  --catalogue storage/rl/ogsim/plain-train.json --eval storage/rl/ogsim/plain-eval.json \
  --out storage/rl/ogsim/runs/flow/<row>-seed<s> --seed <s> --steps 3000000
```

- About 14k steps/s on the 4070, so a 3 M-step run takes about 4 minutes, and 3 seeds run side by side.
- The last log line reads `eval +X% (mean)` against the player rule on held-out planets.

## 3. The stages (go up only when the stage's exit is met)

There are two kinds of experiment, run one at a time in this single order:
- **R** experiments change the learner or its problem. They are judged by value against the rule, in the simulator,
  and later in PHP.
- **T** experiments change the teacher or the world (the scripted players, the menu, the mechanics). They are judged by
  the human distance: `rl/scripts/hds.py` over 3 season seeds (`rl/ogsim/examples/season.rs`, 64 accounts × 60 days at
  speed 8, about 1.4 s per seed).

The T baseline is the N5 reading: **HDS 0.686, AUC 0.990**. Real accounts score about 0.84 against themselves.

| Stage | Kind | Problem | Exit (3 seeds) | Why this order |
| --- | --- | --- | --- | --- |
| **S0** | R | Building order, one planet, speed 8, 72 h, no logins | the documented +31..+34% over the player rule, reproduced within the spread | the trainer and the simulator work, so later numbers mean something |
| **S1** | R | S0 + logins (`--login-gaps storage/rl/ogsim/login-gaps.json`) | the documented +90..+100% on plain starts, reproduced | logins are the game's timing |
| **S2** | R | **Transfer of the S1 model to PHP** (NORTH-STAR N0b) | the gap between the simulator (+44..+63%) and PHP (median 0.99) is located and named | nothing above S2 is trustworthy until simulator gains are shown to be real |
| **S3** | T | Colonise: the scripted players take a new planet when the host's cap allows, at a position drawn from the real histogram | `planets` in band, HDS up beyond the 3-seed spread | the top giveaway (48% out of band) |
| **S4** | T | The research/military split per persona, as doctrine data | `res_share` and `mil_share` move into band | giveaways 2 and 4 |
| **S5** | T | Expeditions, then moons (each its own row) | each row's measure | the remaining giveaways, in giveaway order |
| **S6** | R | Building model retrained on the S3-S5 world, then the transfer check again | beats the new teacher in both simulator and PHP | RL on a teacher that plays the whole account |
| **S7** | R | The first fleet decision, smallest form: one raider, fixed inactive targets, menu `{wait, spy, raid}`. **Headroom first**, then BC, then PPO | beats the rule beyond its spread, and HDS does not fall | a small problem with a known good answer |
| S8.. | R/T | One menu item or one reward term per row: recycle, save, colonise as a choice, the band penalty (NORTH-STAR N7) | each row's own keep rule | the errand menu rebuilt one proven piece at a time |

Rules for T rows:
- A T row's code change is in `players.rs` or doctrine data, and it comes with a test.
- No object is named in code (Gate 1).
- Before the change, check that it is something a human player does (Gate 3).

## 4. Experiment log (newest last; one row per experiment)

| # | Stage | One change | Hypothesis | Command (short) | Seeds result | Health (KL / clip / ent / EV / invalid) | Decision |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | S0 | none: `train_ppo` defaults (lr 3e-4, minibatch 4096, ent 0.01) on `plain-train.json` | reproduces +31..+34% | `--steps 3000000`, `runs/flow/s0-seed*` | +22.0 / +20.5 / +3.0, **mean +15.2%, spread 19 points** | 0.0000 / 0.00 / 0.90 / 0.93 / 0 at the end | **not reproduced.** Healthy plumbing (EV 0.93, 0 invalid), but KL 0 by the end and seed 3 stuck near the rule. The documented runs (`ppo-s*`, +31%) used lr 1e-3, minibatch 1024, ent 0.003 and the older `train.json` catalogue. Next: their settings, catalogue unchanged |
| 2 | S0 | the documented trainer settings (lr 1e-3, minibatch 1024, ent 0.003), catalogue unchanged (`plain-train.json`) | reaches the +31% | `runs/flow/e2-seed*` @ 3bc4585 | +13.1 / +9.8 / +14.9, **mean +12.6%, spread 5** | 0.0004 / 0.00 / 0.33-0.42 / 0.98 / 0 | not reproduced. Tighter seeds and a healthier mid-run KL (0.005-0.009), but a lower mean |
| 3 | S0 | the documented catalogue (`train.json` / `eval.json`) with row 2's settings: the exact documented command | reaches the +31% | `runs/flow/e3-seed*` @ 3bc4585 | +17.5 / +16.3 / +13.6, **mean +15.8%, spread 3.9** | 0.0004 / 0.00 / 0.33-0.37 / 0.97 / 0 | **the old +31% cannot be reproduced on today's code.** The old model no longer loads: its state had 18 columns, today's has 17. Since 25 Oct the features, the player rule (`mine while short`, prerequisites) and the logins (night) have all changed, so the old number was measured against a different, weaker yardstick. **S0 exit met, re-based (planner, 26 Oct):** the trainer works, all 3 seeds beat the rule, the spread is small and the health is clean. **The S0 baseline is row 3: +15.8% ± 2.** Next: S1, the same command plus `--login-gaps storage/rl/ogsim/login-gaps.json` |
| 4 | S1 | none (no code change): row 3's exact command plus `--login-gaps storage/rl/ogsim/login-gaps.json`, so the account decides only at logins — median gap 2 880 s, mean 8 035 s, night-aware, about 30-60 decisions over the 72 h horizon instead of one per idle queue | the learner gains **more** than at S0: each decision now has to keep the queue busy across a long idle window, which the myopic rule (`mine while short`, cheapest reachable unlock) does not plan for, so the eval mean rises above S0's +15.8%. Expected **+30..+90%**; the documented +90..+100% is a stretch, since rows 1-3 showed the documented numbers came from a different yardstick. **Keep rule:** keep as the S1 baseline only if the 3-seed mean beats S0's upper spread (+19.7%) in that direction with `invalid` 0, EV > 0 and KL not 0 from the first update; otherwise revert to the S0 command and the stage stays open | `--catalogue storage/rl/ogsim/train.json --eval storage/rl/ogsim/eval.json --login-gaps storage/rl/ogsim/login-gaps.json --lr 1e-3 --minibatch 1024 --ent 0.003 --steps 3000000`, `runs/flow/e4-seed{1,2,3}` @ 6b8cbb7 | pending | pending | running |
