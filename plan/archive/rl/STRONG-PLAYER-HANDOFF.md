# Strong player: handoff (4 Nov 2026)

For the next agent that takes over the strong-player and late-game work. Read this first, then the log in
`STRONG-PLAYER-PLAN.md`. This file covers the state, the owner's rules, the tools, what is known, and the open issues
to check before building on any number below.

## Owner direction, latest first

1. **Late game first.** Late game, defeating a "final boss" (a very strong player) using every tactic. Nothing else
   until the late game is perfected.
2. **No run longer than 10 minutes.** Know early whether a model is good; optimise the simulator so a short run says
   as much as possible (memory `ten-minute-runs`).
3. **Every player plays the whole game:** early, mid and above all late.
4. **Rust and GPUs.** Train in the Rust simulator (`rl/ogsim`), not PHP. Neural training goes on the 4070
   (`cuda:1` with `CUDA_DEVICE_ORDER=PCI_BUS_ID`).
5. **Standing rules (see `MEMORY.md`):**
   - root cause first, no knob sweeps;
   - proven methods;
   - win first, with no reward gaming;
   - a short plain-English status after every step;
   - CPU and RAM at most 85%: launch through `rl/scripts/capped.sh NAME LOG CMD...`, which drops env vars, so use
     `env K=V`;
   - clean up disk;
   - commit only named files.

## Where things are

| What | Where |
| --- | --- |
| Simulator | `rl/ogsim` (Rust). Build with `export PATH=$HOME/.cargo/bin:$PATH; cargo build --release --example season --example late` |
| Season runner | `rl/ogsim/examples/season.rs`. Champion arms: `champion=`, `champion-label`, `champion-solo`, `champion-errands`, `champion-every=`, `champion-from=`, `champion-late=`, `champion-base` |
| Late bench | `rl/ogsim/examples/late.rs`. Usage is in its header; details below |
| Look-ahead | `Universe::lookahead`, `lookahead_errand` and `rollout_worths` in `rl/ogsim/src/season.rs`. Fields `rollout_policy` and `value_to_end` |
| Policy inference | `Policy` in `src/season.rs`: flat layers, eight-lane `dot`. Also `params`, `with_params` and `to_json` |
| Trainers | `rl/ogrl/train_exit.py`: imitation of the champion, stakes-weighted, reports high-stakes regret. `rl/ogrl/train_value.py`: critic. `rl/ogrl/es_world.py`: whole-season ES |
| Judges | `paired_days.py` (seed-paired log-ratio by day). `storage/rl/ogsim/runs/train4/shortjudge.sh TAG DAYS POLICY [greedy]` (10 seeds; long, so prefer the bench) |
| Runs and models | `storage/rl/ogsim/runs/train4/` |
| Plan and log | `plan/rl/STRONG-PLAYER-PLAN.md` |

**Models in `train4/`:**
- `best-es3-s10.policy.json`: today's best full-season model, ES-trained, played sampled;
- `stakes/student-4.policy.json`: best student, played greedy;
- `dagger-r1a/student-2.policy.json` and `full-student-3.policy.json`: older students;
- `bc-errand.policy.json`: the fleet errand model.

**Binaries in `train4/`:**
- `season-exit`, the judge binary. It has the fast inference; `season-exit.old` is the previous build.
- `late/late`, the bench.

**Label sets for imitation, all from the 12 h champion:**
- `exit-it1/`, `dagger-r1*/`, `dagger-r2*/`;
- `full-r3a`, `full-r3b` (stopped at days 124–194);
- `full-r4` (stopped mid-season; sampled 1 in 8, from day 20, student 4 greedy).

## The late bench (use this, not full seasons)

```
late DATA ECON START WINDOW SEEDS cand=NAME=SPEC ... [world arms]
```

- **How it works:** each seed's world is played to START by the personas once, then copied per candidate. Every copy
  plays the same WINDOW with the same dice.
- **Output:** the paired log-ratio of points against the first candidate, production, planets, research, military
  and unspent stock.
- **Speed:** day 120 → 365 on 6 seeds takes about 4–6 minutes.
- **SPEC forms:**

  | SPEC | Meaning |
  | --- | --- |
  | `persona` | the scripted personas |
  | `greedy:P`, `sampled:P` | policy P on every account |
  | `champ:BASE:H:R:STRIDE[:WIDTH]`, `champv:...` | look-ahead seats; `champv` scores rollouts to the season's end |
  | `switch:DAY:A\|B` | A before DAY, B after |
  | `with:T1+-T2:SPEC` | persona tactic switches on or off for all accounts |
  | `es=OUT es-base=P es-minutes=9` | late ES in process |

- **Example**, from `train4` with `$data=storage/rl/ogsim/r8w` and the world arms `moonstack ... class=3 expofull`
  (the list is in `shortjudge.sh`):

  ```
  late/late $data $data/units-parity/econ-u1-colonies.json 120 245 1,2,3 cand=es3=sampled:best-es3-s10.policy.json cand=persona=persona $arms
  ```

## What is known (measured; numbers in the plan log)

1. **Early game is won.** Student 4 greedy beats best-es3: 7.6× at day 3, 3.2× at day 10, 1.8× at day 30, on 10/10
   seeds. Two fixes got there:
   - students are judged greedy, not sampled;
   - imitation is weighted by decision stakes. Three in four champion decisions are near-ties inside rollout noise.
2. **The early lead does not survive.**
   - Student 4 alone is 0.37× at day 365.
   - Student 4 handing over to best-es3 at day 30 equals best-es3 exactly by day 200. The late game converges to one
     ceiling.
3. **Best-es3 sits on the late ceiling.** None of these beat it from day 120:
   - the 12 h champion: worth is the next hours' production, which no late build moves;
   - the champion with best-es3 rollouts and season-end scoring;
   - late ES (11 generations): 0.998×;
   - removing the yard cap: 0.969×, because the yard starves research and production.
4. **Late points come from expeditions, not fighting.** Top accounts take in about 150–170 G from expeditions between
   days 120 and 365, roughly half their points, and loot under 1 G. Top accounts never fight each other late: most
   are fleet savers, and raids only find nearby weak targets.
5. **Speed.** Model inference was about 90% of a season. It is now 3.75× faster with identical results. The GPU does
   not help sequential single decisions.

## Open issues: check these before building on the results above

1. **Expedition fidelity.** About 150 G late income per top account looks generous: production-sized, and it decides
   the whole late game. Check it against real OGame rules: the find bracket by rank-1 points, the fleet-size caps, the
   expedition slot count by astrophysics, and the black-hole and loss rates. If it is too generous, every late-game
   conclusion above changes. (The plan's F3 already suspects late generosity: our median at day 365 is above the real
   top 1%.)
2. **No late PvP.** The "final boss" cannot be defeated today because nobody attacks strong accounts. Planned and not
   built:
   - a boss duel in the bench: rank-1 at day 120 as the boss, with every tactic; a few top accounts as challengers;
     reporting points, fleet destroyed and loot between them;
   - a challenger attack tactic: spy the boss, attack when the battle simulation wins, recycle, fleet-save against
     counters;
   - training those choices with the bench ES, scored by the win rate.

   First check `errands::candidates`: raid targets are the nearest neighbours plus scouted rich targets, so the boss
   may never be offered.
3. **The bench starts from persona worlds.** Day-120 positions are played by the personas. Errands in the bench are
   the personas' own. The judge (`shortjudge.sh`) routes the first 21 accounts' errands through the errand door
   (`fleet-model=21 errand-policy=teacher`). The rankings agreed so far, but a candidate that changes errands needs the
   same door in the bench.
4. **Late ES signal.** Antithetic pairs share dice and draws, but one diverging choice changes everything after it.
   Votes are mostly noise at sigma 0.02 over 30 days. Before more ES, measure the vote's signal against a ±0 pair.
5. **The champion's objective.** `worth = points × 1000 + stock` is total production over the horizon. It cannot see
   payback beyond the horizon. `value_to_end` adds `hourly × time left`, which ignores expeditions and research
   effects. Any further champion work must fix the objective first.
6. **Labels.** Every label set comes from the 12 h champion, which is even with best-es3 by day 120. Late labels from
   it cannot teach a student to beat best-es3. `full-r4` labels are unused.
7. **Realism versus score.** Military share is capped (`cap`) to stay inside real bands. A win-first boss may drop
   it, but the result changes the observable behaviour; say so in any claim.

## Housekeeping

- **Nothing is running.**
- **Large run dirs (about 4 GB):** `full-r3a/b`, `full-r4`, `short-s3-365`, `short-s4-g365`, `short-s2-g365`,
  `champ-year12`, `champ-late48`. The short-* and champ-* dirs are cited in the plan log: compress them with `xz`,
  don't delete. The full-* label sets are unused; keep them only if a student is trained from them.
- **Scratch traces** in the session scratchpad can go.
