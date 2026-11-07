# Simulator architecture options

## 1. Options A–F (how to run the game fast)

| Option | What | Status / evidence | Verdict |
| --- | --- | --- | --- |
| **A. Laravel/PHP simulator, real services, virtual time, queues bypassed, sidecars off** | Exactly `ai:sim --native-cognition` | **Exists.** Measured ×313 (mid-game) to ×1,005 (early) real time per 20-account universe on MySQL. Sidecars on: ×6–×10 [handoff]. | Baseline. Keep. |
| **B. PHP rules with in-memory persistence** | B-full: abstract repositories, replace Eloquent with arrays. B-lite: same code, SQLite `:memory:` connection, one universe per process. | **B-lite measured**: ×1,150–1,485 early, ×332 mid-game; 1.3–2.0× faster than MySQL; identical end state over 12 h of economy play; linear scaling over processes. B-full: not attempted. 26 sites build `PlayerService` from the factory, 94 Eloquent/DB calls in `app/Domain/Decision` alone, the host services are Eloquent throughout. | **B-lite: adopt.** B-full: reject. The remaining cost after B-lite is PHP object churn, which an array repository would not remove. |
| **C. Rust for selected pure calculations** | Production, queues, timing in Rust behind FFI | Pure math is < 1–2% of a session [measured]; the FFI call costs 54 ns. | **Reject for now.** Nothing to gain until the PHP session is 50× cheaper. |
| **D. Full Rust training simulator** | Re-implement the state transition (planets, queues, missions, combat, espionage, expeditions) in Rust | Toy port needed bit-level discipline to match 6 buildings (float association, `pow`). Real host: ~60 objects with PHP-closure formulas, class/officer bonuses, update-cadence semantics, 11 mission types. | **Not now.** Justified only if gate G3 fails (PHP env too slow for the sample budget). Then build it as Architecture 5, never as an independent game. |
| **E. Rust via extension/FFI/sidecar** | PHP FFI (54 ns/call), native extension, gRPC, socket (88 µs round trip), subprocess | FFI already in production (battle engine). | If Rust is used from PHP: **FFI** with one batched call per operation. No sidecar, no gRPC. |
| **F. Rust env + Python learner** | PyO3/maturin, NumPy buffers, Gymnasium API | PyO3 call measured at 47 ns. | Only together with D. If D happens, this is the binding. |

## 2. Architectures 1–5

### Architecture 1: Laravel simulation ↔ local protocol ↔ Python

```
PHP worker per universe (ai:sim loop, SQLite :memory:)  ──unix socket, JSON or msgpack──▶  Python trainer (PyTorch)
   at each learner choice point: send (state, candidates, teacher index), receive choice
```

| Criterion | Assessment |
| --- | --- |
| Correctness risk | **Lowest.** The real host and module code. |
| Development complexity | Low–medium: a choice-point hook in the planners, a socket client in PHP, a VecEnv-like server in Python. |
| Throughput | 1–5 learner decisions/s per process mid-game today; × 16 processes; 2–3× more after the PHP fixes in the roadmap. Socket cost 88 µs ≪ 100+ ms session. |
| Maintainability | High: one implementation of the game. |
| Mod compatibility | Full: objects come from the host. |
| Reuse of OGameX rules | 100%. |
| Debugging | Real traces, real `ai:explain-decision`, real DB state you can dump and inspect. |
| Deployment | Same PHP image, plus Python on the training box. |

Variant 1b: the PHP worker runs the policy itself through ONNX Runtime via FFI and writes rollouts to files;
Python trains and publishes a new `.onnx` per PPO iteration. Removes the per-decision socket wait, at the cost
of slightly stale policies within an iteration (handled by synchronising per iteration). Choose 1 or 1b in the
PoC by measurement; 1 is simpler to debug.

### Architecture 2: Laravel state + Rust hot path + Python

Measured hot-path share is < 2%, so this architecture buys nothing today. **Reject.**

### Architecture 3: Rust simulator ↔ PyO3 ↔ Python

| Criterion | Assessment |
| --- | --- |
| Correctness risk | **High**: a second game. Float- and cadence-level drift demonstrated on a toy. |
| Complexity | High: months for the economy + missions + combat wrapper + espionage + expeditions + colonisation, then the planners (or a re-implementation of the deterministic opponents too!). |
| Throughput | 10³–10⁵× the PHP env (estimate from the toy: 138 ns/event). |
| Maintainability | Every host rule change has to be ported. |
| Mod compatibility | Lost unless the Rust side reads exported object tables, and closures cannot be exported. |
| Debugging | Two codebases to suspect. |

The deterministic opponents are themselves ~9,000 lines of PHP planners. A Rust simulator must either port
them or play against something else, which removes the main baseline.

### Architecture 4: Rust simulator + Rust inference for production

Production does not need it: inference is 69 µs in ONNX Runtime [measured] and the game itself is PHP.
**Reject.**

### Architecture 5: hybrid, OGameX canonical + optimised simulator + continuous differential testing

```
OGameX (PHP, canonical) ──fixtures: (state, action, Δt) → next state──▶ Rust simulator (optimised)
        ▲                                                                      │
        └─────── nightly differential run: same seed, same actions, compare ◀──┘
```

The only acceptable shape for a Rust simulator, if one is ever needed:

1. **Shared specification by export, not by hand**: dump `ObjectService` prices, requirements, rapidfire,
   and **evaluate the PHP production closures** at every level 0–80 for every temperature/position/bonus
   case as lookup tables. Rust reads tables; it never re-derives a formula.
2. **Golden fixtures**: a PHP command that snapshots `(state_t, action, t+Δ)` from `ai:sim` runs, both the
   input and the PHP output, as JSON. Rust must reproduce output to the tolerance defined per field (exact
   for integers and levels, ≤ 1e-9 relative for floats).
3. **Differential replay**: the same seeded episode (requires the host RNG seam) played by PHP and by Rust,
   compared every simulated hour; first divergence printed with the event that caused it (the method that
   found the `pow` drift in this investigation).
4. **Property tests** (`proptest`): conservation (resources only appear through production, loot, expedition,
   debris), monotonic time, queue invariants.
5. **Policy evaluation on PHP only**: any policy trained in Rust is evaluated on the PHP environment before its
   numbers count.

## 3. Recommendation

**Architecture 1 on Option A + B-lite**: the real code, virtual time, SQLite `:memory:`, one universe per
process, sidecars and social lanes off, Python trainer over a local socket. Revisit Architecture 5 only
through gate G3.
