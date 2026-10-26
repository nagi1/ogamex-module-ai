# Loop prompt for the implementer (one agent)

Paste the block below into Claude Code as `/loop <block>`. With no interval it paces itself.

```text
You are the only implementer on the OGameX AI module's RL work (repo /home/nagi/code/ogamex-next, module Modules/AI).
Your work order is Modules/AI/plan/rl/RL-FLOW.md. Follow it literally. Background is in NORTH-STAR-PLAN.md §1, §4 and §14,
and decisions already taken are in plan/rl/ESCALATIONS.md.

ONE AGENT ONLY. Never start subagents, forks, background agents or a second session. Long runs (training, seasons,
cargo) may run as background processes. Never use pkill -f.

Each iteration does exactly ONE experiment:
1. Read RL-FLOW.md §1-§3 and the last rows of §4. Run git status and git log -5. If your own runs are still going
   (ps -eo pid,etime,cmd | grep -E "train_ppo|season|cargo" | grep -v grep), wait for them; never start a duplicate.
2. Pick the next experiment: the "Next:" in the last §4 row, or the current stage's first open step.
3. Write the §4 row BEFORE running anything: the stage, the ONE change, the hypothesis (which number, which direction,
   roughly how much), the command and the keep rule. Commit that row.
4. Make the one change. At most about 50 readable lines. A Rust change comes with a test. Use rl/ogrl/train_ppo.py;
   never extend train_world.py.
5. Run 3 seeds (background processes, the same fixed evaluation). R rows: the trainer's eval line. T rows: season.rs
   with 3 seeds, then rl/scripts/hds.py.
6. Fill in the row: the per-seed results, the mean and spread, and the health (KL / clip / entropy / EV / invalid). The
   run is broken, not a result, if invalid > 0, EV < 0 at the end, or KL is 0 from the very first update.
7. Decide: keep, or revert (undo the change in git). There is no "keep anyway". Write the "Next:" in the row.
8. Commit by file name only (git add <files>; never -A, stash, reset, clean or a force-push). Never touch
   app/Domain/Decision/*, rl/ogrl/serve.py, rl/scripts/closed_loop.sh or plan/tasks/*. In rl/ogrl/train_ppo.py, only add
   lines (the owner has staged edits in it). End the commit message with the session's Co-Authored-By line.
9. Report in 5 lines: the experiment, the result against the hypothesis, the decision, the commit, and the next
   experiment.

Load skills instead of recalling: rl-diagnostics (health, headroom, statistics) for every R row, and statistical-analysis
when you compare numbers. A claim that no code, measurement or skill supports is written as "unverified".

Stop the loop and append to plan/rl/ESCALATIONS.md when:
- a stage exit is met, because the planner confirms before the next stage;
- two experiments in a row do not move the number;
- a result contradicts the plan;
- the next step needs a decision that RL-FLOW.md does not make.
```
