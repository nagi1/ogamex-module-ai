# Implementation roadmap

Each phase leaves the game playable and the deterministic AI unchanged for accounts not on the learned path.
Gates are defined in [benchmark-plan.md](benchmark-plan.md#5-gates).

## Phase 0: environment hygiene (host + module, small) — DONE 4 Oct 2026, see [next-steps.md](next-steps.md)

| | |
| --- | --- |
| Objective | A reproducible, fast, honest simulation environment. |
| Code | Module `composer.json` (declare `symfony/yaml`); `RecordAiStopReasonAction` (date lookup); host `CoordinateDistanceCalculator:159` and `WreckFieldService:129,:1012` (portable SQL, PHP time); host RNG seam (`Random\Randomizer` from the container in `ExpeditionMission`, `CounterEspionageService`, `BattleEngine` live seed, `PlanetServiceFactory`, `MoonDestructionMission`, `MerchantService`, `DarkMatterService`); `PlanetServiceFactory::makeFromModel` report-copy scoping; an `ai:sim` option for SQLite `:memory:` with snapshot load. |
| Success | `bench/parity.php` gives identical digests for MySQL vs MySQL **and** MySQL vs SQLite over 24 h from a day-10 battle-heavy snapshot, 3 seeds (gate G1). |
| Benchmark | B2/B6b rerun on the 6254; scaling at 8/12/16 workers. |
| Fallback | If the RNG seam is refused by the host owner: keep MySQL-vs-MySQL noise floor in all evaluations and use more seeds. |

## Phase 1: cheap PHP speed-ups (measure each)

| | |
| --- | --- |
| Objective | 2–3× fewer milliseconds per session without changing a decision. |
| Code | One `PlayerService`/snapshot per login passed to planners (diagnosis step 1); executors consume the plan carried in the payload instead of re-planning; `users`/`ai_profiles` read once per login; alliance life off in training. |
| Success | Same parity digest before/after on 3 seeds (behaviour unchanged) and ≥ 2× sessions/s mid-game. |
| Benchmark | per-phase session profile (`bench/sessionprof.php`). |
| Fallback | Each change is independent; drop any that changes the digest. |

## Phase 2: choice-point seam + dataset (no learning yet) — DONE 4 Oct 2026

| | |
| --- | --- |
| Objective | Expose C1/C2/C3 as `(state, candidates, teacher index)` and log them from simulation. |
| Code | A small `ChoicePolicy` contract in the module, default implementation = "first queueable" (today's behaviour), called from `QueueableBuildingPlanner::firstQueueable`; a `FeatureEncoder` (state + candidate rows from host data, schema-versioned); a JSONL/NPZ writer active only in simulation; a worker command `ai:rl-worker` (ai:sim loop + socket). |
| Success | With the default policy the parity digest equals the pre-seam digest (the seam is behaviour-neutral). 10⁶ samples logged from 16 universes. |
| Benchmark | Overhead of encoding < 5% of session time. |
| Fallback | Seam behind a config switch, off by default in production. |

## Phase 3: behaviour cloning — code done (`rl/`), run on the training machine (HANDOUT-local-agent.md)

| | |
| --- | --- |
| Objective | Prove state → encoding → network → candidate choice. |
| Code | `rl/` Python package (outside the PHP app): dataset loader, scorer model, BC trainer, ONNX export; PHP `OnnxChoicePolicy` (FFI via `ankane/onnxruntime`). |
| Success | ≥ 90% top-1 on multi-candidate decisions, MRR reported per choice point/archetype/phase; **closed-loop 30-day ΔV within ±5% of the teacher** over ≥ 30 twin seeds. PHP inference < 2 ms p95 (G5). |
| Fallback | If closed-loop drifts: DAgger-style relabelling (run the BC policy, label its states with the teacher, retrain). |

## Phase 4: E2, PPO on C1–C3

| | |
| --- | --- |
| Objective | Beat the teacher on the economy. |
| Code | CleanRL-style PPO over the worker socket; per-account trajectories; KL anchor to BC; evaluation harness. |
| Success | Statistically significant ΔV gain at day 30 on the pre-registered seed count, validity checks passed ([evaluation-plan.md](evaluation-plan.md#5-validity-checks-every-reported-win)). |
| Benchmark | learner decisions/s; G3 check. |
| Fallback | Teacher remains the production policy; the seam's default. |

## Phase 5: production shadow mode

| | |
| --- | --- |
| Objective | Run the learned policy in the live cohort without trusting it. |
| Code | Per-account switch `policy = deterministic \| learned \| shadow`; in shadow the model's choice and margin are logged next to the teacher's and not executed. |
| Success | Agreement and disagreement patterns inspected; no latency regression; then A/B on a minority of accounts with automatic fallback when the model output is invalid or the margin is small. |
| Fallback | Switch to deterministic per account or globally. |

## Phase 6+: more choice points, then populations

C4 shipyard → C5/C6 raid and spy targets (report set encoder) → C7 saves → C8 colonies → checkpoint
populations. Each one is a Phase 2–5 loop of its own. Re-check gate G3 when the sample budget grows past
~10⁷ decisions.

## Stop conditions (evidence that RL is not worth continuing)

1. **BC cannot reproduce the teacher in closed loop** (ΔV off by > 10% after DAgger) with ≥ 10⁶ samples:
   the encoding loses information the planners use. Fix the features once; if it fails again, stop.
2. **E2 shows no significant gain over the teacher after 2× the planned budget** (10⁷ decisions), and the
   heuristic "highest payback first" is within noise of both: the economy decision has no headroom, and the
   project should invest in doctrine data instead.
3. **Wins do not survive MySQL replay or the human day-read**: the model is exploiting the environment, not
   playing better.
4. **Throughput after Phase 1 is < 20 learner decisions/s on 16 workers** and the owner will not fund
   Architecture 5: RL beyond the economy is not reachable on this hardware in reasonable time.
5. **Production cannot run the policy under the same settings as training** (e.g. the affect/experience
   weights must stay on for authenticity): the learned decisions would be made in a different game than the
   one they were trained in.

## What not to build yet

- A Rust simulator, Rust formulas, a Rust inference runtime.
- Array/in-memory repositories replacing Eloquent (B-full).
- Ray, distributed training, PufferLib, any cluster.
- Self-play leagues, recurrent or transformer policies, hierarchical managers.
- Learned control of fleet saves, raids or colonisation before the economy result.
- Social/cognitive signals as features.
- A learned `DecisionEngine` errand scorer (its inputs are constants).
