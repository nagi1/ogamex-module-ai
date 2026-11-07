## Overview

`maximalcode/ogame-combat-sim` is a stateless OGame fleet-combat **simulator** (not a bot, not a game client) written in Rust, MIT-licensed, self-described as "an independent reimplementation" validated against the now-offline TrashSim (Klaas). It models OGame combat resolution: up to 6 rounds with shield regeneration, the full rapid-fire cross-table with chained re-rolls, the 1%-shield bounce rule, hull-integrity explosion rolls, player/alliance class levels, per-ship-type lifeform bonuses, debris/loot/profit economics, downscaling for >10M-ship battles, and v13's "instant calculation" rule.

- **Workspace** (`Cargo.toml`, resolver 3, edition 2024, rust-version 1.85): `combat-types` (data model + hardcoded tables), `combat-core` (engine), `combat-api` (axum HTTP server), `combat-cli` (clap CLI), `combat-fixtures` (regression-corpus tooling), `combat-ogame-api` (XML metadata client). A TypeScript `frontend/` exists but is described as incomplete ("Rendering the results is still in progress").
- **State**: no releases, 0 stars, 3 contributors (maximalcode, claude, devin-ai bot). Work happens on `develop`; `main` is the reviewed branch.
- **Not modelled** (stated in README): the lifeform *empire* model (which planets/buildings/species produced the bonus — engine takes researched levels or resolved percentages), the General's Light-Fighter-kills-Deathstar perk, defence rebuild (70% free, 85% with Engineer), moon destruction, and fuel cost in profit.

## Architecture & entry points

Six Rust crates plus a web frontend:

| Crate | Role | Key files |
|---|---|---|
| `combat-types` | Data model + static game tables | `src/lib.rs`, `src/entities.rs`, `src/lifeforms.rs`, `src/names.rs`, `src/combat_report.rs` |
| `combat-core` | The engine (round loop, instant calc, scaling, economics) | `src/combat.rs`, `src/simulator.rs`, `src/instant.rs`, `src/scaling.rs`, `src/economics.rs`, `src/entity.rs`, `src/stats.rs`, `src/report_builder.rs` |
| `combat-api` | Stateless HTTP server | `src/main.rs` |
| `combat-cli` | CLI | `src/main.rs`, `src/cli.rs`, `src/args.rs`, `src/render.rs` |
| `combat-ogame-api` | Typed access to OGame's public per-universe XML | `src/client.rs`, `src/lifeforms.rs`, `src/models.rs` |
| `combat-fixtures` | Fixture authoring/validation for the regression corpus | — |

**Entry points:**

- **Library**: `Combat::new()` (`combat-core/src/combat.rs`) → `simulate_single()` (normal path, `InstantCalculation::Applied`), `simulate_single_with_slots()` (ACS A1/A2/D1/D2), and `simulate_single_through_the_rounds()` (`InstantCalculation::Skipped`, test-only equivalence path). All three funnel into `fn resolve(...)`. The high-level entry is `Simulator::new().simulate_multiple(&CombatRequest)` (`combat-core/src/simulator.rs`).
- **HTTP**: `#[tokio::main] async fn main()` in `combat-api/src/main.rs` — routes `GET /` (liveness, `"combat-api <version> — ready"`) and `POST /api/simulate`. Binds `0.0.0.0:PORT`.
- **CLI**: `fn main() -> ExitCode` in `combat-cli/src/main.rs` → `run(Command)` with subcommands `sim`, `entities`, `fixture {template|check|run}`.
- **Report**: `ReportBuilder::new().build_summary_report(request, results)` → `CombatReport`.

The engine is pure: "A simulation is a pure function of its input — so there is nothing to install, migrate or back up."

## Scheduling & loop model

**There is no scheduler, daemon, background task, or game-facing loop.** The only loops are:

1. **The Monte Carlo round loop** inside `Combat::resolve` (`combat-core/src/combat.rs`):
   ```rust
   while round < MAX_ROUNDS && attackers.remaining_count() > 0 && defenders.remaining_count() > 0
   ```
   Each iteration: `round += 1`; attackers shoot (`Party::shoot_at`); defenders shoot; `regenerate_shields()` both sides; `remove_destroyed()` both sides. `MAX_ROUNDS` is `pub(crate) const MAX_ROUNDS: u8 = 6`. Dead entities still fire in the round they died (removal happens at end of round).
2. **The parallel simulation fan-out** in `Simulator::simulate_multiple` (`combat-core/src/simulator.rs`):
   ```rust
   (0..request.simulations).into_par_iter().map(|_| ...).collect()
   ```
   using `rayon`. `Simulator::new()` builds a **global** Rayon pool at `((num_cpus * 3) / 4).max(1)` threads, named `combat-sim-{i}`.

Each simulation seeds a `SmallRng` from the thread-local generator (`rand::rng()`), explicitly not `from_os_rng`, since battles need no cryptographic randomness. `combat-ogame-api` has a `tokio` client with a 1-request-per-second rate limiter and disk cache, but it is a pull-based metadata fetcher, not a loop.

## Decision engine & algorithms

**Damage application** — `fn apply_damage_fast(weapon_power: u32, target: &mut Entity, rng)` (`combat-core/src/combat.rs`). Damage is **exact — no randomization**:
- If `attack_power < target.max_shield`: compute `damage_percentage = (attack_power / max_shield * 100.0).floor()`. If `>= 1.0`, subtract `damage_percentage/100 * max_shield` from shield; **else the shot bounces** (absorbed entirely, `attack_power = 0.0`). This is the 1% shield-bounce rule.
- Else if `current_shield > 0`: `attack_power -= current_shield`; set `current_shield = -1.0` (destroyed marker).
- If `attack_power > 0`: subtract from hull; `hull <= 0.0` → `destroy()`; else `check_explosion(rng)`.

**Explosion roll** — `Entity::check_explosion` (`combat-core/src/entity.rs`): only when `hull_percentage <= 0.7`, with `explosion_chance = 1.0 - hull_percentage`; destroy if `rng < chance`.

**Rapid fire** — `Party::shoot_at` (`combat-core/src/combat.rs`): each armed entity fires at least once at a uniformly random living target; `continue_probability = 1.0 - (1.0 / rf_value)`, re-roll per shot (chained). `rapid_fire_against` is looked up once per attacker type (hoisted out of the shot loop).

**Stat scaling** — `ModifiedStats::calculate` (`combat-core/src/stats.rs`):
```rust
weapon_modifier = 1.0 + (tech.weapon * 0.1) + (lifeform.weapon / 100.0);
modified_weapon = (base_weapon * weapon_modifier).floor();
modified_shield = (base_shield * shield_modifier).floor();
modified_hull = ((base_armour * armour_modifier) * 0.1).floor();
```
Technology (+10%/level) and lifeform percentage are **additive terms of one sum**, not compounded. Hull = armour × 0.1.

**Class levels** — `Technology::effective_levels` (`combat-types/src/lib.rs`): `GENERAL_COMBAT_LEVELS = 2`, `WARRIOR_COMBAT_LEVELS = 1`, added to each of weapon/shield/armour, **saturating** (`u8`), stack to max +3.

**Instant calculation (v13)** — `combat-core/src/instant.rs` (see Discrete mechanisms M13–M17 for the full five-condition rule).

**Downscaling** — `combat-core/src/scaling.rs` (see M18–M20).

**Economics** — `combat-core/src/economics.rs` and `combat-types/src/combat_report.rs` (see M21–M28).

## Data model & persistence

**No database, no persistence, no state between requests.** `combat-ogame-api` is the only component that persists anything: a disk cache (`cache_dir/<universe>/<file>`) with per-endpoint TTLs (serverData/players daily, universe/playerData weekly, highscore hourly), freshness checked against both source timestamp and file mtime with `CLOCK_SKEW = 5 min`.

Core types (`combat-types/src/lib.rs`):
- `pub type EntityType = u16` — ships 202–219, defences 401–408, missiles 502/503.
- `pub type FleetComposition = HashMap<EntityType, u32>`.
- `EntityStats { entity_type, weapon: u32, shield: u32, armour: u32, rapid_fire_from, rapid_fire_against, cost_metal, cost_crystal, cost_deuterium, cargo_capacity, base_speed, fuel_consumption }`.
- `Technology { weapon, shield, armour: u8, combustion/impulse/hyperspace/hyperspace_tech: Option<u8> }` (drive fields carried, read by no combat code).
- `PartyData { technology, entities, lifeform: LifeformBonuses }`.
- `CombatRequest { attacker, defender, attacker_slots, defender_slots, planet_resources, debris_percentage: f32 (=30.0), use_rapid_fire, simulations, enable_downscaling: Option<bool>, enable_round_compositions, universe_settings, attacker_bonuses, defender_bonuses, plunder_percentage: u8 (=50) }` — `Default` is hand-written to match serde defaults (verified by `default_matches_deserializing_a_minimal_request`).
- `PlayerBonuses { player_class, alliance_class, has_engineer: bool }` — `has_engineer` is "read by nothing" (documented; its effect is on post-battle rebuild, which isn't modelled).
- `CombatResults { simulations, attacker_wins, defender_wins, draws, results: Vec<SimulationResult>, duration_ms, average_rounds, debris_settings }`.

The entity stat table is built once per process via `static ENTITY_STATS: LazyLock<HashMap<...>> = LazyLock::new(build_entity_stats)` in `combat-types/src/entities.rs`; `entity_stats()` returns the shared reference.

## Config surface

**Environment (server only)** — `combat-api/src/main.rs`: `PORT` (default 3000), `MAX_SIMULATIONS` (default `DEFAULT_MAX_SIMULATIONS = 1000`, server protection only).

**JSON request** — `CombatRequest` deserializes the same body for `POST /api/simulate`, the library, and `combat-cli sim --file`. Debris precedence: `universe_settings` **wins** over top-level `debris_percentage` (`CombatRequest::debris_settings`).

**`UniverseSettings`** defaults (`combat-types/src/lib.rs`): `galaxies: 9`, `systems: 499`, `donut_galaxy/donut_systems: true`, `fleet_speed: 1`, `debris_fleet: 30`, `debris_defence: 0`, `debris_deuterium: false`, `deuterium_save_factor: 0`.

**CLI flags** (`combat-cli/src/cli.rs`, `SimArgs`): `-a/--attacker`, `-d/--defender`, `--tech`, `--attacker-tech`, `--defender-tech`, `-n/--simulations` (uncapped), `--no-rapid-fire`, `--debris` (0–100 float), `--plunder` (0–100 u8), `--planet M,C,D`, `--downscaling auto|on|off`, `--rounds`, `-f/--file`. `--file` conflicts with all shorthand flags.

**Classes** (`combat-types/src/lib.rs`): `PlayerClass { None, Collector, General, Discoverer }`, `AllianceClass { None, Trader, Warrior, Researcher }` — only General/Warrior grant combat levels.

## Edge cases & failure handling

- **Bounce**: shots `< 1%` of shield are absorbed entirely (`apply_damage_fast`); the instant-calc predicate `shot_registers` mirrors the exact `f32` expression `(shot / shield * 100.0).floor() >= 1.0` to stay in agreement with the damage rule, including the `0.0/0.0` and divide-by-zero degenerate cases.
- **Division by zero** in instant calc is avoided by writing the ratio as multiplication: `self.attack_power <= INSTANT_CALCULATION_RATIO * loser.attack_power` (0 vs 0 fails, correctly → simulated).
- **Saturating arithmetic everywhere**: `effective_levels` (`u8` levels can't wrap to 0), `debris_percentage.clamp(0.0, 100.0)`, `saturating_mul` in upscaling, `saturating_sub` in losses/remaining.
- **Validation** (`combat-cli/src/cli.rs::validate`): rejects both-empty fleets, `simulations == 0`, and **unknown entity ids** (including inside slots) with a deterministic message. The HTTP handler (`combat-api/src/main.rs::simulate`) rejects empty fleets and `simulations == 0`, caps `simulations`, and forces `enable_downscaling = None` — but does **not** reject unknown entity ids (they are silently skipped by `Party::new`; the CLI closes this gap, the API does not).
- **Instant calc conservatism**: the short-circuit is deliberately *narrower* than the game's rule — if any of the five conditions fails, the battle is fought round-by-round ("slower and right").
- **Downscaling precision**: `downscale_fleet` keeps `max(1)` per type; `upscale_result_with_originals`/`restore_precision_fleet` restore original counts where a type took no scaled losses.
- **Metadata client** (`combat-ogame-api/src/client.rs`): `StaleResponse` if source timestamp is too old, `CLOCK_SKEW = 5*60s` tolerance, process-wide 1 req/s limiter keyed by host, atomic temp-file-then-rename cache writes, and `validate_contact` requires an `https://` or `mailto:` contact in the User-Agent.
- **CLI parsing** (`combat-cli/src/args.rs`): an input not understood is an error, never a skip (`-a "cruser:100"` fails loudly); `serde_path_to_error` names the offending JSON field.

## Anti-detection & authenticity

**None.** This is a pure offline simulator and HTTP utility; it performs no game logins, sends no commands to OGame, and has no bot behaviour, sleep scheduling, or humanization. The only network component, `combat-ogame-api`, fetches **public** XML metadata with good-citizen hygiene: a 1 req/s per-host rate limiter, disk caching, and a mandatory contact address in the User-Agent — that is politeness toward Gameforge, not anti-detection.

## Discrete mechanisms

- M01 — Battles last at most **6 rounds** — `pub(crate) const MAX_ROUNDS: u8 = 6` (`combat-core/src/combat.rs`).
- M02 — Round loop runs while `round < MAX_ROUNDS && attackers.remaining_count() > 0 && defenders.remaining_count() > 0` (`combat.rs::resolve`).
- M03 — Entities killed mid-round **still shoot** that round; dead removed at end of round (`combat.rs::shoot_at` + `remove_destroyed`).
- M04 — Shields regenerate to full between rounds — `Entity::regenerate_shield` sets `current_shield = max_shield` (`combat-core/src/entity.rs`).
- M05 — Shots under **1% of max shield bounce** (absorbed entirely) — `damage_percentage = floor(attack_power / max_shield * 100.0)`; `< 1.0` ⇒ no damage (`combat.rs::apply_damage_fast`).
- M06 — Damage is **exact** (no random component) (`combat.rs::apply_damage_fast`).
- M07 — Shield break sets `current_shield = -1.0` as destroyed marker (`combat.rs::apply_damage_fast`).
- M08 — Explosion roll only when hull ≤ **70%**, chance = `1.0 - current_hull/max_hull` (`entity.rs::check_explosion`).
- M09 — Rapid fire continues with probability `1.0 - 1.0/rf_value`, re-rolled per shot (chained) (`combat.rs::shoot_at`).
- M10 — Stat modifier = `1.0 + 0.10*tech_level + lifeform_pct/100`, applied once (additive, not compounded); weapon/shield floored, hull = armour×0.1 floored (`combat-core/src/stats.rs::ModifiedStats::calculate`).
- M11 — General +2, Warrior alliance +1, to each of weapon/shield/armour; stack to +3; saturating `u8` (`combat-types/src/lib.rs::effective_levels`).
- M12 — Instant-calc gate: strict `attack_power > 10_000.0 × loser.attack_power` — `INSTANT_CALCULATION_RATIO = 10_000.0` (`combat-core/src/instant.rs`).
- M13 — Instant-calc condition 2: loser's `strongest_shot` must **not** register on winner's `smallest_shield` (winner loses nothing) (`instant.rs::annihilates`).
- M14 — Instant-calc condition 3: winner's `weakest_armed_shot` must register on loser's `largest_shield` (bounce can't save any type) (`instant.rs`).
- M15 — Instant-calc condition 4: `attack_power >= WIPE_CERTAINTY_MARGIN × loser.hitpoints`, where `WIPE_CERTAINTY_MARGIN = INSTANT_CALCULATION_RATIO = 10_000.0` (`instant.rs`).
- M16 — Instant-calc condition 5: `MAX_ROUNDS × armed_units >= 10_000.0 × loser.units` (shot budget; rapid fire not counted) (`instant.rs`).
- M17 — Instant calc wipes the losing `Party` via `annihilate()` (clears entities), leaving the ordinary loop to assemble the result with `rounds = 0` (`instant.rs::apply` + `combat.rs::annihilate`).
- M18 — Downscaling threshold **10M total ships** — `DOWNSCALE_THRESHOLD = 10_000_000` (`combat-core/src/scaling.rs`).
- M19 — Downscale factor: `>100M → 100`, `≥50M → 50`, `≥10M → 10`, else 1 (`scaling.rs::calculate_downscale_factor`).
- M20 — `downscale_fleet` divides counts by factor with `.max(1)` (keep ≥1 of each type); technology/lifeform modifiers pass through unscaled (`scaling.rs`).
- M21 — Debris: `cost × (fleet|defence)_percentage/100 × count`; ship ids `< 400`, defences `400..500` (`const DEFENCE_IDS: Range<EntityType> = 400..500`); defence debris only counts for the defending side (`combat-core/src/economics.rs::calculate_debris`).
- M22 — Deuterium debris only when `settings.deuterium` (universe option, v9.2) (`economics.rs`).
- M23 — Loot: plunder factor `plunder_percentage/100` per resource; if over cargo capacity, fill **Metal → Crystal → Deuterium** priority (`economics.rs::calculate_loot_extended`).
- M24 — Cargo capacity = Σ `cargo_capacity × count` over surviving attacker fleet (`economics.rs::calculate_cargo_capacity`).
- M25 — Attacker profit = debris.total + loot.total − losses_value; **fuel cost ignored** (TODO) (`economics.rs::calculate_attacker_profit`); defender profit = debris.total − losses_value.
- M26 — Moon chance = `min(20.0, debris_total / 100_000.0)` (`combat-types/src/combat_report.rs::calculate_moon_chance`).
- M27 — Recyclers needed = `ceil(debris_total / 20_000)` (`combat_report.rs::calculate_recyclers_needed`, `RECYCLER_CAPACITY = 20_000`).
- M28 — Harvest time = `trips × 60s` placeholder (`SECONDS_PER_TRIP = 60`) (`combat_report.rs::estimate_harvest_time`).
- M29 — Debris precedence: `universe_settings` wins over top-level `debris_percentage`; fallback is fleet-only 30%, no defence debris, no deuterium (`combat-types/src/lib.rs::debris_settings`).
- M30 — Server cap: `MAX_SIMULATIONS` (default 1000) per HTTP request; CLI/library uncapped (`combat-api/src/main.rs`).
- M31 — Rayon thread pool = `(num_cpus × 3 / 4).max(1)` (`combat-core/src/simulator.rs::new`).
- M32 — Per-battle RNG is `SmallRng` seeded from thread-local `rand::rng()` (no per-battle OS entropy) (`simulator.rs::simulate_once_internal`).
- M33 — Metadata rate limit: ≥1 s between requests to the same host, process-wide (`combat-ogame-api/src/client.rs::wait_for_host`, `REQUEST_INTERVAL = 1s`).
- M34 — Cache freshness allows `CLOCK_SKEW = 5*60s` and checks both source timestamp and file mtime against TTL (`client.rs::cache_is_fresh`).
- M35 — Entity name/alias lookup is case- and punctuation-insensitive; aliases include German shorthands (`kt`, `gt`, `jf`, `sf`, `ss`, `kr`, `zer`, `rip`, `sxer`, `rak`) (`combat-types/src/names.rs`).
- M36 — Numeric tokens that aren't known entities return `None` (never passed through) (`names.rs::resolve`).
- M37 — Lifeform ship research is **0.3%/level**, except Recycler `13208` and Large Cargo `14214` at **1.0%/level** (`combat-types/src/lifeforms.rs::BUILTIN_TECHS`).
- M38 — Defence lifeform `12216` (Obsidian Shield Reinforcement) is **0.5%/level** across all eight defences 401–408 (`lifeforms.rs`).
- M39 — Lifeform bonus resolves as `per_level_percent × level × (1 + boost_percent/100)`; caps are the caller's responsibility (`lifeforms.rs::LifeformTechTable::resolve`).
- M40 — Cost-reduction researches `12217`, `13217`, `14217` are deliberately **excluded** (grant no combat stat) (`lifeforms.rs`).
- M41 — `has_engineer` is carried but read by nothing (documented; no rebuild step exists) (`combat-types/src/lib.rs::PlayerBonuses`).
- M42 — `simulations: 0` and both-fleets-empty are rejected (HTTP 400 / CLI error); unknown ids rejected in CLI, skipped silently in API (`combat-cli/src/cli.rs::validate`, `combat-api/src/main.rs::simulate`).
- M43 — `Classify battle type`: ship ids `202..=219`, defence ids `401..=408`; FleetVsFleet / FleetVsDefense / Mixed (`combat_report.rs::classify_battle_type`).

## Notable concerns

- **Gate-1 (no static hardcoded AI):** This repo is a textbook case of what OGameX's Gate 1 forbids. The **entire object universe is hardcoded**: entity ids, weapon/shield/armour, costs, cargo, speed, fuel (`combat-types/src/entities.rs`), the **full rapid-fire cross-table**, and the **lifeform tech table** (`combat-types/src/lifeforms.rs::BUILTIN_TECHS`). The project itself flags this: the lifeform table "is hardcoded and goes stale whenever Gameforge rebalances." It mitigates *only* lifeforms and universe settings via `combat-ogame-api` reading `serverData.xml` (`ServerDataLifeformTechs`, a second implementation behind the `LifeformTechTable` trait); **entity stats and rapid-fire are not loadable from the host** — you would have to reimplement that loading yourself if adopting.
- **Gate-2 (over-engineering):** Generally lean, but the report model carries several **placeholders that nothing populates**: `MoonDestructionInfo` (always `None`), `estimate_harvest_time` (hardcoded 60s/trip), `Participant.name/coordinates/alliance` (literal `"Attacker"`/`"Defender"`, `None`), and drive-technology fields (`combustion/impulse/hyperspace`) read by no combat code. These are forward-looking scaffolding — dead weight for a pure simulator, though each is documented.
- **API/CLI asymmetry:** the HTTP endpoint does **not** reject unknown entity ids, so `{"entities": {"2014": 30}}` over the API silently produces a different battle than requested; the CLI rejects it. An integration consuming the API should re-validate.
- **Profit is optimistic:** fuel cost is ignored (explicit TODO), and `attacker_profit`/`defender_profit` are alternative scenarios, not a ledger (summing double-counts the field).
- **Downscaling is an approximation** above 10M ships (extrapolation; `.max(1)` can over-represent rare ship types in the scaled-up result). Documented and precision-restored, but not exact.
- **Not modelled:** defence rebuild (70%/85% with Engineer), the General's LF-vs-Deathstar perk (sources conflict, deliberately omitted and "recorded rather than quietly dropped"), moon destruction, and the lifeform *empire* derivation. The README is unusually honest about these — a strong positive for reuse.
- **Gate-3 (human-observable behaviour):** n/a — it performs no gameplay actions. The stochastic Monte Carlo approach and conservative instant-calc are exactly how a careful human planner reasons about OGame combat.
- No auth, TLS, or per-IP rate limiting on `combat-api` beyond the simulations cap and CORS `Any`.

## Confidence

**High.** Findings are based on direct reading of the README plus ~18 source files fetched from `main`: `combat-core/src/{combat,simulator,instant,scaling,economics,entity,stats,report_builder,lib}.rs`, `combat-types/src/{lib,entities,lifeforms,names,combat_report}.rs`, `combat-api/src/main.rs`, `combat-cli/src/{main,cli,args}.rs`, `combat-ogame-api/src/{client,lifeforms}.rs`, and the workspace `Cargo.toml`. All constants, formulas, file paths, and thresholds above are quoted from that source. The one unverified area is the `frontend/` TypeScript app (only the README's summary was read), and the repository's git history/CI internals were not examined in depth.