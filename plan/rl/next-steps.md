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
