I have the complete repository contents. Here is the exhaustive research document.

---

## Overview

`patrykstefanski/og-battle-engine` is a self-contained, deterministic **OGame combat simulator** implemented in **C** with thin **PHP** and **Python** API wrappers. License: **AGPL-3.0**. It is not a game server plugin — it is a standalone battle engine for "OGame and its clones" (topics: `ogame`, `ugamela`).

- Requirements: C99 compiler, CMake ≥ 3.1, PHP ≥ 7.3, Python ≥ 3.6.
- Build: `cmake -B build -DCMAKE_BUILD_TYPE=Release && cmake --build build --config Release`, producing a `BattleEngine` binary the wrappers invoke.
- ~10 stars, 2 forks, last commit ~4 years ago ("Add simulator mode").
- Files (complete repo): `BattleEngine.c`, `BattleEngine.php`, `BattleEngine.py`, `OG.php`, `OG.py`, `example-battle.php`, `example-battle.py`, `example-simulator.php`, `example-simulator.py`, `CMakeLists.txt`, `.clang-format`, `.github/workflows/`, `LICENSE`, `README.md`.
- **Scope:** core combat loop only (rapid fire, shields, hull, explosion chance, 6-round cap, draws). **No** debris fields, **no** moon chance, **no** resources/loot, **no** fleet escape — those must come from elsewhere.

## Architecture & entry points

Three parallel implementations of the *same* API, all delegating the actual simulation to one C binary:

- **`BattleEngine.c`** — the engine. `main(int argc, char *argv[])` reads `<SEED> <NUM_SIMULATIONS>` from argv, reads unit attributes + combatants from **stdin**, writes per-round stats to **stdout**. `simulate(seed, num_simulations)` is the orchestrator; `fight()` runs one battle; `fire()` one side's volley; `update_units()` prunes the dead.
- **`BattleEngine.php`** (`namespace BattleEngine`) — spawns the binary via `proc_open($enginePath . ' ' . $seed . ' ' . $numSimulations, ...)`, pipes a serialized text input to stdin, parses stdout with `preg_split('/\s+/', $stdout)` + `array_map('intval', ...)`. Entry point: `BattleEngine::simulate(array $attackers, array $defenders, int $seed = 0, int $numSimulations = 1): BattleOutcome[]`.
- **`BattleEngine.py`** — same contract; `BattleEngine.simulate(attackers, defenders, seed=0, num_simulations=1, timeout=None)`, using `subprocess.Popen` + `communicate()` (kills on `TimeoutExpired`).
- **`OG.php` / `OG.py`** — the OGame ship/defence catalogue (22 unit kinds) and human-readable `$names`, injected into the wrapper as `units_attributes`.

Public classes (PHP/Python mirror each other):
- `UnitAttributes(weapons, shield, armor, rapidFire)` — per-kind base stats; `rapidFire` is a map `targetKind => N`.
- `Combatant(weaponsTechnology, shieldingTechnology, armorTechnology, unitGroups)` — a player/party with per-kind unit counts.
- `UnitGroupStats` — 7 counters per kind per round: `timesFired, timesWasShot, shieldDamageDealt, hullDamageDealt, shieldDamageTaken, hullDamageTaken, numRemainingUnits`.
- `CombatantOutcome` (rounds of stats), `BattleOutcome(numRounds, attackersOutcomes, defendersOutcomes)`, `Error`.

**Wire format (stdin):** `num_kinds` → blank → per kind `weapons shield armor num_rapid_fire` then `kind count` pairs → blank → `num_attackers num_defenders` → per combatant `weapons_tech shield_tech armor_tech num_unit_groups` then `kind count` pairs. **Wire format (stdout):** `num_rounds` → blank → for each combatant, per round, per kind, 7 space-separated `uint64` counters (parsed by `parseCombatantOutcome` in `BattleEngine.php` as `array_slice($data, $index, 7)`).

## Scheduling & loop model

There is no scheduler — it is a pure synchronous, deterministic simulation:

```c
while (round < MAX_ROUNDS && attackers_party->num_alive > 0 && defenders_party->num_alive > 0)
{
    restore_shields(units_attributes, attackers_party);
    restore_shields(units_attributes, defenders_party);
    fire(units_attributes, attackers_party, defenders_party, round, random);
    fire(units_attributes, defenders_party, attackers_party, round, random);
    update_units(units_attributes, attackers, attackers_party, round);
    update_units(units_attributes, defenders, defenders_party, round);
    round++;
}
```
(`BattleEngine.c`, `fight()`)

- `MAX_ROUNDS = 6` (macro, `BattleEngine.c`).
- Per round: shields fully regenerate for **both** sides → attackers fire → defenders fire → dead units removed. A round ends even if one side is wiped mid-round.
- Multi-simulation: `simulate()` copies the initial `combatants` block with `memcpy` between iterations (`num_simulations > 1`), reusing the same `seed`, so the same seed yields identical results across runs.

## Decision engine & algorithms

All logic lives in `fire()` and `create_party()` in `BattleEngine.c`. Core formulas:

- **Attack power** (per shot): `damage = weapons * (1.0f + 0.1f * weapons_technology)`.
- **Max shield**: `shield * (1.0f + 0.1f * shielding_technology)` (`restore_shields`).
- **Max hull** (per unit, set once in `create_party`): `max_hull = 0.1f * armor * (1.0f + 0.1f * armor_technology)` — i.e. hull = 10% of "structural integrity" (standard OGame).
- **Target selection**: uniform random over all units alive at round start — `target = &targets[r % num_targets]` where `r = RANDOM_NEXT(r)`.
- **Shield application** (per shot against a target with `hull != 0`):
  ```c
  float hull_damage = damage - target->shield;
  if (hull_damage < 0.0f) {
      float max_shield = target_attrs->shield * (1.0f + 0.1f * defender->shielding_technology);
      float shield_damage = 0.01f * floorf(100.0f * damage / max_shield) * max_shield;
      target->shield -= shield_damage;
  } else {
      target->shield = 0.0f;
      if (hull_damage > hull) hull_damage = hull;
      hull -= hull_damage;
  }
  ```
- **Rapid fire loop** (after each shot): `do { ... } while (rapid_fire != 0 && (r = RANDOM_NEXT(r)) % rapid_fire != 0);` — with rapid-fire value `N`, the shooter fires again with probability `(N-1)/N`.
- **Explosion check** (after any hull damage leaves `hull != 0`):
  ```c
  if (hull < 0.7f * max_hull) {
      r = RANDOM_NEXT(r);
      if (hull < (1.0f / (float)RANDOM_MAX) * (float)r * max_hull) hull = 0.0f;
  }
  ```
  i.e. below 70% hull, probability of exploding = `1 - hull/max_hull` (the standard OGame "ship explosion" mechanic).

## Data model & persistence

- **No persistence.** The engine is stateless across runs; input on stdin, output on stdout.
- In-memory model (`BattleEngine.c`):
  - `struct unit_attributes { float weapons, shield, armor; uint32_t *rapid_fire; }`
  - `struct units_attributes { uint8_t num_kinds; uint32_t *rapid_fire; struct unit_attributes attributes[]; }` — rapid-fire stored as a dense `num_kinds × num_kinds` matrix (`calloc`).
  - `struct unit { float shield; float hull; uint8_t kind; uint8_t combatant_id; }` — **one struct per individual ship**, expanded flat (not grouped).
  - `struct party { struct combatant *combatants; struct unit *units; uint64_t num_alive; }`.
  - `struct unit_group_stats` — the 7 counters, allocated `MAX_ROUNDS * num_kinds` per combatant.
  - `MAX_UNITS = UINT64_MAX / sizeof(struct unit)` (overflow guard in `create_party`).
- `unit_groups` counts are `uint64_t`; kind ids are `uint8_t` (max 256 kinds); tech levels `uint8_t`.

## Config surface

- No config files. Everything is runtime parameters: unit attributes + combatants passed by the wrapper, and `SEED` / `NUM_SIMULATIONS` on the CLI.
- The only "data config" is `OG.php` / `OG.py` — the hardcoded 22-kind OGame catalogue (see below), which the examples pass in as `OG::$unitsAttributes` / `units_attributes`.

## Edge cases & failure handling

- `BattleEngine::__construct` (`BattleEngine.php`) calls `assertValidUnitsAttributes()`: rejects empty attribute set, non-contiguous kind keys (`no unit with key %d found`), and rapid-fire targets `>= num_kinds`.
- `assertValidCombatants()`: rejects `count($combatants) >= 256`, and any unit-group key not present in `units_attributes`.
- `UnitAttributes` ctor: `weapons/shield/armor <= 0.0` → `InvalidArgumentException`; rapid-fire values must be in `[0, 2^32-1]`.
- `Combatant` ctor: tech levels must be in `[0, 255]`; unit counts `>= 0`.
- `simulate()`: `seed < 0` rejected; `seed === 0` auto-randomized to `rand(1, 1000000000)` (PHP) / `random.randint(1, 1000000000)` (Python). C rejects `seed == 0` with "Seed cannot be 0".
- C `simulate()`: `num_simulations == 0` returns 0 (no output); `num_attackers == 0 || num_defenders == 0` prints `"0"` and returns success; `> 256` combatants on either side → error.
- `proc_open` failure → `Error('opening process failed')`; non-zero exit → `Error($stderr)`. Python `timeout=` raises on `TimeoutExpired` (kills the process).
- **No debris/moon/loot** handling at all — out of scope.

## Anti-detection & authenticity

**None.** This is a pure, deterministic combat simulator. There is no timing jitter, no humanization, no logging, no latency modeling, no session/uptime simulation. For OGameX-authenticity purposes it is useful only as a *mechanically accurate* combat oracle; all behavioral authenticity (reaction latency, save failure, uptime shape) must be layered elsewhere. Its determinism (fixed seed → fixed outcome) is actually a useful property for reproducible planning/appraisal.

## Discrete mechanisms

- **M01 — 6-round cap** — battles run at most `MAX_ROUNDS = 6` (`BattleEngine.c`, macro); after round 6 the loop exits regardless of survivors.
- **M02 — battle end condition** — loop stops when either `attackers_party->num_alive == 0` or `defenders_party->num_alive == 0` (`fight()`).
- **M03 — shield regen per round** — `restore_shields()` resets every unit's `shield` to `shield * (1 + 0.1 * shielding_tech)` at the start of **each** round, both sides.
- **M04 — fire order** — attackers fire first, then defenders fire, each round (`fight()` calls `fire` twice).
- **M05 — attack damage** — `damage = weapons * (1.0f + 0.1f * weapons_technology)` (`fire()`).
- **M06 — max shield** — `max_shield = shield * (1.0f + 0.1f * shielding_technology)` (`restore_shields()` / `fire()`).
- **M07 — max hull** — `max_hull = 0.1f * armor * (1.0f + 0.1f * armor_technology)` (`create_party()`); hull = 10% of structural integrity.
- **M08 — uniform random target** — `target = &targets[r % num_targets]` over units alive at round start (`fire()`).
- **M09 — wasted shots on dead units** — within a round, later shots can still select a unit whose `hull` was set to 0 earlier that round; `times_fired`/`times_was_shot` increment, damage skipped (`if (target->hull != 0.0f)`).
- **M10 — bounce rule** — when `damage < current shield`, shield loses `0.01 * floor(100 * damage / max_shield) * max_shield`; if `damage < 1%` of `max_shield`, `floor(...) == 0` → **no damage** (bounce) (`fire()`).
- **M11 — shield penetration** — when `damage >= current shield`, shield is zeroed and `hull_damage = damage - shield`, clamped to remaining hull (`fire()`).
- **M12 — explosion chance** — if `0 < hull < 0.7 * max_hull`, explode with probability `1 - hull/max_hull` (test `hull < (r / RANDOM_MAX) * max_hull`) (`fire()`).
- **M13 — rapid fire** — value `N = rapid_fire[target_kind]`; continue firing while `RANDOM_NEXT(r) % N != 0`, i.e. extra-shot probability `(N-1)/N`; `N == 0` means no rapid fire (`fire()`).
- **M14 — per-round stats** — 7 counters per kind per round: `times_fired, times_was_shot, shield_damage_dealt, hull_damage_dealt, shield_damage_taken, hull_damage_taken, num_remaining_units` (`struct unit_group_stats`, `dump_stats()`).
- **M15 — dead-unit compaction** — `update_units()` copies units with `hull != 0` to the front, sets `num_alive = n`, and increments `num_remaining_units` (`BattleEngine.c`).
- **M16 — draw semantics** — no explicit draw flag; a draw is inferred when `num_rounds == 6` with both `num_alive > 0` (`BattleOutcome::getNumRounds()`).
- **M17 — RNG** — Lehmer/Park-Miller MINSTD: `RANDOM_NEXT(r) = (r * 48271) % 2147483647`, `RANDOM_MAX = 2147483646`; seed must be non-zero (`BattleEngine.c` macros, `main()`).
- **M18 — deterministic multi-sim** — `simulate()` reruns `fight()` up to `num_simulations` times, `memcpy`-restoring the initial combatant state each iteration with the same seed (`BattleEngine.c`).
- **M19 — technology scale** — `weapons_technology`, `shielding_technology`, `armor_technology` each `uint8_t` in `[0, 255]`; every +1 level = +10% on the relevant stat (`load_combatants()`, formulas).
- **M20 — unit catalogue (hardcoded data)** — `OG.php` / `OG.py` statically define 22 kinds (ids 0–21): Small Cargo … Large Shield Dome, each with `(weapons, shield, armor, rapidFire)` — see duplication flag below.
- **M21 — kind-id space** — `num_kinds` is `uint8_t` (≤ 256 kinds), kind ids must be contiguous from 0 (`assertValidUnitsAttributes()`, `load_units_attributes()`).
- **M22 — combatant limit** — ≤ 255 per side (`assertValidCombatants` / C `num_attackers > 256` guard).
- **M23 — no post-battle economics** — no debris field, no moon-creation chance, no loot, no fuel, no fleet escape; output ends at unit counts per round.

## Notable concerns

1. **Duplication vs. the host engine (most relevant to OGameX).** This is a complete third-party reimplementation of OGame's combat formulas. Per the module's "never duplicate a supported driver's capability" rule, it must not be wired in as a *parallel authority* for combat truth alongside OGameX's own battle engine — the host engine is the authority for real battles. Its legitimate use is as a **planning/appraisal oracle** (e.g. `AppraiseObservedBattleReportAction`, campaign consultation) consuming host-provided unit attributes, not as the source of combat truth.
2. **Gate-1 hardcoding.** The *engine* (`BattleEngine.c`) is fully data-driven (reads all kinds/attributes from stdin). The **`OG.php` / `OG.py` catalogue is static, hardcoded OGame data** — a gate-1 violation if used as a module source of truth. For OGameX it must be swapped for host-read attributes (`OG::$unitsAttributes` pattern maps cleanly to a host-data adapter), never the shipped static table.
3. **No debris / moon / loot.** A battle report needs debris-field and moon-chance numbers for authenticity; this engine provides none, so those still require the host engine (or a separate derivation), keeping the duplication boundary sharp.
4. **Unit-per-ship memory model.** `create_party` allocates one `struct unit` per individual ship (`UINT64_MAX / sizeof(struct unit)` cap) — fine for sims but a memory cliff for the huge fleets in `example-battle.php` (250k battleships × several kinds), and C has no per-run time bound (PHP/Python rely on caller-side timeouts).
5. **`float` precision.** Damage/shield/hull are `float`, then truncated to `uint64` for stats — fine for gameplay magnitudes, but slightly lossy for large counts.
6. **Ordering approximation.** Attackers-always-first is an implementation simplification of OGame's per-round model; with enough ships the difference is negligible, but it is not a bit-exact clone of any specific server implementation.

## Confidence

**High** for architecture, data model, and all combat formulas — I read the complete C source (`BattleEngine.c`), both wrappers, and both catalogues directly from raw GitHub; the `fire()`, `fight()`, `create_party()`, `restore_shields()`, and `update_units()` functions were retrieved in full with their exact formulas and thresholds. **High** for the absence of debris/moon/loot/anti-detection — the entire file listing is small and enumerated, and no such code exists. **Medium** for the claim that this exactly matches a given OGame server's rounding/ordering (it is a faithful reference implementation, not a byte-for-byte port of any specific server).