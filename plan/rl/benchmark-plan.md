# Benchmark plan and measurements

## 1. Setup used for the measurements below

- Host `ogamex-next` @ `a206575` + module @ `1440811`, copied into a scratch tree (the repositories were not
  modified), PHP 8.5.11 CLI in Docker, MariaDB 11.3.2 (the host's compose image), SQLite (PDO, bundled).
- 20 AI accounts seeded with `ai:seed-test-universe --players=20` (5 archetypes rotating) next to one human
  account. Economy, research and fleet speed 8. `ai:sim --native-cognition` (no sidecars).
- Instrumentation: `DB::listen` counting statements and DB time (`bench/simbench.php`), a per-phase session
  profiler (`bench/sessionprof.php`, `bench/decisionprof.php`), an in-memory runner (`bench/memsim.php`) and a
  parity runner (`bench/parity.php`).
- Machine: 4 vCPU cloud Xeon, 15 GB RAM. Your Xeon Gold 6254 (18 cores, 3.1 GHz base / 4.0 GHz turbo) should
  be similar or faster per core. Re-measure on it (section 4).
- Two bench-only patches were needed: vendoring `symfony/yaml` (the module does not declare it) and the
  `RecordAiStopReasonAction` date lookup for SQLite.

## 2. Measurements already taken

### Baselines B1–B7

| Baseline | What was run | Result |
| --- | --- | --- |
| **B1** production path, sidecars on | not re-run here (sidecars not available in this container) | ×6–×10 real time, 0.65–0.95 s per jump, ~22 FAtiMA calls ≈ 3.5 s per session [HANDOFF.md, Step D] |
| **B2** sidecars off (`--native-cognition`), MySQL | 24 h fresh universe; days 2–3; days 4–10 | Day 1: ×735, 2,406 work items in 90.8 s (**38 ms/item**, 146 statements/item). Days 2–3: ×764, **133 ms/item**, 526 statements/item. Days 4–10: ×313, **171 ms/item**, 452 statements/item, DB 42% of wall. |
| **B3** queues bypassed | `ai:sim` already runs `ProcessAiWork::handle()` synchronously with `queue.default=sync` | Included in B2. No Redis, no Horizon needed. |
| **B4** tracing/logging minimised | statement mix | Writes (incl. `ai_decision_traces` inserts) are absent from the top-25 statement types (< 0.2% of 680k statements in 48 h). Trace volume is not a cost. |
| **B5** virtual clock / event jumping | `ai:sim` | Exists. Average jump 223–379 simulated s; a quiet night is one jump. |
| **B6a** reduced durability (tmpfs, no fsync, no doublewrite) | same 12 h on two MariaDB containers | 43.4 s vs 43.1 s: **no effect**. DB cost is round trips (≈ 0.17 ms × statements), not disk. |
| **B6b** in-memory SQLite, same code | 24 h fresh; 12 h from identical day-3 and day-10 states | Early: ×1,485 (vs ×735), DB 15% of wall. Day 3→3.5: 68 s vs 121 s (**1.8×**). Day 10→10.5: 130 s vs 163–168 s (**1.3×**). |
| **B6b parity** | identical copied state, 12 h | Day 3: **identical** end state (all planets' levels, ships, defence, resources, research, queues, decision histogram). Day 10 (battles, expeditions): MySQL vs MySQL already differ (23 state lines), SQLite vs MySQL differ 12 lines: the game is not deterministic once unseeded RNG fires, so parity cannot be proven there until the RNG seam exists. |
| **B7** Rust components | economy toy, battle FFI, FFI/PyO3/socket overheads | See [rust-performance-research.md](rust-performance-research.md): pure math 4.8× (bit-exact) to 12.5× over PHP JIT, but < 2% of a session. Battle 0.05–1.4 ms per raid-sized fight. FFI 54 ns, PyO3 47 ns, socket 88 µs. |
| Parallel scaling | 1 vs 4 concurrent in-memory universes | Early game: 30.8 s vs 32.8 s wall. Mid-game (`bench/xeon-benchmark.sh`): 4.6 vs 17.7 work items/s. **Linear.** |

### Per-session profile (MySQL, day 3, 20 accounts, per login)

| Phase | ms | Statements |
| --- | --- | --- |
| Host advance (all planets) | 22 | 49 |
| Alliance life (social) | 211 | 820 |
| Decision | 102 | 180 |
| → perception build | 57 | 82 |
| → candidate factory (planners for eligibility) | 14 | 55 |
| → scoring | 0.6 | 2 |
| Intent scheduling (planners + managers) | 73 | 120 |
| → building planner `steps()` | 24 | 27 |
| → unit planner `plan()` | 29 | 37 |
| Executors | 1–14 | 2–38 |

### Policy inference

ONNX Runtime 1.30, 0.29 M params, 1 thread: 34 µs (8 candidates), 69 µs (32), 207 µs (128).

## 3. Metrics to record in every future run

| Metric | How |
| --- | --- |
| Decision latency (per choice point, p50/p95) | timer around the choice-point hook |
| Learner decisions/s, sessions/s, orders/s | counters in the worker |
| Simulated account-days per real second | `(accounts × simulated hours / 24) / wall` |
| CPU% and RSS per worker | `/proc`, `memory_get_peak_usage` (72–78 MB measured) |
| Statements and DB time per work item | `DB::listen` (as in `bench/simbench.php`) |
| Policy inference time | timer around the scorer call |
| Parity (when changing the env) | `bench/parity.php` digest diff |

## 4. Benchmarks still to run (on the Xeon Gold 6254)

1. Rerun B2 and B6b (`bench/README.md`) to calibrate per-core speed.
2. Scaling at 8, 12, 16, 18 concurrent universes (memory bandwidth and turbo drop matter on 18 cores).
3. Session profile at day 30 and day 60 (more planets, raids, the 50-sample estimator).
4. Effect of each cheap PHP fix, one at a time: alliance life off; one `PlayerService`/snapshot per login
   (diagnosis step 1); planners not re-run in the executor; `users`/`ai_profiles` read once per login.
5. PHP → ONNX Runtime via `ankane/onnxruntime` FFI: latency per decision including feature marshalling.

## 5. Gates

Thresholds are derived from the measurements above and the sample budgets in
[training-plan.md](training-plan.md).

| Gate | Condition | Decision |
| --- | --- | --- |
| **G1 SQLite for combat episodes** | After the host RNG seam lands: a battle-heavy snapshot gives identical digests on MySQL and SQLite under the same seed. | **PASSED 4 Oct 2026** (Phase 0): from the day-10 state, `--seed=42`, 12 h with 11 battles, 5 attacks and 11 expeditions: MySQL run A = MySQL run B, in-memory A = in-memory B, and MySQL = in-memory on every planet, research level, battle/espionage/debris/wreck count, mission and decision (`bench/digest.php`). Train on SQLite. |
| **G2 Array repositories (Option B-full)** | Only if, after B-lite, DB time is > 30% of wall time. Measured now: 15%. | Do not build. |
| **G3 Rust simulator (Architecture 5)** | Only if, **after** the cheap PHP fixes, 16 workers on the 6254 produce fewer than *(sample budget of the next experiment) ÷ (72 h)* learner decisions/s. With E2's budget of 5×10⁶ decisions that is ≈ 20 decisions/s. Mid-game measured now (days 4–10): 1.8 sessions/s per process on MySQL, ≈ 2.4 on SQLite, × ≈ 2.8 economy choice points per session (1.8 planets + the lab) ≈ **6–7 decisions/s per process if every account is a learner**, ≈ 100/s for 16 processes, ≈ 25/s if only a quarter of the accounts learn. Projected pass, narrowly for the mixed population; the cheap PHP fixes are the margin. | G3 pass (PHP fast enough): no Rust. G3 fail by > 10× on the experiment that matters: start Architecture 5 for the economy only, with the differential tooling first. |
| **G4 Any single function to Rust/FFI** | Only if a profile shows it takes > 10% of session wall time **and** it is pure (no Eloquent inside). | None qualifies today (largest pure function < 2%). |
| **G5 Inference path** | PHP→ONNX via FFI must be < 2 ms p95 per decision including feature building. | Else serve the policy from Python over the socket (measured 88 µs round trip) or batch per login. |
| **G6 Simulation throughput regression** | Any env change must keep simulated account-days/s within 10% of the last recorded value, or say why. | Prevents silent slowdowns. |
