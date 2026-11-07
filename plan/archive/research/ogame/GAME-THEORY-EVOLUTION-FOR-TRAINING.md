# Game theory and evolution for our training (5 Nov 2026)

Where we are: BC clone (C) then OpenAI-ES (D: antithetic pairs, centred ranks, sigma 0.02, lr 0.01). ES plateaued at
g14 (confirm2: g20 vs g14 +0.042, CI [-0.021, 0.105]). Model accounts (<21) play against FIXED teacher accounts.

## Game theory
1. League / fictitious self-play / PSRO (Heinrich & Silver 2016; Lanctot et al. 2017; AlphaStar, Vinyals et al. 2019):
   opponents drawn from a pool of frozen checkpoints (teacher, g14, g20, ...) instead of the teacher only. Stops the
   model from fitting one opponent's habits. Needs: per-account errand policy in the season (sim change, no slowdown).
2. Exploiters (AlphaStar): a short ES run whose only goal is beating g14 (e.g. a raider specialist); g14 then trains
   against it. Fits late-game deception/defence.
3. Evaluation by payoff matrix + Nash averaging / alpha-Rank (Balduzzi et al. 2018; Omidshafiei et al. 2019) instead
   of "vs gate-0" only: catches cycles (A beats B beats C beats A).
4. Repeated games (iterated prisoner's dilemma, tit-for-tat, reputation; Axelrod) -> foreign ministry design.

## Evolutionary game theory
5. Personas (miner/raider/saver) are strategy shares; replicator dynamics: a share grows by its payoff over the mean.
   Real servers sit near a stable mix (ESS). Diagnostic: if our sim's stable mix differs from the real mix, a sim rule
   is wrong (cheap check, no training).

## Evolution theory
6. Population-based training (Jaderberg et al. 2017): N ES runs in parallel with different sigma/lr/anchor; every k
   generations the worst copy the best and perturb. Attacks the plateau with the 72 CPUs, no sim change.
7. Adaptive step (CMA-ES / sep-CMA, NES; Hansen 2016; Wierstra et al. 2014): sigma fixed at 0.02 now.
8. Quality-diversity (MAP-Elites, Mouret & Clune 2015; novelty search, Lehman & Stanley 2011): archive of elites per
   behaviour niche (ship mix, mission mix, phase) -> "use every ship" and a diverse opponent pool for item 1.
9. Hall of fame against Red-Queen cycling in co-evolution.

## Order (largest gap first, each with a <= 15 min go/no-go)
- A: PBT over ES (4 members x ~10 seeds pairs). Go: best member vs g14 paired score CI > 0 within 1 h.
- B: replicator diagnostic on personas vs real data (no training).
- C: league opponents (sim: per-account policy). Go: score vs a held-out checkpoint mix up, HDS not down.
- D: MAP-Elites for ship/mission coverage; E: repeated games for the foreign ministry.

## Experiment list and parallel plan (5 Nov)
Budget: ~60 season threads (72 CPU x 85%), cuda:1. One ES generation (20 pairs, 365 d) ≈ 4 min on 40 threads, so
CPU, not GPU, is the bottleneck: at most one 40-thread job + small jobs at a time.

| # | Experiment | Needs | Files (owner) | Go/no-go (≤ 15 min) |
|---|---|---|---|---|
| E0 | errands=4 hotspot fix (0x108b30) | - | rl/ogsim src + examples (Agent R) | wall ≤ +15% vs spy-a, same tallies |
| E1 | PBT + adaptive sigma over ES from C4 | C4 | ogrl/pbt_es.py new (Agent P) | best member vs C4 start paired CI > 0 |
| E2 | Replicator diagnostic: persona shares vs real | levels dumps | rl/scripts/replicator.py new (Agent A) | report: sim ESS vs real mix |
| E3 | Per-account policy in a season (`errand-policy=A,B@split`) | E0 done | rl/ogsim (Agent R, after E0) | same-policy run identical to today, wall same |
| E4 | Payoff matrix teacher/g14/g20/C4 + Nash avg / alpha-Rank | E3 | rl/scripts/payoff.py new | matrix with CIs; cycles found or not |
| E5 | League ES: opponents from a pool + hall of fame | E3 | ogrl/es_world.py `--opponents` | vs held-out mix up, top10+mid not down |
| E6 | Exploiter: short ES to beat g14, then g14 vs it | E3, E5 | es_world.py | exploiter beats g14; retrained g14 recovers |
| E7 | Reward variants: relative (own − rivals' mean), late-game survival | - | es_world.py `--reward` | 15-min slice vs plain reward |
| E8 | MAP-Elites archive by ship/mission mix | E5 | ogrl/qd.py new | niches filled, every ship used by some elite |
| E9 | Repeated games for the foreign ministry | D done | design doc | design reviewed |

Waves:
- W1 now: CPU = D slice from C4 (15 min, 40 thr) then E1 (≤ 1 h). Agents in parallel: R (E0 then E3), A (E2),
  P (E1 code, then E7 flag; owns es_world.py + pbt_es.py). No two agents on one file.
- W2 when E3 verified: E4 (small: 4 policies x 5 seeds), then E5 15-min slice, then E6.
- W3: E8, E9. Anything that fails its measure stops; nothing runs > 1 h before confirmation.

## Revised plan (owner, 5 Nov): only the nine items above, in parallel tracks
The per-account-policy sim change does NOT depend on the errands fix (dropped E0/E7 from this plan).

| Track | Items | Owner / files | CPU | Go/no-go |
|---|---|---|---|---|
| T1 evolution | 6 PBT + 7 adaptive sigma | Agent P: ogrl/pbt_es.py (new) | 40 thr, 15 min then ≤ 1 h | best member vs C4 start paired CI > 0 |
| T2 EGT | 5 replicator / ESS check | Agent A: rl/scripts/replicator.py (new) | ~0 | sim stable mix vs real mix report |
| T3 sim | per-account policy (base of 1, 2, 3, 9) | Agent R: rl/ogsim src + examples | build only | same-policy run identical, wall same |
| T4 eval | 3 payoff matrix + Nash avg / alpha-Rank | Agent A after T2: rl/scripts/payoff.py | 4 policies x 5 seeds | matrix with CIs |
| T5 league | 1 league + 9 hall of fame, then 2 exploiter | Agent P after T1: ogrl/es_world.py `--opponents` | 40 thr, 15 min slice | vs held-out mix up, top10+mid not down |
| T6 QD | 8 MAP-Elites | Agent Q: ogrl/qd.py (new) | after T5 | more niches filled, every ship used |
| T7 diplomacy | 4 repeated games design | research agent: plan doc only | 0 | design ready for after D |

Now in parallel: T1 code, T2, T3, T7. CPU: T1 slice as soon as its code is verified. Then T4 → T5 → T6.
