# Next steps and status (updated 4 October 2026)

How to work on this: `.github/skills/ogame-rl-training/SKILL.md` (also `.claude/skills/ogame-rl-training`).
The handout for the training machine: [HANDOUT-local-agent.md](HANDOUT-local-agent.md).

## 1. Status

| Phase | State | Where |
| --- | --- | --- |
| 0 Environment hygiene | **Done**, merged to `main` (host + module) | seedable `Random\Randomizer`, live battle seeds, portable SQL (`CoordinateDistanceCalculator`, `WreckFieldService`), report-copy cache fix, `symfony/yaml` declared, SQLite date lookups, `ai:sim --in-memory --seed --save-sqlite`; gate G1 passed (MySQL = SQLite under one seed on a battle-heavy state) |
| 1 Cheap PHP speed-ups | **Not started** | next cloud task: one snapshot per login, no triple planning, N+1 `users` reads; each proven behaviour-neutral with `--seed` + `bench/digest.php` |
| 2 Choice seam + dataset | **Done**, merged to `main` | `QueueableBuildingPlanner::choiceSets`, `DecideAiEconomyStepsAction`, `EconomyChoiceEncoder` (v1: 45 state, 27 candidate features), teacher/epsilon/socket policies, `ChoiceRecorder`, `ai:rl-universe`, `ai:sim --choice-policy --choice-epsilon --choice-socket --learner-share --record-choices`, `tests/Feature/EconomyChoiceSeamTest.php`. Recording proven behaviour-neutral (same seed, identical digest with and without it) |
| 3 Behaviour cloning | **Code done, not yet run** | `rl/` package (`ogrl.data`, `model`, `train_bc`, `metrics`, `export`, `serve`, `evaluate`), `rl/scripts/generate.sh`, `rl/scripts/closed_loop.sh`. Runs on the RTX machine (handout) |
| 4 PPO on C1–C3 | Not started, gated on the two BC gates | skill `references/improving.md` §5 |
| 5 Production shadow mode | Not started | roadmap |

## 2. What depends on the owner

1. **Run the handout** on the Xeon + RTX 3090 machine (or give it to the local agent there) and keep its
   Results rows below.
2. **The boundary decision**: the learned policy re-ranks the planner's own candidates behind a switch, with the
   planner as default and fallback (`docs/architecture-diagnosis.md` §5 said no RL in the play loop).
3. **Production weights**: if the learned policy ever plays live, `ai.cognition.affect.decision_weight` and
   `experience.decision_weight` must be the same as in training (0) for those accounts, or become features.
4. Known module test failures on `main` (8, none from this work; the local agent fixes the small ones):
   `AiExploitationGuardTest` ×2, `AiOperationsTest`, `ColonySpyExecutorTest`, `CoverageCompletionTest`,
   `ExpeditionDispatchSlotTest`, `SpyDepthTest`, `TransferDepthTest`.
5. Optional: `ogamex:scheduler:process-planet-queues` now runs every minute in production but not in `ai:sim`;
   decide whether simulations should run it too (it changes when planets are touched, see
   `game-simulation-audit.md`, update-cadence section).

## 3. Language-model classification and RL

Kept out of data generation, training, the reward and play. Where it belongs: `plan/details/specs/classification-use-plan.md`.

## 4. Results (append one row per run; newest last)

| Date | Commits (host / module) | What | Numbers |
| --- | --- | --- | --- |
| 2026-10-04 | c7c5dc0 / dd111c9 | Cloud: seeded day-10 state, 12 h, MySQL vs MySQL vs SQLite | identical digests (G1 pass); MySQL 168–172 s, SQLite 128–132 s |
| 2026-10-04 | 39174b7 / (merge) | Cloud: 1 universe × 12 accounts × 24 h, recording on/off | identical digests; 149 choice points, 6–12 candidates, ~4.9 legal, teacher always legal |
| 2026-10-05 | 39174b7 / e303c7a | Workstation (24 threads, RTX 3090, WSL2): BC data v1, 63 universes at speed 8 (bc seeds 1–16 cut at ~day 19.5, bc2 17–28 cut at ~day 13, bc3 29–52 16 days, 13 finished; staged bc4 201–207 and bc5 301–304, 1 day). Three host freezes/WSL shutdowns cut runs; cut batches trimmed to whole lines and kept as final (`.partial`) | 484,814 points, 339,405 with 2+ legal, 0 sim errors, validation pass; trivial baseline "first legal non-wait row" = 0.950 of 3+ legal rows (gate is weak evidence); late phase ~3% of rows, only from staged starts; Space Dock is most no-value picks (2.7% of cheap-mine choices) |
| 2026-10-05 | 39174b7 / e303c7a | BC v1 on the GPU, 30 epochs, ~1 s/epoch, 278,786 parameters | top1_3plus_legal 0.995 (gate 0.90), mrr 0.996 (gate 0.93), top1_hard_rows 0.931 (random 0.22; hard rows = 4.2% where the first legal row is not the teacher's), research 0.968 vs building 1.000 |
| 2026-10-05 | 39174b7 / e303c7a | BC v1 closed loop: 4 clean pairs (seeds 1001–1004) × 12 accounts × 12 days, learner share 0.25; pairs 1005–1008 dropped (their policy side started after a teacher change) | ΔV −0.05%, 95% CI [−5.6%, +5.5%], n=14 learner accounts, share better 0.29; 0 choice(s) fell back in every policy log. Passes as stated, but n is small and the horizon short |
| 2026-10-05 | 39174b7 / 0816ca6 | Root cause of "no late game": fixed 600 s login gap at high speed (one step per login), and staged late planets that idled on full stores (field-full planet never raised its field cap; technology priced above the store never paid; ambition vault above the store). Teacher fixed, `--stage-budget` staging and `--full-play` added; v1 data archived to `storage/rl/archive-v1`, v2 data regenerating (bc6 organic 16 × 16 days, bc7 staged 36 × 2 days full play) | v2 numbers to follow |
| 2026-10-06 | 39174b7 / 262f951 + uncommitted | Schema v2 and two new choice kinds: planet yard (ships and defence) and login errand (the engine's ranked action), both recorded and applied through the same seam. Teacher-neutral: 12 accounts × 18 h staged, with and without recording, byte-identical planets and tech and mission count, 1571 sessions each, 0 errors; all four kinds recorded | v1 data archived; first-legal baseline falls from 0.95 to 0.50, so the offline gate is far harder to pass by copying |
| 2026-10-06 | same | Data v2: 76 universes, 40 organic (4 days, speeds 4/8/16) + 36 staged (1 day, budgets 2e7–2e11, full play), 60% CPU after repeated WSL freezes; universes kept as they finish so a freeze loses only the sims in flight | 452,418 points, 338,506 with 2+ legal, 0 sim errors, validation pass (0 blocking, 0 warnings); kinds building 127,924 / yard 102,843 / errand 94,564 / research 13,175 |
| 2026-10-06 | same | BC v2 on the GPU, width 1024 (4.29 M parameters), 80 epochs, rows weighted by (kind, phase) cell share^-1; an 18-run sweep over width, balance and learning rate spread only 1 point, so settings are not the lever | top1_3plus_legal 0.906 (gate 0.90), mrr 0.952 (gate 0.93), top1_hard_rows 0.845; by kind building 1.00, research 1.00, yard 0.87, errand 0.83; by phase early 0.93, mid 0.90, late 0.93 |
| 2026-10-06 | same | BC v2 closed loop: 16 pairs (seeds 1001–1016) × 12 accounts × 12 days, the model decides 100% of choices (share 1.0), 14 sims in parallel | 0 sim errors, 0 choice(s) fell back in all 16 policy logs. Mean of per-account ratios ΔV +14.5%, CI [+3.4%, +25.5%], n=192: outside the ±5% band by the gate's letter (FAIL on the upside). Robust measures show parity: total value ratio 1.016, median +0.3%, geometric mean +2.6%, better in 50.5%; the mean is lifted by a few accounts at 3–9× the teacher |
| 2026-10-06 | 0c49ab1 | BC v3 closed loop (eval3): 16 pairs × 12 accounts × 12 days, share 1.0, colony in-flight and lane-scoped refusal fixes in the teacher | 0 sim errors, 0 choice(s) fell back in all 16 policy logs. Total value ratio 0.984 (-1.6%), median +3.6%, geometric mean +2.1%; mean of per-account ratios +13.4%, CI [+4.4%, +22.4%], n=192 (skewed by a few large winners, robust stats are the verdict). Within the ±5% band. |
| 2026-10-06 | 0c49ab1 + bc-v4 | BC v4 (v3 data + DAgger round 2: 1,153,216 rows, 172 universes; top1_3plus_legal 0.921, mrr 0.960) closed loop (eval4): 16 pairs (seeds 1201–1216) × 12 accounts × 12 days, share 1.0 | 0 sim errors, 0 choice(s) fell back in all 16 policy logs. Total value ratio 1.038 (+3.8%), median +3.7%, geometric mean +7.2%; mean of per-account ratios +22.6%, n=192. Within the ±5% band on total value; better than v3 (-1.6%). |
| 2026-10-10 | module 67c93dd..de78923 | **BC v5 data started (late-weighted):** 18 staged universes × 12 accounts × 1 day, full play, budgets cycled 2e10 / 2e11 / 1e12, epsilon 0.1, seeds 3001–3018, 6 parallel (generate.sh, `storage/rl/bc5`). Why now: war 3/3, greetings gone, truces agreed and kept/broken in play across 6 worlds, every mission and ship class reached in 9484–9506; v4 had late rows ~3% | in progress; result row follows data → BC v5 → closed loop (twin seeds 1301–1316) |
| 2026-10-11 | 9aa8003 | **BC v6 closed loop (eval6), full cognition, schema v3, first model on the officer-widened candidate vector:** trained on 7 of the 18 planned late-staged universes (94k rows; v7 re-trained without the error-contaminated 3003: 72k rows, top1 0.832, top1 on 3+ legal 0.806 held out by universe, random 0.199). 8 pairs (seeds 1301–1308) × 12 accounts × 1 day, share 0.25, 95 accounts per arm: relative value model minus planner **+0.045, 95% CI −0.049 to +0.139**, so parity with the teacher, not a win; 1 choice per sim fell back. Small sample (1 day, 8 pairs): a model that beats the planner needs more late rows (11 universes still to regenerate, retry added to generate.sh) before another closed loop. |
| 2026-10-11 | c42f994 | **Why the model stays at parity, read from the data (6 full-cognition universes, 72k rows):** (1) learning curve, held-out top-1 on 3+ legal rows: 3.9k rows 0.731, 9.7k 0.783, 19.5k 0.803, 39k 0.814 (±0.003 over 3 seeds), about +1 point per doubling now; (2) capacity is not the limit: 22k parameters 0.804, 78k 0.815, 287k 0.811 to 0.819, 1.1M 0.817; (3) outcome signal per choice is nil at account level: a random legal choice instead of the teacher's changes 6 h production growth by −0.0002 ± 0.002 against a spread of 0.14 (3 h account value: −0.00001 ± 0.00002), so advantage-weighted training has nothing to learn from; (4) the gap is the yard: held-out top-1 by kind building 0.995, errand 0.78, **yard 0.61 (train 0.87)** at every size. The yard planner orders the unit furthest behind its doctrine template, holds reinforcement under a threat response and switches on the escort share, and none of it was in the row. **Fix (c42f994, schema v4):** per-unit template gap and listed flag, reinforce flag and escort share in the yard row; the gap maths now lives in `ArchetypeDoctrine::templateGaps`, read by the planner and the encoder. The v3 batch (bc5) is retired to `storage/rl/discarded/`; v4 data is generating in `storage/rl/bc6` (seeds 4001 to 4012, full cognition, 12 accounts, 1 day, epsilon 0.1). Next: retrain and compare yard top-1 on held-out universes (target: above 0.8), then the closed loop with 16 pairs. |
