# OGameX-Combat-Simulator (`rbardtke/OGameX-Combat-Simulator`) — Research Report

## Overview

A **client-side OGame combat simulator** for the OGameX OGame-clone, packaged three ways:

1. **Rust → WASM engine** (`src/lib.rs`) compiled with `wasm-pack`, consumed by a **browser extension** (`browser-extension/`).
2. **Pure-Python reimplementation** (`python-simulator/combat_simulator.py`) with "feature parity".
3. **Sync/CI scaffolding** to keep the hardcoded game data in step with upstream `lanedirt/OGameX`.

Latest commit: `5370a59` "add fleet api 1 and 2 support, add version check"; latest release **v1.5.0** (8 months before 2026-09). 1 contributor (`rbardtke`). MIT license. Languages: JS 50.6%, HTML 19.2%, CSS 10.6%, Python 8.8%, Rust 6.1%, Shell 4.7%.

Purpose is **human-facing pre-attack planning**: enter attacker/defender fleets + tech levels, simulate the battle, get per-round losses, debris, plunder, profit/loss, and flight time. It never acts on a game — it only reads numbers from a form. (README feature claims: "~200x faster than PHP", "100% private, works offline", "1–100 simulations with averaged results".)

## Architecture & entry points

- **WASM engine** — `src/lib.rs`
  - `#[wasm_bindgen] pub fn simulate_battle(input_json: &str) -> String` — the only public entry point; parses JSON `BattleInput`, runs `process_battle_rounds`, returns JSON `BattleOutput`.
  - Structs: `BattleInput { attacker_units: HashMap<i16, BattleUnitInfo>, defender_units: ... }`, `BattleUnitInfo { unit_id, amount, attack_power, shield_points, hull_plating, rapidfire: HashMap<i16,u16> }`, `BattleUnitCount`, `BattleUnitInstance`, `BattleRound`, `BattleOutput { rounds: Vec<BattleRound> }`.
  - Crate `ogame-combat-wasm` (`Cargo.toml`): `crate-type = ["cdylib"]`; deps `wasm-bindgen 0.2`, `serde 1.0`, `serde_json 1.0`, `rand 0.8` (feature `getrandom`), `getrandom 0.2` (feature `js`); release profile `opt-level=3, lto=true, codegen-units=1`.
- **Browser extension** — `browser-extension/app.js` (single large UI+logic file, ~2000+ lines), `index.html`, `manifest.json` (Manifest V2, `version: "1.5.0"`; also a `manifest-v3.json`), `background.js`, `pkg/` (built WASM).
  - Boot: `initWasm()` dynamic-imports `./pkg/ogame_combat_wasm.js` and calls `wasm.default('pkg/ogame_combat_wasm_bg.wasm')`.
  - Orchestrator: `simulateBattle()` — collects units via `collectUnits(side)`, clamps runs to 1–100 (default 5), calls `wasmModule.simulate_battle(inputJson)` per run, averages via `averageSimulationResults()`, renders via `displayResults()`.
- **Python** — `python-simulator/combat_simulator.py`: `CombatSimulator` class (`simulate_battle`, `simulate_multiple`, `_average_results`) and `FlightTimeCalculator` class; `main()` demo.
- **Build/dist** — `build.sh` (`wasm-pack build --target web --out-dir OGameX-CombatSim/pkg --release` — note: this out-dir does **not** match where `app.js` imports from, `browser-extension/pkg`), `package.sh` (runs `wasm-pack build --target web --out-dir browser-extension/pkg --release`, zips Chrome V2/V3 + Firefox).
- **Sync tooling** — `check-ogamex-updates.sh`, `.github/workflows/sync-ogamex.yml` (daily 02:00 UTC), `.github/workflows/auto-sync-ogamex.yml` (daily 03:00 UTC), `.ogamex-last-sync` marker.

## Scheduling & loop model

**No scheduling, no game loop, no acting on an account.** The only "loop" is the battle simulation loop itself, driven by a user clicking a button:

- `process_battle_rounds` (`src/lib.rs`) / `simulate_battle` (Python): `for _ in 0..6` — **max 6 rounds**; breaks early if either side's unit list is empty.
- Per round, two combat phases run back-to-back **before any cleanup**: attacker→defender, then defender→attacker (`process_combat(...)` called twice with sides swapped and `is_attacker` flag).
- Within a phase, each unit iterates a `while continue_attacking` loop for rapidfire chains.
- The JS wrapper adds the **multi-run** loop (`simulateBattle`): `for (let i = 0; i < simulationRuns; i++)`, with a 50ms UI yield before the first run and a 10ms yield between runs when `simulationRuns > 5`.
- The only timed/background jobs are the GitHub Actions cron schedules and the browser-extension update check (`checkForUpdates()` on load, hits `https://api.github.com/repos/rbardtke/OGameX-Combat-Simulator/releases/latest`). No cron inside the simulator itself.

## Decision engine & algorithms

The "decision engine" is the combat resolution math. All formulas duplicated across Rust (`src/lib.rs`), JS (`app.js`), and Python.

**Combat resolution (`process_combat`, `src/lib.rs`):**
- Each attacker uses flat `damage = attack_power` (no per-shot randomization).
- Target: `rng.gen_range(0..defenders.len())` — uniform random among **currently alive list entries**.
- Bash/negation rule: `if damage < 0.01 * target_metadata.shield_points { continue; }` (uses the target's **full** shield stat from metadata, matching OGameX's bashing rule).
- Damage order: shield first, then hull: `shield_absorption = min(damage, current_shield)`; remainder to hull.
- Explosion: `if current_hull / max_hull < 0.7 { explosion_chance = 100.0 - (ratio * 100.0); if roll(0..=100) < explosion_chance { hull = 0; shield = 0; } }`.
- Rapidfire: `chance = 100.0 / amount; rounded = floor(chance*100)/100; rf_chance = 100.0 - rounded; roll(0.0..100.0); continue = roll <= rf_chance` — i.e. RF amount 4 → 75%, 10 → 90%, 33 → ~96.97%.

**Cleanup (`cleanup_round`)**: remove `hull <= 0` units (incrementing per-round loss counters), then restore survivors' `current_shield_points = metadata.shield_points` (full regen between rounds).

**Loss accounting (`calculate_losses`)**: accumulated losses = `initial_count - current_count` per unit id vs. battle start.

**JS-only decision/policy logic** (all in `app.js`):
- `applyTechMultipliers(base, level) = base * (1 + level * 0.1)`.
- `collectUnits` applies `GENERAL_COMBAT_BONUS = 2` to weapons/shielding/armour when class = General (2).
- `averageSimulationResults`: win/draw/loss %, `averageRounds = (sum/totalRuns).toFixed(1)`, per-round unit counts averaged with `Math.round(sum/totalRuns)`, and a synthetic `is_final_averaged` round.
- Plunder, debris, profit/loss, reaper collection, IPM — see Discrete mechanisms.

## Data model & persistence

**No persistence.** Everything is transient JS state:
- `wasmModule`, `wasmReady`, `lastBattleResult` (latest averaged result, used for share), `lastIpmResults` (IPM modal state) are module-level globals in `app.js`.
- The only durable output is a **share code**: `collectShareData()` builds a compact object (`SHARE_SCHEMA_VERSION = 1`, keys `v/atk/def/s/ft/br`), serialized with `LZString.compressToEncodedURIComponent` (base64 fallback), importable via URL `?share=` param (`checkUrlForShareCode`).
- OGameX **fleet API import/export**: `exportAPIKey(side)` emits both "API 1" (pipe/`|`+`;` legacy) and "API 2" (JSON) formats; `importAPIKey` / `parseAPI1` parse them back. This is the only integration surface with a running OGameX account.
- Data flow: form inputs → `collectUnits` builds `{unit_id, amount, attack_power, shield_points, hull_plating, rapidfire}` per unit → JSON string → WASM → JSON string → parsed → averaged → rendered.
- Rust `BattleUnitInstance` tracks per-unit `current_shield_points`/`current_hull_plating` during a battle only; discarded after `simulate_battle` returns.

## Config surface

No config file. "Config" = hardcoded JS constants + form fields.

Hardcoded constants (`browser-extension/app.js`):
- `UNITS` — full stat table (attack/shield/hull/rapidfire/cost) for ships 202–219 and defense 401–408.
- `SHIP_SPEEDS`, `SHIP_DRIVES`, `DRIVE_BONUSES = {combustion:0.10, impulse:0.20, hyperspace:0.30}`, `CARGO_CAPACITY`.
- `DEFENSE_STRUCTURES` (structure + metal/crystal cost per defense), `IPM_BASE_DAMAGE = 12000`.
- `REAPER_UNIT_ID = 218`, `REAPER_COLLECTION_PERCENT = 0.30`.
- `CHARACTER_CLASSES = {NONE:0, COLLECTOR:1, GENERAL:2, DISCOVERER:3}`, `GENERAL_COMBAT_BONUS = 2`.
- `CURRENT_VERSION = '1.4.0'` (stale vs `manifest.json` 1.5.0), `SHARE_SCHEMA_VERSION = 1`.
- Hardcoded research ids `109/110/111/115/117/118/114`, ship ids `[202..215,218,219]`, defense ids `[401..408]`, missile ids `{502,503}` in `exportAPIKey`.

User-adjustable form fields: tech levels (weapons/shield/armour/combustion/impulse/hyperspace drive/hyperspace tech), character class per side, unit counts, coords (galaxy/system/planet), `simulationRuns` (1–100, default 5), `plunderPercent` (50/75/100), `debrisShipPercent` (default 30), `debrisDefensePercent` (default 0), `debrisDeuteriumEnabled` (checkbox), `fleetSpeedPercent` (1–100), universe `fleetSpeedWar/Holding/Peaceful` multipliers, defender resource stockpiles.

## Edge cases & failure handling

- **Empty-side guard**: round loop breaks if either side is empty before combat; `simulateBattle` refuses if either side has zero units ("enter at least one attacker/defender unit").
- **Random target index** `gen_range(0..defenders.len())` would panic on an empty vec, but is protected by the per-round non-empty check.
- **Dead-but-not-removed units**: destroyed units are only removed in `cleanup_round`, so within a single phase a unit exploded earlier in the same phase stays in the `Vec` and can be re-targeted — damage lands on a hull that is already ≤ 0 (wasted shots). This is a fidelity divergence vs. OGameX's per-shot removal.
- **`simulate_battle` uses `.unwrap()`** on `serde_json::from_str` and `to_string` — malformed JSON panics inside WASM; JS always feeds valid JSON, so unreachable in practice. Python equivalent raises exceptions naturally.
- **Bash negation skips the hit counter**: a negated (<1% shield) shot does not increment `hits_*` or `full_strength_*`.
- **Explosion roll is inclusive `0..=100`** (101 values) compared with `< explosion_chance` — a minor off-by-one vs. a true percentage.
- **Flight-time validation** (`updateFlightTime`): galaxy 1–9, system 1–499, planet 1–16, speed 1–100; out-of-range hides the result. `speedPercentOGame = speedPercent / 10` before the formula.
- **No-ship fallback**: `getSlowestShipSpeed()` returns `10000` if the attacker has no known ships.
- **Plunder zero-guard**: returns all-zero if `attackerCargoCapacity === 0` or attacker lost.
- **Share import**: LZ-String first, base64 fallback, then `JSON.parse`, validates `data.v` exists; `parseAPI1` only accepts keys in the hardcoded ranges (202–215, 218, 219, 401–408, 109–118).
- **Update check**: silently fails on network errors; `compareVersions` strips a leading `v`.
- **No error recovery in the engine**: RNG variance only; results are averages, not confidence intervals.

## Anti-detection & authenticity

**None.** This is an offline, read-only calculator for humans. It makes no network calls (except the GitHub release check), stores no cookies, uses no accounts, and never touches the game. There is no anti-detection layer because there is nothing to detect. For the AI module context: it contains no scheduling, no reaction latency, no humanization, no uptime shaping — it is not an actor at all. The only "authenticity-relevant" artifact is the multi-run averaging, which mirrors what a careful player does before launching (simulate first, then commit) — and that is exactly the gate-3 reference behaviour, not automation.

## Discrete mechanisms

- **M01 — 6-round cap** — `for _ in 0..6`; break when a side empties (`src/lib.rs: process_battle_rounds`; `python-simulator/combat_simulator.py: simulate_battle`).
- **M02 — Unit expansion to instances** — one `BattleUnitInstance` per unit with full shield/hull (`expand_units`).
- **M03 — Two combat phases per round** — attacker fires, then defender fires, before any cleanup (`process_combat` called twice with swapped args).
- **M04 — Uniform random target** — `rng.gen_range(0..defenders.len())` per shot.
- **M05 — Flat per-shot damage** — `damage = attacker_metadata.attack_power` (no dice on damage).
- **M06 — Bashing negation** — shot ignored when `damage < 0.01 * target_metadata.shield_points` (metadata/full shield).
- **M07 — Shield-then-hull absorption** — `shield_absorption = min(damage, current_shield)`, remainder to hull; stats accumulate `absorbed_damage_*`.
- **M08 — Explosion check** — when `current_hull/max_hull < 0.7`: `chance = 100 - 100*ratio`, `roll(0..=100) < chance` → hull=0, shield=0 (applied immediately per hit, not in cleanup).
- **M09 — Rapidfire** — `rf_chance = 100 - 100/amount` (amount from metadata table), `roll(0.0..100.0) <= rf_chance` → attack again against a new random target.
- **M10 — Round cleanup** — remove hull≤0 units, record per-round losses, regen survivors' shields to full (`cleanup_round`).
- **M11 — Accumulated losses** — `initial_count - current_count` per id (`calculate_losses`).
- **M12 — Round stats** — `hits_*` (shots), `full_strength_*` (Σ damage), `absorbed_damage_*` (Σ shield absorption), per-side ships/losses maps.
- **M13 — Tech multiplier** — `base * (1 + level*0.1)` for weapons/shield/armour (`applyTechMultipliers`).
- **M14 — General class bonus** — `+2` to weapons/shield/armour before multiplying (`GENERAL_COMBAT_BONUS = 2`).
- **M15 — Simulation runs clamp** — `Math.max(1, Math.min(100, parseInt(...) || 5))` (`simulateBattle`).
- **M16 — Averaging** — win/draw/loss %, `averageRounds` to 1 decimal, per-round counts `Math.round(Σ/totalRuns)`; synthetic final `is_final_averaged` round when run lengths differ (`averageSimulationResults`, `averageUnitCounts`).
- **M17 — Debris field** — `floor(cost * lossAmount * percent / 100)` per resource; ships default 30%, defense default 0%, deuterium optional (`calculateDebris`).
- **M18 — Recyclers needed** — `Math.ceil(totalDebris / 20000)` (`calculateRecyclersNeeded`).
- **M19 — Plunder** — zero unless attacker won; `floor(resource * plunderPercent/100)` each; if cargo < total, proportional `floor(available * cargo/total)` (`calculatePlunder`).
- **M20 — Cargo capacity** — `Σ floor(baseCapacity * (1 + 0.05*hyperspaceTech)) * amount` (`calculateCargoCapacity`).
- **M21 — Moon chance** — `min(20, floor((debrisMetal + debrisCrystal) / 100000))` % (`displayDebrisInfo`).
- **M22 — Reaper debris collection** — 30% of remaining debris, capped by reaper cargo capacity; attacker reapers collect first, defender from remainder (`calculateReaperDebrisCollection`, `collectDebris`).
- **M23 — Fair loot distribution** — first pass up to `floor(capacity/3)` per resource, second pass drains remainder (`distributeLoot`).
- **M24 — IPM damage** — `intercepted = min(count, abm)`; `totalDamage = hits * 12000 * (1 + 0.1*weaponsTech)`; per-defense `armor = structure*(1 + 0.1*armourTech)/10`; destroy `floor(remainingDamage/armor)` capped by count (`simulateIpmAttack`).
- **M25 — IPM targeting priority** — `0` cheapest first, `1` most expensive first, otherwise specific unit first then cheapest (`getDefenseSortedByPriority`, sort by `metal+crystal`).
- **M26 — Coordinate distance** — same→5; `|Δgalaxy|*20000`; `|Δsystem|*95 + 2700`; `|Δplanet|*5 + 1000` (`calculateDistance`).
- **M27 — Flight time** — `max(round((35000/speed_pct * sqrt(distance*10/slowest) + 10)/fleet_speed), 1)` seconds; JS passes `speed_pct = input/10` (`calculateFlightTime`, `updateFlightTime`).
- **M28 — Ship speed** — `base + floor(base * driveLevel * driveBonusPct)`; upgrade thresholds switch drive/base speed (`calculateShipSpeed`, `SHIP_DRIVES`).
- **M29 — Slowest ship** — min over attacker fleet with ≥1 unit; fallback 10000 (`getSlowestShipSpeed`).
- **M30 — Coordinate/speed validation** — galaxy 1–9, system 1–499, planet 1–16, speed 1–100 (`updateFlightTime`).
- **M31 — Share encoding** — LZ-String `compressToEncodedURIComponent`, base64 fallback; schema `v=1`; round/result compression with abbreviated keys (`generateShareCode`, `compressBattleResult`).
- **M32 — Fleet API import/export** — API 1 pipe format and API 2 JSON, both with hardcoded id ranges (`exportAPIKey`, `parseAPI1`, `importAPIKey`).
- **M33 — Update check** — semver compare of `CURRENT_VERSION` vs latest GitHub release tag (`checkForUpdates`, `compareVersions`).
- **M34 — Drive bonuses** — combustion 10%, impulse 20%, hyperspace 30% per level (`DRIVE_BONUSES`).

## Notable concerns

**Gate-1 violations (hardcoded AI universe) — the repo's defining anti-pattern.** `UNITS` (attack/shield/hull/rapidfire/cost), `SHIP_SPEEDS`, `SHIP_DRIVES`, `CARGO_CAPACITY`, `DEFENSE_STRUCTURES`, research ids `109/110/111/115/117/118/114`, ship ids, defense ids and missile ids `502/503` are all hardcoded in `app.js` as a **source of truth**. This is precisely what gate 1 forbids in the AI module. The entire `SYNCING.md` / `check-ogamex-updates.sh` / two-workflow apparatus exists solely to reconcile this drift — evidence the hardcoding is known to be a maintenance liability. If any of this is borrowed for the AI module, object ids/prices/requirements must be read from the OGameX host, not copied.

**Gate-2 over-engineering.** (a) Two full, independent engines (Rust/WASM + Python) maintained for "parity" are a duplicate authority and have already drifted (see below). (b) Four overlapping sync mechanisms (`check-ogamex-updates.sh`, `sync-ogamex.yml`, `auto-sync-ogamex.yml`, and manual workflow in `SYNCING.md`) do substantially the same job. (c) `build.sh` targets an out-dir (`OGameX-CombatSim/pkg`) that `app.js` does not import from; only `package.sh` builds to the correct `browser-extension/pkg`.

**Engine fidelity divergences (possible bugs):**
- Destroyed units remain targetable for the rest of the same phase (removed only at cleanup), so later shots can land on already-dead units — wasteful overkill that likely diverges from OGameX per-shot removal.
- Explosion is applied immediately after each hit (`process_combat`) rather than during cleanup.
- Rust `roll(0..=100) < explosion_chance` is an inclusive-range vs. percentage subtlety.
- Python `simulate_multiple` averages only the *first `min(rounds)` rounds* then appends one averaged final round; JS does the same but with a different final-round merge when run lengths match.

**JS↔Python drift (concrete):**
- Recycler (209): Python sorts upgrades by level descending and takes the first match → Impulse 17 wins (4000). JS iterates in declared order with later-override → Hyperspace 15 wins (6000). OGameX uses the *fastest* drive, so JS is correct and Python is wrong for a Recycler with both techs.
- Bomber (211): JS upgrade `{level:8, drive:'hyperspace', baseSpeed:5000}`; Python upgrade lacks `baseSpeed` → stays 4000.
- Pathfinder (219): JS has `[{level:3, impulse}, {level:3, hyperspace}]`; Python only `{level:3, hyperspace}`.

**Documentation vs. code drift:**
- README/FEATURES.md claim Solar Satellite (212) and Crawler (217) are supported; `UNITS`/`SHIP_SPEEDS` contain neither.
- FEATURES.md mislabels ship ids: "Battlecruiser (218), Reaper (215)" — actually 215 = Battlecruiser, 218 = Reaper.
- FEATURES.md "Accuracy" section references PHP paths (`BattleEngine.php`, `FleetMissionService.php`) while SYNCING.md states OGameX actually uses `rust/battle_engine_ffi/` — the sync scripts watch both, inconsistently.
- `CURRENT_VERSION = '1.4.0'` in `app.js` vs `manifest.json`/release `1.5.0`.
- FEATURES.md lists "Local storage" as a requirement but the code uses none.
- Python README speed formula "Base + (Base/100)×(Drive Level×Drive Bonus %)" is consistent with code, but the FEATURES.md `calculate_flight_time(origin, target, attacker_units, tech_levels)` usage example does not match the actual static method signature `(distance, speed_percent, slowest_speed, fleet_speed)`.

**Gate-3 relevance.** Nothing here is behaviour a human wouldn't do — it is literally a human's pre-battle tool. No action, no loop, no anti-detection. Its only relevance to the AI module is as a **formula reference**: the combat, debris, plunder, moon, reaper, IPM, and flight-time math are candidate sources for the module's own outcome prediction, provided the inputs are derived from host data (gate 1) rather than copied tables.

**No tests.** The repo ships no test files for the combat engine; correctness is asserted only by README claims ("Results match OGameX server calculations").

## Confidence

**High** on existence, structure, constants, formulas, and thresholds — everything above was read directly from `src/lib.rs`, `browser-extension/app.js`, `python-simulator/combat_simulator.py`, `Cargo.toml`, `manifest.json`, and the docs/scripts.

**Medium** on the specific "divergence from OGameX" judgments (dead-unit re-targeting, per-hit explosion, Recycler drive selection) — I verified what this repo does and that JS/Python disagree, but did not diff against the actual `lanedirt/OGameX` `rust/battle_engine_ffi` source; the README's own "accuracy" claims are unverified and partially contradicted by the repo's internal inconsistencies.

**High** on the gate-1 flag: the hardcoded unit/price/requirement tables are present verbatim in `app.js` and are explicitly maintained out-of-band by the sync scripts.