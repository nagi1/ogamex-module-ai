# Loop prompt for the implementer (one agent)

Paste the block below into Claude Code as `/loop <block>`. With no interval it paces itself.

```text
You are the only implementer on the OGameX AI module's RL work (repo /home/nagi/code/ogamex-next, module Modules/AI).
Your work order is Modules/AI/plan/rl/RL-FLOW.md. Follow it literally. Background is in NORTH-STAR-PLAN.md §1, §4 and §14,
and decisions already taken are in plan/rl/ESCALATIONS.md.

ONE AGENT ONLY. Never start subagents, forks, background agents or a second session. Long runs (training, seasons,
cargo) may run as background processes. Never use pkill -f.

Each iteration does exactly ONE row. A row makes one change, or runs several one-change arms side by side, each
behind its own season argument (off by default) and each judged alone against the same baseline. RL-FLOW.md §0 holds
the rules learned up to 27 Oct; read it first.
1. Read RL-FLOW.md §1-§3 and the last rows of §4. Run git status and git log -5. If your own runs are still going
   (ps -eo pid,etime,cmd | grep -E "train_ppo|season|cargo" | grep -v grep), wait for them; never start a duplicate.
2. Pick the next row: the lowest-numbered §4 row whose result columns are empty and that is not marked deferred,
   superseded or waits. Each row says in its keep rule what follows each result; follow that without asking.
3. Write the §4 row BEFORE running anything: the stage, the ONE change, the hypothesis (which number, which direction,
   roughly how much), the command and the keep rule. Commit that row.
4. Make the one change. At most about 50 readable lines. A Rust change comes with a test. Use rl/ogrl/train_ppo.py;
   never extend train_world.py.
5. If any file under Modules/AI/rl/ogsim/src is newer than the installed ogsim wheel, rebuild first:
   `cd Modules/AI/rl/ogsim && ../../../../.venv-rl/bin/maturin develop --release` (the wheel went stale once). Then
   run the seeds as background processes, at most 26 at once, never pinned. T rows: season.rs, 10 seeds × 365 days,
   then rl/scripts/hds.py, growth.py, the ledger shares by age and (from row 29) stall.py. R rows: 3 training seeds,
   the trainer's eval line, then the paired-twin evaluation the row names. GPU on cuda:1 with
   CUDA_DEVICE_ORDER=PCI_BUS_ID.
6. Fill in the row: the per-seed results, the mean and spread, and the health (KL / clip / entropy / EV / invalid). The
   run is broken, not a result, if invalid > 0, EV < 0 at the end, or KL is 0 from the very first update.
7. Decide: keep, or revert (undo the change in git). There is no "keep anyway". Write the "Next:" in the row.
8. Commit by file name only (git add <files>; never -A, stash, reset, clean or a force-push). Never touch
   app/Domain/Decision/*, rl/ogrl/serve.py, rl/scripts/closed_loop.sh or plan/tasks/*. In rl/ogrl/train_ppo.py, only add
   lines (the owner has staged edits in it). End the commit message with the session's Co-Authored-By line.
9. Report in 5 lines: the experiment, the result against the hypothesis, the decision, the commit, and the next
   experiment.

Every headline number in a row (a share, a count, a growth figure) comes from rl/scripts/census.py, growth.py or hds.py run
on the raw season lines, with the command in the row. A number taken from a log or written from memory is marked
"unverified". RL-FLOW.md §0 rule 7: row 36's prose and its raw lines disagreed.

Load skills instead of recalling: rl-diagnostics (health, headroom, statistics) for every R row, and statistical-analysis
when you compare numbers. A claim that no code, measurement or skill supports is written as "unverified".

Do not end the loop at a stop (since 28 Oct). Append the dated escalation to plan/rl/ESCALATIONS.md, commit it, then
check every 30 minutes for a newer "Planner" entry below yours and continue from it the moment it is there. End the loop
only after 12 hours with no answer, or at a stage exit. The stop points are:
- a stage exit is met (the planner, or the owner's PHP closed loop, confirms before the next stage);
- a row's own keep rule says stop, or its result falls outside every branch the row pre-commits;
- two rows in a row do not move their numbers beyond the spread;
- a test can pass only by changing its assertion, or a ledger line stops closing;
- the next step needs a decision that RL-FLOW.md does not make.
```
