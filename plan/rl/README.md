# Reinforcement learning for OGameX strategy: research package

Written 4 October 2026 from `nagi1/ogamex-module-ai@1440811` and `nagi1/ogamex-next@a206575`, after running
the real host + module on PHP 8.5 / MariaDB 11.3 / SQLite in Docker. **No production code was changed.**
Numbers marked as measured come from [benchmark-plan.md](benchmark-plan.md); scripts are in [bench/](bench/).

| Document | Contents |
| --- | --- |
| [current-system-audit.md](current-system-audit.md) | What the decision pipeline really is, executable intents, host services, cognition coupling |
| [game-simulation-audit.md](game-simulation-audit.md) | Time, laziness, virtual clock, DB/queue coupling map, MySQL vs SQLite, determinism |
| [state-and-action-space.md](state-and-action-space.md) | Choice points, candidate scoring, features, model size |
| [reward-design.md](reward-design.md) | ΔV reward, horizon, episodes, hacking guards |
| [ml-stack-research.md](ml-stack-research.md) | Library status, algorithm fit, inference latency |
| [rust-performance-research.md](rust-performance-research.md) | Battle engine, Rust vs PHP benchmark, FFI/PyO3 costs, where Rust pays |
| [simulator-architecture-options.md](simulator-architecture-options.md) | Options A–F, Architectures 1–5 |
| [benchmark-plan.md](benchmark-plan.md) | Baselines B1–B7 measured, what is left to measure, gates |
| [training-plan.md](training-plan.md) | step/reset, logging, BC, first RL experiment, curriculum, population, time |
| [evaluation-plan.md](evaluation-plan.md) | Twin-universe tournament, seed counts, validity checks |
| [risks.md](risks.md) | Failure modes, mitigations, determinism checklist |
| [implementation-roadmap.md](implementation-roadmap.md) | Phases, success criteria, fallbacks, stop conditions, what not to build |

## Recommended architecture

```
 ┌──────────────────────────── per universe: one PHP 8.5 process (×16 on the Xeon 6254) ───────────────────────────┐
 │  SQLite :memory:  ◀── snapshot load (reset)                                                                      │
 │  ai:sim virtual clock (SimulatedTime): jump to next due session / fleet arrival / maintenance                     │
 │  OGameX host services — authoritative rules, queues, missions, Rust battle rounds (FFI)                          │
 │  AI module: routines, managers, planners — deterministic accounts unchanged                                      │
 │      learner accounts: planner builds legal candidate list ─▶ ChoicePolicy ─▶ chosen candidate ─▶ host validates  │
 │                                                     │ (state, candidates, teacher idx)                           │
 └─────────────────────────────────────────────────────┼─────────────────────────────────────────────────────────────┘
                                                       │ unix socket / rollout files
 ┌─────────────────────────────────────────────────────▼─────────────────────────────────────────────────────────────┐
 │  Python: PyTorch 2.14 — candidate scorer (0.3–1 M params) — BC, then CleanRL-style PPO — evaluation tournament    │
 │  RTX 3090 for updates · export ONNX per policy version                                                            │
 └─────────────────────────────────────────────────────┬─────────────────────────────────────────────────────────────┘
                                                       ▼
 Production: Laravel ─ PerceptionSnapshot/planner candidates ─ OnnxChoicePolicy (ONNX Runtime via PHP FFI, CPU, ~70 µs)
             ─ host validates ─ deterministic choice is default, fallback and A/B control
```

## Why (10 points)

1. The game is already simulated fast with the real code: `ai:sim` is a discrete-event loop over a virtual
   clock and runs at ×313 (mid-game) to ×1,485 (early, SQLite) real time per 20-account universe [measured].
2. In-memory SQLite made the real code 1.3–2.0× faster, with an **identical** 12-hour end state compared
   with MySQL from the same snapshot [measured]. No second implementation is needed for that.
3. DB durability is irrelevant (tmpfs/no fsync: 0% change); the cost is query count and PHP object churn
   [measured]. The cheap fixes are in PHP (one snapshot per login, no triple planning), not in Rust.
4. Pure math (production, timing) is < 2% of a session; Rust is 4.8× faster on it bit-exact [measured], which
   buys < 2% end to end. Rust only pays as a whole-simulator replacement, with drift risk that a toy port
   already demonstrated.
5. The real decisions sit in the planners, which already produce host-derived candidate lists; scoring
   candidates keeps Gate 1 (no hardcoded objects) and makes the deterministic choice a free imitation label and
   fallback.
6. The candidate scorer is small (0.3 M params) and costs 69 µs on one CPU thread for 32 candidates
   [measured], so production needs no GPU and no Python.
7. The environment produces < 10³ samples/s, so SB3/PufferLib speed is irrelevant; a small custom PPO that
   fits a variable action list and event-driven multi-agent stepping is simpler than bending SB3.
8. ΔV (invested + held) rewards production, counts losses and loot automatically, and gives zero for merely
   buying something, so no behaviour is hand-rewarded.
9. Twin-universe evaluation with seeded RNG gives paired, low-variance comparisons; the host needs one RNG
   seam for that (determinism holds for the economy today and fails once battles/expeditions fire [measured]).
10. Everything stays behind a switch whose default is today's deterministic AI.

## Confirmed facts

- `PerceptionSnapshot`, `CandidateActionFactory`, `UtilityScorer`, `DecisionEngine`, `DecisionTrace`,
  `ArchetypePolicy`, `SessionDecisionService`, `QueueAi*` all exist; personas are Miner, Raider, Turtle,
  Fleeter, Hybrid (YAML also has `trader`/`casual` blocks with no registered policy). [code]
- The engine chooses one category per login; managers then emit many orders regardless
  (`ScheduleAiIntentAction::runManagers`). Candidate features are per-type constants. [code]
- All 20 work kinds execute through host services; none is record-only. [code]
- Host production, building, research and shipyard progress lazily from timestamps; fleet arrivals can be
  processed by the overdue fallback. `SimulatedTime` moves every Carbon flavour. [code]
- Affect (weight 10) and experience (weight 20) influence gameplay decisions by default. [code]
- MySQL durability settings do not change sim speed; SQLite `:memory:` does (1.3–2.0×). [measured]
- SQLite runs all 140 migrations; one module bug (date lookup) and two host raw-SQL sites are MySQL-specific. [measured, code]
- Two runs from the same mid-game state diverge on MySQL itself (unseeded battles/expeditions). [measured]
- Independent universe processes scale linearly (4 on 4 cores). [measured]
- FFI 54 ns/call, PyO3 47 ns/call, unix-socket JSON 88 µs, ONNX scorer 34–207 µs. [measured]
- A faithful Rust port of six building formulas diverged from PHP until float operation order matched. [measured]
- The module needs `symfony/yaml` but does not declare it; `--no-dev` installs break every session. [measured]
- SB3 2.9.0 / sb3-contrib 2.9.0 (Jun 2026), Gymnasium 1.3.0, PyTorch 2.14, ONNX Runtime 1.30, PyO3 0.29;
  TorchScript deprecated. [docs]

## Assumptions still requiring proof

- SQLite parity holds for battles, expeditions and wreck fields once the RNG is seeded (gate G1).
- The cheap PHP fixes give ≥ 2× (Phase 1).
- The Xeon Gold 6254 is at least as fast per core as the cloud VM used here, and scales to 16 workers.
- A ~100-feature pooled state is enough for BC to match the teacher in closed loop.
- PPO initialised from BC improves on the teacher within 5×10⁶ learner decisions.
- PHP → ONNX Runtime via FFI stays under 2 ms per decision including feature building.
- The economy decision has headroom over the teacher at all (stop condition 2 tests it).

## Recommended first experiment

Phases 0–3: environment hygiene + choice-point seam + behaviour cloning on **C1/C2/C3 (which building, which
technology, or wait)**, all other decisions deterministic, 16 universes × 24 accounts, SQLite `:memory:`.
Success = closed-loop 30-day ΔV within ±5% of the teacher over ≥ 30 twin seeds. Then E2 (PPO on the same
choice points, 6 learners per universe, 5×10⁶ decisions).

## Benchmark gates (detail in [benchmark-plan.md](benchmark-plan.md#5-gates))

- G1: no combat curricula on SQLite until MySQL-vs-SQLite digests match on battle-heavy seeds.
- G2: no in-memory repository layer unless DB time > 30% of wall after SQLite (15% measured).
- G3: no Rust simulator unless, after the PHP fixes, 16 workers give < (next experiment's budget ÷ 72 h)
  learner decisions/s (≈ 20/s for E2).
- G4: no function moves to Rust unless it is pure and > 10% of session time (none is).
- G5: PHP inference path must be < 2 ms p95 per decision.

## Answers to the 30 questions

1. **Realistic?** Yes for an economy policy on this hardware; the environment, executors and baseline exist.
   Raids/saves/self-play are realistic only if the PHP fixes or a later simulator lift throughput.
2. **Best first architecture:** Architecture 1 on the real PHP code, `ai:sim` virtual time, SQLite
   `:memory:`, one universe per process, Python trainer over a socket.
3. **Python / PyTorch / SB3:** Python owns training and evaluation only (PyTorch networks, BC, custom PPO, the
   tournament). SB3 MaskablePPO is an optional sanity baseline, not the core.
4. **Fixed classification or candidate scoring?** Candidate scoring at planner choice points.
5. **One `step()`:** one learner choice point (a free build queue on a planet, or a free lab), after the
   universe has advanced in virtual time to it.
6. **`reset()`:** load a seeded snapshot (fresh accounts or day-N) into a new SQLite `:memory:` DB, assign
   learner/deterministic roles and personas, set the clock.
7. **Virtual time:** keep `SimulatedTime` and the `ai:sim` jump loop; sessions keep human routines.
8. **Refactor:** module: declare `symfony/yaml`, date lookup, choice-point seam in
   `QueueableBuildingPlanner::firstQueueable`, feature encoder, one snapshot per login. Host: RNG seam,
   portable SQL in `CoordinateDistanceCalculator` and `WreckFieldService`, report-copy scoping in
   `PlanetServiceFactory`.
9. **Authoritative:** the host for every rule, queue, mission, battle and validation; the module planners
   for candidate generation; the deterministic AI for every non-learned choice.
10. **Disable in training:** sidecars, language lane, conversation cycle, alliance life, campaign
    consultation; affect and experience weights set to 0 (and the same on the learned path in production).
11. **Avoid MySQL?** Probably yes: SQLite `:memory:` with identical economy results; combat parity pending G1.
12. **If not:** the whole DB moves to memory with SQLite; replacing Eloquent with arrays is not needed (G2).
13. **Where Rust helps:** not in formulas (< 2%). Only a full simulator could give large gains (estimated
    10³× per event) and only if G3 fails.
14. **Rust integration:** PHP FFI for anything called from PHP (already used); PyO3 if a Rust simulator is
    driven from Python. No gRPC, no sidecar.
15. **Whole simulator or hot computation?** Neither now. If ever, the whole economy transition, under
    Architecture 5.
16. **Prevent divergence:** export tables from PHP (never hand-port closures), golden fixtures, seeded
    differential replay with first-divergence reports, property tests, final evaluation on PHP only.
17. **Throughput on your hardware:** estimate ≈ 100 decisions/s if every account learns (≈ 25/s at 25%
    learners) mid-game today on 16 workers, 2–3× after the PHP fixes; ≈ 30–80 simulated account-days per
    second. To confirm on the 6254.
18. **Parallel universes:** 16.
19. **Players per universe:** 24 (20–32).
20. **Learners vs deterministic:** 6 learners + 18 deterministic in E2; up to 50% later.
21. **Decisions needed:** BC ≈ 10⁶ samples; economy PPO 10⁶–10⁷ (assumption); interactive decisions 10⁷–10⁸.
22. **First training time:** BC data ≈ 3 h + training minutes; E2 ≈ 1–3 days of wall time.
23. **Model size:** 0.3–1 M parameters, 1.2–4.5 MB.
24. **CPU latency:** 34–207 µs measured in ONNX Runtime (69 µs at 32 candidates); < 2 ms expected from PHP.
25. **Starting reward:** Δ(invested + held + in-flight value) per decision interval, divided by own hourly
    production, time-scaled γ; relative-rank term off at first.
26. **Better or not:** twin-universe paired comparison on pre-registered seed counts, MySQL replay of top
    seeds, human day-read.
27. **Reuse the deterministic AI:** as the world (opponents), the teacher (labels), the fallback (default
    `ChoicePolicy`), and the baseline (twin control).
28. **Imitation first?** Yes. It proves the plumbing, and its closed-loop test is the cheapest stop condition.
29. **First proof of concept:** Phases 0–3 above.
30. **Do not build yet:** Rust simulator or formulas, array repositories, distributed/Ray, self-play, recurrent
    or transformer models, learned saves/raids/colonies, social features, a learned errand scorer.

## Mechanics vs strategy

Mechanics are taken from host code only: production and storage (`BuildingObjects`, `PlanetService`),
costs and requirements (`ObjectService`), score (`HighscoreService`), fleet timing (`FleetMissionService`),
combat (`RustBattleEngine` + `BattleEngine`), classes Collector/General/Discoverer (`CharacterClassService`),
universe speeds (`SettingsService::economySpeed/fleetSpeed/researchSpeed`). Strategy (opening orders,
research paths, fleet templates) lives in the module's doctrine YAML and `plan/research/ogame`; it shapes the
teacher and the personas, never the simulator.

## A boundary the owner must accept

`docs/architecture-diagnosis.md` section 5 says "Don't add GOAP, MCTS, an RL agent or an LLM to the play loop."
This plan does not replace the managers or the play loop; it adds a learned ranker at planner choice points,
behind a switch, with the deterministic choice as default and fallback. If that boundary is not acceptable,
stop at Phase 1 (the speed fixes are useful either way).
