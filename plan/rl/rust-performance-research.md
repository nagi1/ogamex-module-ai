# Rust performance research

Every number here was measured in this investigation on a 4-vCPU cloud Xeon (no SMT), unless marked.
Benchmarks and how to rerun them: [bench/README.md](bench/README.md).

## 1. The existing Rust battle engine

| Question | Answer (from `ogamex-next/rust/battle_engine_ffi`, `app/GameMissions/BattleEngine/RustBattleEngine.php`) |
| --- | --- |
| How is it called? | PHP FFI: `char* fight_battle_rounds(const char* input_json)` and `free_battle_result(char*)`. One process-wide binding (`RustBattleEngine::binding()`), shared with `rank_case_similarities` (CBRKit similarity port). |
| Interface | JSON in: attacker/defender fleets, per unit type `{unit_id, amount, attack_power, shield_points, hull_plating, rapidfire{}}`, optional `seed`. JSON out: per-round ship counts, losses, absorbed damage, hits, per-fleet results. PHP computes unit stats with techs (`properties->attack->calculate($player)`), Hamill manoeuvre, loot, debris, moon chance, defence repair and the report. |
| What Rust owns | Only the 6 combat rounds. Everything around a battle is PHP. |
| Determinism | Seeded `StdRng` when `seed` is given; `thread_rng` on the live attack path. |
| Measured cost per battle through PHP FFI (JSON both ways) | 50 LF + 20 HF vs 100 RL + 50 LL: **0.054 ms**; ×10: 0.154 ms; ×100: 1.4 ms; 100k LF + 40k HF vs 200k RL + 100k LL: 33.6 ms. Output JSON ≈ 5 KB regardless of size. |
| PHP engine for comparison | README (2025-01-18, not re-run): 54 s PHP vs 176 ms Rust for 800k vs 120k units (~300×). For raid-sized fights the JSON and PHP setup dominate. |
| FFI call overhead | **54 ns** per call (measured with a no-op through the same FFI mechanism). |
| Reusable in a larger Rust simulator? | The round loop (`lib.rs`) is a self-contained pure function over unit vectors and is reusable as a crate (`crate-type = ["cdylib", "rlib"]` already). The battle *mission* (loot, debris, repair, moon, reports, ACS) is PHP and would have to be ported. |
| Existing Rust state representations | None beyond battle units. No planet, account or queue model exists in Rust. |

## 2. Pure-computation benchmark: economies through a simulated month

`bench/econ.php` and `bench/econrs/` implement the **same** algorithm: N planets, 6 buildings (three mines,
solar, two stores), OGameX production formulas (`30·L·1.1^L`, etc.), energy factor, storage clamp exactly like
`updateResourcesUntil`, greedy "cheapest-ETA" build policy, discrete-event jumps (next completion or next
affordability) for 30 days. 143 events per planet-month.

| Implementation | ns / event | planet-months / s | vs PHP 8.5 JIT |
| --- | --- | --- | --- |
| PHP 8.3, no opcache | 1,340 | 5,200 | 0.5× |
| PHP 8.5, no JIT | 1,184 | 5,900 | 0.56× |
| PHP 8.5, tracing JIT | 663 | 10,600 | 1× |
| Rust, bit-identical to PHP (`powf`, same association) | 138 | 50,600 | **4.8×** |
| Rust, `powi` (faster, **not** bit-identical) | 53 | 133,600 | 12.5× |
| Rust bit-identical, 4 threads | 35 | 200,100 | 19× (machine) |
| Rust called from PHP via FFI (10,000 planets in one call) | 191 ms per batch | 52,400 | FFI overhead invisible |

Python → Rust (PyO3 0.29, maturin) per-call overhead: **47 ns**. Unix-socket JSON round trip with a 4 KB
observation: **88 µs**.

## 3. What the port taught

The first Rust port gave **the same final checksum but 142 events per planet instead of PHP's 143**. Tracing
showed the first divergence at event 84: PHP computes `30 * L * 1.1 ** L` as `(30·L)·1.1^L`, the port computed
`30·(L·1.1^L)`. One ulp of difference made one affordability check land a second earlier, and the trajectory
differed from there. Switching `powi` to `powf` alone made it worse (147 events). Only matching **operation
order and the pow function** restored exact agreement.

So an independent re-implementation of OGameX formulas will not stay identical by default. Even this toy
needs bit-level discipline, and the real host has ~60 objects with formulas as PHP closures, class and officer
bonuses, `ceil`/`floor` at specific points, and the update-cadence semantics described in
[game-simulation-audit.md](game-simulation-audit.md#update-cadence-sensitivity-matters-for-any-second-implementation).

## 4. Where Rust could matter, by component

| Candidate | Pure? | Speed-up available | Share of a session today | Verdict |
| --- | --- | --- | --- | --- |
| Resource production / integration | Yes | 5–12× over PHP JIT | < 1% (the host's `updateResources` is a few multiplications per touch) | **Not worth it.** The arithmetic is not the cost; the Eloquent hydration around it is. |
| Queue completion, fleet timing, fuel | Mostly | similar | < 1% | Not worth it. |
| Combat rounds | Yes | already Rust (≈300× vs PHP) | 3–70 ms per raid candidate in the estimator (50 samples) | Already done. Possible win: run the 50 estimator samples in one FFI call instead of 50, saving 49 JSON round trips (~0.05 ms each). Small. |
| Candidate evaluation (payback, chains) | Yes, but reads host objects | — | 25–30 ms per planner call, mostly service/object construction and queries | The cost is PHP object churn and queries, not math. Fix in PHP first (one snapshot per login). |
| Whole account/universe step | No (Eloquent, missions, messages) | 100–1000× in principle | 100% | Only as a separate simulator (Option D), with the divergence risk above. |

## 5. Answer

- **Hot math is not the bottleneck.** Measured: DB round trips ≈ 40–50% (MySQL) and PHP object/query churn
  the rest. Moving formulas to Rust behind FFI would speed up < 2% of the session.
- Rust pays only if it owns the **whole state transition** of the hot loop, so that no Eloquent model and no SQL
  is touched per event. That is a second implementation of OGameX (Option D), justified only if the PHP
  environment cannot reach the sample budget after the cheap fixes (in-memory SQLite, one snapshot per login,
  no social lane). See the gates in [benchmark-plan.md](benchmark-plan.md#gates).
- If Option D is ever built, the Python binding should be **PyO3 in-process** (47 ns/call, zero-copy NumPy
  buffers), not a socket or gRPC, and the PHP code stays the referee through differential testing
  ([simulator-architecture-options.md](simulator-architecture-options.md#architecture-5-hybrid)).
