# Risks and failure modes

| Failure mode | Likelihood | Mitigation |
| --- | --- | --- |
| **Simulator diverges from OGameX** | Low with Architecture 1 (same code); **high** with any re-implementation (toy port diverged on float association) | Train on the real code; parity tool (`bench/parity.php`) for every env change; Architecture 5 rules if Rust ever comes. |
| **SQLite dialect differences** | Medium | One found in an hour (`RecordAiStopReasonAction` date lookup); known MySQL-only SQL in `CoordinateDistanceCalculator:159` and `WreckFieldService:129,:1012`. Gate G1 parity on battle-heavy states before trusting SQLite for combat. |
| **Non-determinism** (live battles, expeditions, espionage detection, planet creation) | Certain today | Host seam: container-resolved `Random\Randomizer` seeded per universe + per-mission battle seed. Until then, report the A-vs-A noise floor. |
| Wall-clock leaks into virtual time | Present (`NOW()` in `CoordinateDistanceCalculator`, non-array cache TTLs) | Bind PHP time in SQL; array cache in training. |
| **Report copy leaks through `PlanetServiceFactory` instance cache** | Present (HANDOFF section 6.1) | Scope report copies; required before long-lived training workers. |
| **Training decisions depend on state production uses differently** (affect weight 10, experience weight 20 on by default) | Certain unless handled | Same settings on the learned path in production; or make them features. |
| Reward hacking: hoarding | Medium | Stock earns nothing; raiders exist in the universe. Monitor stock/production ratio. |
| Reward hacking: score inflation via useless objects | Low | ΔV at purchase is 0 by construction. |
| Learns to sit idle (WAIT forever) | Medium early in PPO | BC initialisation + KL anchor; WAIT's features carry the ETA; idle-queue metric in eval. |
| Farms weak deterministic players | Expected once raids are learnable | Evaluate against mixed and aggressive panels; report profit by target type. |
| Exploits simulator bugs | Medium | MySQL replay of top seeds; human read of a day. |
| Overfitting to universe settings / speed | Medium | Train on mixed speeds with speed as feature; evaluate at unseen speeds. |
| Action-space explosion | Low with candidate scoring (K ≤ 32 per choice point) | Planners prefilter; cap K; pooled set features. |
| Stale observations | Low | State built at the choice point after `PlanetService::update()`; the host re-validates every order. |
| Illegal action selection | None for C1–C3 (only legal candidates are offered; host re-validates) | Keep the host as referee; count rejections per policy version. |
| High variance / unstable PPO | Medium | Reward normalisation by own production; time-scaled γ; BC init; small lr; KL early stop. |
| Catastrophic forgetting across curriculum | Medium | Evaluate all owned choice points every iteration; keep old-stage episodes in the mix. |
| Rank-based reward weirdness | Avoided | Rank is evaluation-only. |
| Perfect information | Low | Features restricted to own account, own reports, public highscore. |
| **Behaviour stops looking human** (Gate 3) | Medium | Personas as inputs, humaniser layer untouched (routines, delays, failures), the day-read acceptance check. The learned policy only re-ranks what the planners already consider plausible. |
| Process risk: harness/ledger agents edit the same planner files during the RL work | High in this repo (`AGENTS.md` lanes) | Claim the files through `task.py`; keep the RL hook a thin seam in the planners. |
| Project-level: the owner's diagnosis says "Don't add ... an RL agent ... to the play loop" (`docs/architecture-diagnosis.md` section 5) | Certain conflict | This plan keeps the deterministic managers as the play loop and adds a learned **ranker** at planner choice points behind a switch with the deterministic choice as fallback. The owner has to accept that boundary explicitly. |

## Determinism

What must be seeded, and where:

| Item | Where | Status |
| --- | --- | --- |
| Universe and account generation | `PlanetServiceFactory` (`rand`), `ai:seed-test-universe` | Host change needed |
| Persona | `AiPersonaFactory` | seeded |
| Module decisions | `SeededRandomSource` | seeded |
| Battle rounds | `BattleEngine::$seed` on the live path | Host change: derive from (universe seed, mission id) |
| Moon chance, defence repair, Hamill | `BattleEngine::random()` | follows battle seed |
| Expeditions | `ExpeditionMission` `random_int`, `shuffle` | Host change |
| Counter-espionage, moon destruction, merchant, dark matter, NPC fleets | various | Host change |
| Event ordering | `ai:sim` ordering, arrival ordering by `time_arrival, time_arrival_ms, id` | deterministic |
| Python / NumPy / PyTorch | `torch.manual_seed`, `np.random.default_rng`, `torch.use_deterministic_algorithms(True)` for evaluation | trainer |
| Policy sampling | per-decision RNG derived from (seed, account, decision index) | trainer |

Target property: same universe seed + same policy weights + same sampling seed ⇒ same trajectory. Measured
today: holds for economy-only windows (identical counts and states), fails once battles/expeditions fire.
