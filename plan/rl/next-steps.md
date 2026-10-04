# Next steps (after Phase 0, 4 October 2026)

## 1. What Phase 0 changed

| Repo | Change | Why |
| --- | --- | --- |
| module | `composer.json` requires `symfony/yaml` | Every session reads YAML; a `--no-dev` install broke all of them. |
| module | `RecordAiStopReasonAction` looks up `observed_on` with `startOfDay()`; `SummarizeAiOperabilityAction` reads with `whereDate` | The bare-date lookup never matched on SQLite (4,881 failed sessions). Same result on MySQL. |
| module | `ai:sim --in-memory`, `--seed=N`, `--save-sqlite=path` | Play a copy of any database in SQLite `:memory:` (source only read), replay with a fixed seed, save snapshots. Forking (`--workers`) is refused in memory mode. |
| host | `composer.lock`: `symfony/yaml` moves from dev to production packages (same v8.1.8) | Follows the module's new requirement through the merge plugin. |
| host | `Random\Randomizer` bound in `AppServiceProvider`; used by `ExpeditionMission`, `MoonDestructionMission`, `CounterEspionageService`, `DarkMatterService`, `MerchantService`, `NPCFleetGeneratorService`, `PlanetMoveService`, `PlanetServiceFactory`, `AppUtil::selectWeightedRandom` | One seedable source of game randomness. Default engine is the CSPRNG, so production randomness is unchanged. |
| host | `BattleEngine::simulateBattle` draws a seed from the Randomizer when none is given | Live battles (PHP and Rust engines) replay under a seeded run. |
| host | `CoordinateDistanceCalculator` binds `Date::now()` instead of SQL `NOW()`, portable `CASE` | Fleet durations were computed against the DB server's real clock, wrong under simulated time; MySQL-only SQL. |
| host | `WreckFieldService` orders with `CASE` instead of `FIELD()` | MySQL-only SQL. |
| host | `PlanetServiceFactory::makeFromModel` does not cache detached copies | A report copy could answer later `make()` calls for the live planet (HANDOFF §6.1). |

Verification: host suite 1,745 of 1,746 pass. The exception, `ModuleDoctorCommandTest`, expects the AI module
disabled and fails only because it is enabled in the test copy. Module suite: the same 25 failures as on the
original code, none new. `SavingsGoalSituationTest` failed once in the full suite and passed 3 times alone, a
known timing flake. Gate G1 passed (see [benchmark-plan.md](benchmark-plan.md#5-gates)).

## 2. Runbook on the Xeon Gold 6254 (about an hour)

1. Pull both branches (`claude/ogamex-rl-training-design-0p149v`), `composer install`, rebuild the Rust libs
   (`bash rust/compile.sh`), `php artisan migrate`.
2. Pick a source state: the grand cohort or any copy. `--in-memory` never writes it.
3. Determinism check (2 × 10 minutes):
   ```
   php artisan ai:sim --in-memory --native-cognition --seed=42 --hours=12 --from=<SIM_NOW> --save-sqlite=/tmp/a.sqlite
   php artisan ai:sim --in-memory --native-cognition --seed=42 --hours=12 --from=<SIM_NOW> --save-sqlite=/tmp/b.sqlite
   DB_CONNECTION=sqlite DB_DATABASE=/tmp/a.sqlite php storage/digest.php > a.txt   # copy bench/digest.php into storage/
   DB_CONNECTION=sqlite DB_DATABASE=/tmp/b.sqlite php storage/digest.php > b.txt
   diff a.txt b.txt   # must be empty
   ```
4. Throughput (the number that decides everything else):
   ```
   PHP="php" bash Modules/AI/plan/rl/bench/xeon-benchmark.sh 6 <SIM_NOW in daytime> "1 4 8 12 16 18"
   ```
   Report `per_second` per level. Expect linear growth to ~16; the knee shows the right worker count.
5. Paste the outputs into this file under "Results".

## 3. Language models and classification (Jev / `laravel/ai`): where they fit

**Short answer: keep every model call out of the training environment and out of the reward. Use
classification where the module reads human text or judges behaviour, then feed its stored, versioned
answers to the policy as ordinary features later.**

| Place | Verdict | Why |
| --- | --- | --- |
| Inside the RL environment step (scoring candidates, deciding builds) | **No** | A hosted call is 100+ ms of network per decision against ~250 ms of session; 16 workers × millions of calls; answers change when the model version moves, which breaks seeded replay and twin evaluation. The bill is modest (≈ $21 per million decisions at 500 input tokens, $0.042 per million tokens), so the reasons are latency and reproducibility. |
| As the reward or part of it | **No** | The policy would optimise the classifier's quirks (reward hacking against a model). ΔV is measured by the host. |
| As the teacher for imitation | **No** | The deterministic planners are the teacher; they are free, exact and replayable. |
| Social reading (the spec's J3 exchange cascade, J5 alliance pitch, J4 "I'm leaving / on holiday" declarations) | **Yes, as planned in `plan/details/specs/hosted-classification.md`** | That is text no rule reads well. The answer is stored once per message as a fact with its probability. |
| Later policy features (v3: trust, threat, declared absence of a neighbour) | **Yes, through stored facts** | The training environment replays the stored or synthetic facts and never calls the model. The policy sees a number, not a model. |
| Evaluation: "does this account's day read like a human player?" (Gate 3 read at scale) | **Yes, offline** | A sampled judgement over day logs in the evaluation report, never in training. Calibrate it against your own reads first. |
| Harness provenance (J7) | Yes | Unrelated to RL, no interference. |

It does not interfere with this plan as long as it stays in those places. The one real interaction is
section 2 of [current-system-audit.md](current-system-audit.md#6-coupling-to-socialcognitive-machinery-inside-gameplay-what-may-be-disabled):
anything that moves a gameplay score (today the affect and experience weights) must be either off on the
learned path or a recorded feature. A classifier answer that nudged gameplay directly would join that list.

## 4. The best move from here

1. **Merge Phase 0** (both branches). It is behaviour-neutral in production and makes the simulator fast,
   seeded and portable.
2. **Run the Xeon runbook** (section 2). One hour, and it replaces every throughput estimate with a measurement.
3. **Phase 1 + Phase 2 together** (next work in the cloud): the cheap PHP speed-ups, each proven
   behaviour-neutral with `--seed` + `digest.php`, and the choice-point seam in
   `QueueableBuildingPlanner::firstQueueable` with the feature encoder and the sample logger. Both are module
   code; development and parity checks run anywhere, the data generation runs on the Xeon.
4. **Phase 3 on the Xeon**: generate 10⁶ teacher samples (16 workers, a few hours), train the BC scorer on the
   3090, then the closed-loop twin test. This is the first point where the project can fail cheaply, which is
   why it comes before any RL.
5. Only then E2 (PPO). Do not start Rust, self-play, or learned raids before the BC closed-loop result.

## Results (fill in from the Xeon)

| Check | Result |
| --- | --- |
| Determinism (step 3) | |
| Throughput, universes = 1 / 4 / 8 / 12 / 16 / 18 | |
