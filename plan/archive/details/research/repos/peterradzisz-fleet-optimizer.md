I have gathered exhaustive detail from the README, all Rust sources, all Python core/optimizer/API modules, and the web UI. Here is the complete document.

---

## Overview

`peterradzisz/ogame-fleet-optimizer` is a **local, single-user web calculator** (MIT, 0 stars, 1 contributor, no releases) that recommends an optimal counter-fleet for an OGame battle. It is not a bot: it has no game connection, no scheduler, and no account handling. The user pastes an enemy fleet/defense report, sets techs and a budget, and gets a recommended composition with win probability, losses, debris, net profit, and recycler count.

- Stack: Rust combat core (PyO3 + maturin) + Python (FastAPI + Pydantic v2 + Jinja2) + vanilla JS.
- Two combat resolvers: a **per-unit Rust Monte-Carlo** core for small fleets, and an **analytical O(types²) resolver** (Python `fast_combat.py`, mirrored in Rust `analytical.rs`) for large fleets, with a bit-identical CPython RNG port to keep them in parity.
- Three-phase optimizer: greedy → multi-start GA → prune/swap refinement, plus sensitivity analysis and up to 2 "Option B/C" alternative fleets.
- Claims "corrected combat engine (validated against real battle reports)"; ship stats verified against `ogamespec/ogame-opensource` v0.84 `unit.php`/`prod.php` and community wikis, with several plan-spec values documented as wrong and corrected.
- **Gate flags up front:** the entire object universe (ids, prices, requirements, stats, rapidfire) is **hardcoded as source of truth** in 4+ places (gate-1 violation if adapted as-is); the analytical resolver is heavily over-engineered for its purpose (gate-2); there is **no anti-detection or authenticity layer at all** (it is an offline calculator, not a player agent — gate-3 is out of scope by design).

---

## Architecture & entry points

**Rust crate `ogame_combat`** (`Cargo.toml`: pyo3 0.21, rand 0.8, rand_xoshiro 0.6, serde, serde_json, rayon 1.10; release: `opt-level=3`, `lto=thin`, `codegen-units=1`):

- `src/lib.rs` — PyO3 module `_ogame_combat`. Public pyfunctions: `simulate_combat_py`, `simulate_batch_py`, `evaluate_population_py`, `simulate_analytical_combat_py`, `simulate_analytical_batch_py`, `_pyrng_probe_random`, `_pyrng_probe_gauss`. Calls `analytical::verify_tables_on_startup()` at import.
- `src/ships.rs` — `ShipType` (16 variants), `DefenseType` (8), `ShipStats`, `ship_stats()`, `defense_stats()`.
- `src/rapidfire.rs` — `UnitType` enum, `rapidfire(shooter, target) -> Option<u32>`.
- `src/combat.rs` — per-unit Monte-Carlo resolver: `simulate_combat`, `ForceState`, `fire_phase`, `apply_damage`.
- `src/analytical.rs` + `src/analytical_tables.rs` — Rust port of the Python analytical resolver; tables parsed at compile time from `tests/fixtures/ogame_tables.json` via `include_str!`.
- `src/pyrng.rs` — exact port of CPython `random.Random` (MT19937, `init_by_array`, `genrand_res53`, cached Box-Muller `gauss`) + libm FFI for `cos/sin/log/exp/erfc` for bit-parity.

**Python package `ogame_optimizer`** (`pyproject.toml`: `python-source = "python"`, `module-name = "ogame_optimizer._ogame_combat"`, requires-python >=3.9, deps: fastapi, uvicorn, jinja2, pydantic>=2, numpy, httpx):

- `core/combat.py` — snake_case↔PascalCase translation, wraps Rust; dispatches to `fast_combat` when `should_use_fast`.
- `core/fast_combat.py` — analytical resolver (pure-Python, with `SHIP_STATS`, `DEFENSE_STATS`, `RAPIDFIRE`, `SHIP_COSTS_MCD`, `DEFENSE_COSTS_MCD`, `calculate_debris`).
- `core/fleet.py` — cost tables, budget math, fuel/speed penalty.
- `core/tech.py` — `TechLevels` dataclass.
- `optimizer/greedy.py`, `genetic.py`, `progressive_seeds.py`, `orchestration.py`, `objective.py`, `statistics.py`.
- `api/app.py`, `api/routes.py`, `api/schemas.py`.
- `web/templates/index.html` + `web/static/app.js`.
- `logging_config.py` — rotating file log (5 MB × 5) + stderr.
- `tools/gen_ogame_tables.py` (generates the JSON fixture), `python_tests/` (142 tests), Rust `#[cfg(test)]` (52 tests).

**Entry point:** `uvicorn ogame_optimizer.api.app:app` → `create_app()` (FastAPI v0.4.0, CORS `allow_origins=["*"]`), routes in `routes.py` (`/`, `/api/ships`, `/api/defenses`, `/api/version`, `/api/combat`, `/api/optimize`, `/healthz`).

---

## Scheduling & loop model

There is **no scheduler, no background worker, no queue, no cron**. It is a synchronous request/response service:

- `POST /api/optimize` runs the whole pipeline inline, bounded by `ga_time_budget` (default 5 s, UI clamps 1–60 s).
- The only "loop" is the GA generation loop in `genetic.py::genetic_optimize`, bounded by an **absolute wall-clock deadline** (`deadline = t0 + config.time_budget_seconds`), checked both between generations and mid-evaluation (per-fleet batch in `_evaluate_population_with_crn`, which pads un-evaluated fleets with `-inf` fitness).
- Determinism comes from seeds, not time: `CRNManager(base_seed)` gives every individual in generation `gen` the same seed `base_seed + gen`; per-fleet seed streams are `base_seed + i*7919` (fast path) or `base_seed + i*n_sims` (Rust path).
- The greedy phase has its own 1 s budget and a 50-iteration no-improvement cap; sensitivity analysis probes per-sim cost and adaptively shrinks `n_sims` to fit `max_total_seconds=20.0`.
- Concurrency is only for evaluation: `ThreadPoolExecutor` (capped at 8 workers) over `simulate_batch` calls, which release the GIL (Rust) or run under rayon.

---

## Decision engine & algorithms

**Phase A — Greedy (`greedy.py`):**
- `phase_a1_counter_ratio_init`: maps enemy ship → hardcoded counter (`COUNTER_MAP`, e.g. `light_fighter→cruiser`, `deathstar→destroyer`); if a Small/Large Shield Dome is present, reserves **20% of budget** for `HIGH_DAMAGE` ships bought cheapest-first.
- `phase_a2_budget_fill`: trims lowest damage-per-cost if over budget; tops up with the highest damage-per-cost ship if under.
- `phase_a3_local_search`: hill-climbing over augment/trim/swap moves, `max_no_improvement=50`.

**Progressive seeding (`progressive_seeds.py`):** Phase 0 tests each of 9 `COMBAT_SHIPS` (LF, HF, Cruiser, BS, BC, Bomber, Destroyer, Deathstar, Reaper) as a pure fleet at full budget, keeps top 4; Phase 1 tests 50/50 two-type combos of the top 4, keeps top 3; returns top-3 pairs + best single (up to 4 seeds) plus the greedy seed.

**Phase B — Multi-start GA (`genetic.py`):** explore each seed for `min(15% of budget, 2s)` (pop 20, 20 sims/eval, mut 0.30), then refine+polish the best (pop 30, 50→100 sims/eval, mut 0.15→0.08). Operators: tournament selection (k=3), uniform crossover, Gaussian creep (`sigma = step_fraction * (hi-lo)`) with macro-jumps, budget-neutral `reallocate` (moves 15–60% of affordable cost between two types), elitism 2. Fitness `= -(loss*loss_scale + penalty)/budget`; winners (win ≥ 0.95) get a flat `+1000` tier bonus; losers are graded (in profit mode, `+ enemy_loss*debris_pct/budget`) with an anti-camping penalty for spending < 90% of budget.

**Phase C — Prune & swap (`orchestration.py`):** sensitivity analysis tags every ship; `_greedy_swap_refine` validates individual swaps (promise ≥ 15%, accept if loss improves > 5% without lowering win rate, 15 s cap).

**Sensitivity analysis (`_sensitivity_analysis`):** removes each ship type, redistributes its budget to the highest-value remaining type, re-simulates; tags by `impact_pct`.

**Alternatives (`_generate_alternatives`):** up to 2 fleets passing a 0.10 cost-share total-variation diversity gate and a 1.10× loss quality gate, sourced from harvested per-seed bests then forced GA passes that exclude the primary's dominant type.

**Objective (`objective.py`/`statistics.py`):** `ObjectiveMode.ATTACK|DEFEND`; `compute_fitness` enforces the 0.95 win/survive hard constraint (`-inf` otherwise).

---

## Data model & persistence

**No server-side database or persistence of any kind.** State lives only in:

- Hardcoded tables (source of truth, duplicated): `ships.rs` `ship_stats`/`defense_stats`, `fleet.py` `SHIPS_COST`/`DEFENSES_COST`/`SHIP_BASE_ATK`/`SHIP_DRIVE_DATA`, `fast_combat.py` `SHIP_STATS`/`DEFENSE_STATS`/`RAPIDFIRE`/`SHIP_COSTS_MCD`/`DEFENSE_COSTS_MCD`, `analytical_tables.rs` (from `ogame_tables.json`), and JS `SHIP_META`/`DEFENSE_COST`. Drift tests (`test_ships_cost_matches_rust_source_of_truth`, `test_ogame_tables_drift.py`, `test_analytical_python_rust_parity.py`) enforce agreement.
- Browser `localStorage` only: last **6** results (`HISTORY_KEY = "ogame_optimizer_history"`, `HISTORY_MAX = 6`) and fleet presets (`ogame_optimizer_presets_my_fleet` / `..._enemy_fleet`), with TXT/XML export/import.
- Filesystem: rotating log `logs/ogame-optimizer.log` (5 MB × 5).
- Combat state is per-unit `Vec`s in `ForceState` (Rust) or `UnitState` structs with `dmg_bins` histograms (analytical); results are plain dicts / Pydantic models (`OptimizeResponse`, `CombatResponse`).

---

## Config surface

All configuration is per-request (`OptimizeRequest` in `api/schemas.py`), not files/env:

| Field | Default | Constraint |
|---|---|---|
| `budget_multiplier` | 1.0 | ≥0, 0.1-step grid (0.0 = simulate-only, requires `base_fleet`) |
| `mode` | attack | `attack`/`defend` |
| `attacker_tech`/`defender_tech` (weapon/shield/armor) | 0,0,0 | ≥0 int |
| `drive_techs` (combustion/impulse/hyperspace) | 16/14/12 | 0–30 |
| `debris_pct` | 0.30 (API) / 0.80 (UI select) | 0–1 |
| `deuterium_in_debris` | false (API) / true (UI) | bool |
| `optimization_target` | `maximize_profit` | `minimize_loss`/`maximize_profit` |
| `min_gain_pct` | 0 | 0–100 |
| `hyperspace_tech` | 11 | int (0–30 UI) |
| `collector_class` | false | bool |
| `resource_weights` M/C/D | 1,1,1 | ≥0 |
| `preference_beta` | 0.05 | 0–1 |
| `fuel_speed_penalty_pct` | 0 | 0–10 |
| `ga_time_budget` | 5.0 | 1–60 (UI) |
| `final_sims` | 1000 (API) / 500 (UI) | 100–10000 |
| `seed` | 42 | int |
| `exclude_ships` | — | list |
| `seed_fleet` / `base_fleet` | — | known keys, non-negative |
| `include_alternatives` | true | bool |
| `validate_scale` | true | bool |

---

## Edge cases & failure handling

- Input validation at trust boundaries: unknown ship/defense keys, negative counts, and `bool` counts rejected (`fleet.py::_validate_counts`, `tech.py::_validate_tech_value`, Pydantic validators); `budget < cheapest ship` → `ValueError`; empty enemy → `ValueError`; `0.0x` without `base_fleet` → `ValueError`.
- Combat edge cases: shield-bounce < 1%; 0-shield units take hull damage; explosion threshold at 70% hull; simultaneous death → Draw; both alive after 6 rounds → Draw (never "more ships wins"); stalemate (no damage either side) → Draw; `attack == 0` → no damage; EP cannot attack.
- Numerical guards: `_poisson_ge` clamps λ > 500 (e^-λ underflow); `_sround = floor(x + U)` unbiased rounding (avoids +1567% floor bias); `surv_tail` clamped at erfc tail limits (±8.3σ); division-by-zero guards throughout; `fleet_value==0` → `inf` fitness; inf/NaN candidates skipped in alternatives.
- Error handling: routes catch `ValueError` → HTTP 400, all else → HTTP 500 with traceback; alternatives generation failure is best-effort (never kills the optimization); `_rust_verify` is logging-only; `simulate_batch`'s debris computation falls back to zeros on any exception.
- Budget safety: downscale-only renormalization; post-GA proportional scale-down (additions only in base_fleet mode, base preserved as sunk cost).
- Determinism: Xoshiro256PlusPlus (per-unit core) and PyRandom MT19937 (analytical) seeded per sim; CRN shared per generation.

---

## Anti-detection & authenticity

**none.**

There is no anti-detection, rate-limiting, human-latency modeling, uptime shaping, session obfuscation, proxy rotation, or any player-authenticity machinery. The project never connects to OGame — it is explicitly "paste only" (README Limitations: "No OGame API integration (paste only)"). It is a planning/analysis tool, not an actor, so it neither attempts nor needs disguise. The only behavioral "authenticity" artifact is the fuel/speed penalty and resource-preference knobs that nudge recommendations toward what an experienced player values (BC preference, metal-heavy fleets), which is a design choice, not camouflage.

---

## Discrete mechanisms

- M01 — 6-round combat cap — combat runs at most 6 rounds; both sides alive after round 6 = Draw (`src/combat.rs` `for round in 0..6`; `fast_combat.py` `for rnd in range(6)`).
- M02 — Per-unit state — each ship has its own `shield`/`hull`/`max_shield`/`max_hull` vector slot, not pooled per type (`src/combat.rs::ForceState::from_fleet`).
- M03 — Shield regen every round — `shield = max_shield` for all units at round start (`ForceState::regen`; `_regen_shields`).
- M04 — Tech multipliers — `attack = base_attack * (10 + weapon_tech) / 10`; `shield = base_shield * (10 + shield_tech) / 10`; `hull = base_armor * (10 + armor_tech) / 10` (`src/combat.rs::ForceState::from_fleet`, `fire_phase`; `fast_combat.py::_make_side`).
- M05 — Shield bounce < 1% — `bounce_threshold = (max_shield + 99) / 100`; a shot below it deals zero damage (`src/combat.rs::apply_damage`; `per_shot < unit_shield * 0.01` in `_fire`).
- M06 — Zero-shield units — `max_shield == 0` (Espionage Probe) → hull damage directly (`apply_damage`).
- M07 — Explosion at <70% hull — after a non-fatal hull hit with shield at 0 and `hull*100 < max_hull*70`, explosion chance = `(max_hull - hull)/max_hull` (`apply_damage`).
- M08 — Attacker-first volleys — attacker's full volley resolves before the defender's survivors return fire (`src/combat.rs::simulate_combat` comment + call order; `fast_combat.py` `_fire(atk...)` then `_fire(def...)`).
- M09 — Rapidfire continuation — for RF `n ≥ 2`, continue with probability `(n-1)/n`, re-targeting a random alive unit (`fire_phase` loop).
- M10 — Analytical RF multiplier — `mult = 1/(1 - cont_prob)` where `cont_prob = Σ_f f·(N-1)/N`, capped at 20 when `cont_prob ≥ 0.95` (`fast_combat.py::_fire`; `analytical.rs::fire`).
- M11 — Spike vs chip shots — shots ≥ a target unit's full HP kill exactly one unit (overkill discarded); sub-lethal shots pool into the stack's shield then hull (`fast_combat.py::_fire`).
- M12 — FAST_THRESHOLD = 500 — total units above 500 switches to the analytical resolver (`fast_combat.py::should_use_fast`; `FAST_THRESHOLD = 500`).
- M13 — Analytical noise — per-survivor Poisson rate `lam = min(lam_tot/survivors, 500)` perturbed by `max(0.25, 1 + gauss(0, 0.15))` (σ = `0.15/√SUBSTEPS`, `SUBSTEPS=1`) (`fast_combat.py::_fire`).
- M14 — λ>30 tail closure — 2-moment compound-Poisson + Normal upper tail via `erfc`, conditional truncated-Normal moments, explosion hazard `exp(-n_s·x_eff)` (`fast_combat.py::_fire`, lam > 30 branch).
- M15 — λ≤30 exact convolution — Poisson shot-count convolution over per-damage-fraction histogram lineages (`fast_combat.py`, `lam <= 30` branch; `analytical.rs`).
- M16 — Heavy-shot split — chip shots with `per_shot ≥ HEAVY_SHOT_TAU * hull` (`HEAVY_SHOT_TAU = 0.25`) are convolved as an explicit heavy lineage (`fast_combat.py`).
- M17 — Calibration dials — `OVERLAY_BETA = 1.0`, `HAZARD_MID = 0.5`, `V_CAP = 0.25` (`fast_combat.py`; mirrored as `pub const` in `analytical.rs`).
- M18 — Damage histogram bins — 5%-wide buckets `key = min(int(x/0.05), 19)` storing weighted means; spread damping `BETA_SPREAD = 0.8` (if `s_eff ≤ unit_shield`) else `0.5` (`fast_combat.py`).
- M19 — Stochastic survivor rounding — `int(x + rng.random())`, one draw per type, unbiased (`fast_combat.py::_sround`; `analytical.rs::sround`).
- M20 — Stalemate detection — if total counts are unchanged on both sides after a round, `stalemate=True` and the winner is Draw (`fast_combat.py`).
- M21 — RNG engines — per-unit core: `Xoshiro256PlusPlus::seed_from_u64` (`combat.rs`); analytical: CPython MT19937 port `PyRandom` with `init_by_array`, `genrand_res53`, cached Box-Muller `gauss`, libm FFI (`pyrng.rs`).
- M22 — Debris formula — `debris_X = int(total_lost_X * debris_pct)`; deuterium only if `deuterium_in_debris` (`fast_combat.py::calculate_debris`; `analytical.rs::debris_of`).
- M23 — Default debris — `DEFAULT_DEBRIS_PCT = 0.30` in code, but UI default select = `0.80` (`fast_combat.py` vs `index.html`).
- M24 — Budget — `compute_budget = int(fleet_value(enemy) * multiplier)`; `fleet_value = Σ count·(M+C+D)` (`fleet.py`).
- M25 — Multiplier grid — only 0.1-step values accepted (`fleet.py::validate_multiplier`; Pydantic `validate_multiplier`).
- M26 — Profit loss scale — `loss_scale = 1 - debris_pct` when `optimization_target == "maximize_profit"`, else 1.0 (`orchestration.py::optimize`).
- M27 — Greedy counter map — hardcoded enemy→counter: LF/HF→Cruiser, Cruiser→BC, BS/Bomber→Reaper, BC→Battleship, Destroyer→Deathstar, Deathstar→Destroyer, cargo/probe/Reaper→LF (`greedy.py::COUNTER_MAP`).
- M28 — Shield-dome reserve — if LSD or SSD present, reserve 20% of budget for `HIGH_DAMAGE = [battleship, bomber, destroyer, deathstar, reaper]`, bought cheapest-first (`greedy.py::phase_a1_counter_ratio_init`).
- M29 — Greedy fill/trim — trim lowest damage-per-cost when over; top-up best damage-per-cost when under (`greedy.py::phase_a2_budget_fill`).
- M30 — Local search cap — hill-climb stops after 50 iterations without improvement or 1 s (`greedy.py::phase_a3_local_search`).
- M31 — Progressive seeds — Phase 0 top-4 pure singles; Phase 1 top-3 50/50 pairs; `COMBAT_SHIPS` = 9 types (no cargo/probe/recycler/pathfinder) (`progressive_seeds.py`).
- M32 — Scale-down validation — sensitivity sims may run at 1/10 or 1/100 scale if per-resource-unit return `(0.80·dl − 0.20·al)/fv` is within 10% of full scale (`progressive_seeds.py::validate_scale`).
- M33 — GA drift bounds — seeded type `lo=0, hi = max(share*2.5, share+0.25, 0.20)` of budget; unseeded `hi = 0.30` of budget (`genetic.py::_drift_bounds_for_seed`).
- M34 — Never-promoted types — `_NO_PROMOTE = {espionage_probe, solar_satellite, crawler}` are locked at (0,0) bounds (`genetic.py`).
- M35 — Reallocate move — moves 15–60% of affordable cost between two types, budget-neutral (`genetic.py::_reallocate_mutate`).
- M36 — GA default config — pop 50, mut 0.15, crossover 0.7, elitism 2, tournament 3, 5 s, 100 sims/eval, step_fraction 0.25, macro 0.15, reallocate 0.25 (`genetic.py::GAConfig`).
- M37 — Win threshold — `_WIN_THRESHOLD = 0.95`; attack: `win_prob ≥ 0.95`; defend: `1 − win_prob ≥ 0.95` (`genetic.py`; `statistics.py::_HARD_CONSTRAINT_THRESHOLD`).
- M38 — Tier bonus & anti-camping — winners get flat `+1000.0`; fleets spending < `_UNDERBUDGET_FLOOR = 0.90` of budget pay `_UNDERBUDGET_PENALTY = 5.0` per unit (`genetic.py`).
- M39 — CRN seeds — generation seed `base_seed + gen`; per-fleet `+i*7919` (fast) or `+i*n_sims` (Rust) (`genetic.py::_evaluate_population_with_crn`; `statistics.py::CRNManager`).
- M40 — Sensitivity tags — `impact_pct > 20` = critical, `> 5` = important, `< −5` (non-fodder) = dead_weight, `< −5` and in `FODDER_SHIPS = {light_fighter, heavy_fighter, small_cargo, large_cargo, espionage_probe}` = fodder, else negligible (`orchestration.py::_sensitivity_analysis`).
- M41 — Swap acceptance — promise ≥ `_SWAP_PROMISE_PCT = 15%`, accept if loss improves > `_SWAP_ACCEPT_PCT = 5%` without reducing win rate, `_SWAP_TIME_BUDGET_S = 15` (`orchestration.py`).
- M42 — Alternatives gates — `_MIN_ALT_SHARE_DISTANCE = 0.10` (cost-share TV distance), `_MAX_ALT_LOSS_FACTOR = 1.10`, `_MAX_ALTERNATIVES = 2` (`orchestration.py`).
- M43 — Recycler planning — `recycler_cap = 20000 * (1 + hyperspace_tech * 0.05) * (1.25 if collector_class)`; `needed = ceil(debris_total / cap)` (`orchestration.py`).
- M44 — 95% CI — `mean ± 1.96 * stddev/√n` (`_Z95 = 1.959963984540054` in `statistics.py`; inline 1.96 in orchestration).
- M45 — Net profit — `net_profit = debris_total − mean_attacker_loss_raw` (`orchestration.py`).
- M46 — Fuel/speed penalty — vs BC reference: factor `= 1 − bonus + 0.015·log2(v_bc/v)⁺ + 0.012·log2(fuel/fuel_bc)⁺`, clamped [0.95, 1.15]; hyperspace ships with fuel ≤ 500 get `−0.01` bonus (`_PENALTY_ALPHA=0.015`, `_PENALTY_BETA=0.012`, `_PENALTY_CAP=1.15`, `_PENALTY_FLOOR=0.95`, `_HYSPACE_BONUS=0.01`, `_HYSPACE_FUEL_MAX=500`) (`fleet.py::derive_penalty_factors`).
- M47 — Drive multipliers — combustion +10%/lvl, impulse +20%/lvl, hyperspace +30%/lvl; drive switches: Small Cargo @ Impulse 5, Bomber @ Hyperspace 8, Recycler @ Impulse 17 → Hyperspace 15 (`fleet.py::SHIP_DRIVE_DATA`).
- M48 — Resource preference penalty — `beta * fleet_cost * (1 − score)`, `score = (weighted/raw − 1)/(max_w − 1)`, zero if `max_w ≤ 1` (`fleet.py::resource_preference_penalty`).
- M49 — Rapidfire table values — e.g. Cruiser vs LF = 6, vs RL = 10; Bomber vs RL/LL = 20, HL/IC = 10, GC/PT = 5; Destroyer vs BC = 2, LL = 10; BC vs SC/LC = 3, HF/CR = 4, BS = 7; Reaper vs BS = 7/Bo = 4/De = 3; Pathfinder vs LF = 3/HF = 2/CR = 3; Deathstar vs EP/SS/Crawler = 1250, LF = 200, HF = 100, CR = 33, BS = 30, BC = 15, Bomber = 25, Destroyer = 5, SC/LC/Recycler = 250, Pathfinder = 30, Reaper = 10, RL/LL = 200, HL/IC = 100, GC = 50 (`src/rapidfire.rs`; `fast_combat.py::RAPIDFIRE`).
- M50 — Armor semantics — `base_armor = (cost_metal + cost_crystal) / 10` (= structure/10), asserted by tests (`src/ships.rs`).
- M51 — Stats corrections — EP cost 0/1000/0; HF armor 1000; LC armor 1200; Deathstar vs BC = 15 (not 250); Reaper vs BS=7/Bo=4/De=3 (not anti-fighter) (`src/ships.rs`, `src/rapidfire.rs` docs/tests).

---

## Notable concerns

1. **Gate-1 (static hardcoded AI) — full violation if ported as-is.** Every object id/name, cost, stat, and RF relationship is a hardcoded source of truth in Rust (`ships.rs`, `rapidfire.rs`), Python (`fleet.py`, `fast_combat.py`), the JSON fixture, and even JS (`SHIP_META`). Adding a host object requires edits in 4+ places. The `tools/gen_ogame_tables.py` JSON fixture is the only step toward a single source, and it only covers the analytical path. For OGameX this would need to be read from the host at planning time.
2. **Gate-2 (over-engineering).** The analytical resolver is extraordinarily elaborate for a "~500x faster" goal: compound-Poisson, heavy-shot convolution, histogram lineage de-collapse, 2-moment tail closure with truncated-Normal conditional moments, and four hand-calibrated dials (`HEAVY_SHOT_TAU`, `OVERLAY_BETA`, `HAZARD_MID`, `V_CAP`). The whole model is then **duplicated bit-for-bit in Rust** (`analytical.rs` + an exact CPython MT19937 port in `pyrng.rs` + libm FFI) purely for parity. Triple/quadruple duplication of stat tables with drift tests is a smell; the alternatives engine (harvest + two forced GA passes + diversity/quality gates) is a large amount of machinery for a UI nicety.
3. **Gate-3 (human behavior).** Not an agent, so no authenticity concerns — but as a decision source it always optimizes to an exact budget and a 95% win floor, which a human does not always do. It is combat-only: no flying, mining, farming, or fleet-save logic; README explicitly omits lifeforms, officers, fuel/flight time, and round-by-round visualization.
4. **JS data drift.** `app.js::SHIP_META` disagrees with the Python/Rust tables: Reaper `fuel: 100` (Rust `ships.rs` = 1100; `fleet.py::SHIP_DRIVE_DATA` = 900 — three different values), Pathfinder `speed: 10000` (Rust/`fleet.py` = 12000). These are display-only today but are a latent bug if the UI ever feeds them back.
5. **Default inconsistency.** API/`fast_combat.py` default `debris_pct = 0.30` and `deuterium_in_debris = false`, while the UI defaults the select to **80%** and the checkbox to **checked**. README also says 80% default.
6. **Stale comments.** `fast_combat.py` comments say the Rust engine "omits" the Deathstar-vs-Recycler RF row, but `rapidfire.rs` now includes `Recycler => 250`.
7. **Dead code.** `lib.rs::simulate_combat_py` computes `initial_att_val` by adding defender-defense value into the attacker total, then discards it (`// not exposed yet`).
8. **UI already excludes by default** Deathstar, Bomber, Recycler, Small/Large Cargo, Espionage Probe from recommendations — a policy/taste list baked into HTML, which a port must reconcile with gate-1 (it is "taste", but it is static).

---

## Confidence

**High** for the facts above: I read the README, all Rust sources (`lib.rs`, `combat.rs`, `ships.rs`, `rapidfire.rs`, `analytical.rs`, `analytical_tables.rs`, `pyrng.rs`, `Cargo.toml`), all Python core/optimizer/API modules (`combat.py`, `fast_combat.py`, `fleet.py`, `tech.py`, `greedy.py`, `genetic.py`, `progressive_seeds.py`, `orchestration.py`, `objective.py`, `statistics.py`, `app.py`, `routes.py`, `schemas.py`, `logging_config.py`, `pyproject.toml`), and the web UI (`index.html`, `app.js`). I did not read `tools/gen_ogame_tables.py`, `base.html`, or the 142+52 test files individually, so statements about test internals are inferred from docstrings and test names quoted in the sources. Function/constant names, formulas, thresholds, and file paths were transcribed directly.