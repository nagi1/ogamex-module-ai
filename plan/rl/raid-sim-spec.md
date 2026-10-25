# Raid simulator spec (M8 groundwork, 25 October 2026)

Phase M8 of [next-steps.md](next-steps.md): raid targets and fleet size, learned in `rl/ogsim`. This page fixes the
inputs, the fight, and the decision; the simulator itself is the next row.

## Inputs: the combat catalogue

`php artisan ai:rl-combat-catalogue {universe} {out} [--player=] [--max-level=20]`
(`ExportAiCombatCatalogueAction`, proven by `tests/Feature/CombatCatalogueExportTest.php`). For every host ship and
defence: `price` [m,c,d], `rapidfire` {target id: n}, `fuel` (the raw value `calculateConsumption` reads), and
`properties` `structural_integrity`, `shield`, `attack`, `capacity`, `speed`, each `{by: [research ids], values}` with
`values` over every combination of the `by` levels 0..max (first id slowest). `by` is found by asking the host, so
the sim names no unit and no research. `--max-level` must reach the highest drive threshold (recycler: impulse 17).
`rules`: loot %, debris % from ships/defence, deuterium in debris, defence repair rate, max moon chance, war fleet
speed, deuterium multiplier. Values are the attacking account's (class bonuses included); the General's +2 combat
levels are not in the host's unit values (only in the report), so the sim does not add them either.

## The fight: reuse the host's Rust engine, do not port it

`rust/battle_engine_ffi` is `crate-type = ["cdylib", "rlib"]`, so ogsim takes it as a path dependency
(`battle_engine_ffi = { path = "../../../../rust/battle_engine_ffi" }`). Probed from a scratch crate: it links, and a
50-vs-10 fight through `fight_battle_rounds` (JSON in, JSON out, `free_battle_result`) costs ~9.5 µs. `BattleInput`
is `pub` + `Deserialize` but its fields and `process_battle_rounds` are private: v1 calls the extern function; if
profiling shows JSON dominates, ask the owner for one host change (`pub fn process_battle_rounds`, pub fields).

Input per side, exactly as `RustBattleEngine::prepareBattleInput` builds it: fleets
`{fleet_mission_id, owner_id, units: {id: {unit_id, amount, attack_power, shield_points, hull_plating, rapidfire}}}`,
`hull_plating = floor(structural_integrity / 10)`, attacker values at the attacker's research, defender's at the
defender's, plus `seed`. Output: `rounds[]`; the last round's `attacker_ships` / `defender_ships` are the survivors.
Parity: the sim builds the same JSON the host builds for one fight, so the same seed gives the same rounds. The
parity row compares that JSON with the host's for sampled fights (a container with FFI), not outcomes.
The PHP engine draws from `mt_rand`, so it agrees with Rust in distribution, never per seed.

## After the fight, as `BattleEngine::simulateBattle` applies it

- **Loot** only when the defender has 0 units left: `loot% x` each resource (`max(0, r)`), capped by the attackers'
  *starting* cargo capacity through `LootService::distributeLoot` (even thirds, then refill the unfilled), then capped
  again by the *surviving* cargo capacity minus cargo already carried (`distributeResources`).
- **Debris**: `floor(price x lost x %/100)` per resource, ships at the ships %, defences at the defence %, deuterium
  only if on. Defences repaired (`DefenseRepairService`, rate, Engineer halves losses) are not lost and leave no debris.
- **Moon** (only when none exists): `min(floor(debris sum / 100000), max)` %.
- Wreck fields, tactical retreat, Reaper debris pickup and ACS: out of scope for v1 (inactive targets, one fleet).
- **Flight**: `FleetMissionService::calculateFleetMissionDistance`, `calculateFleetMissionDuration` (slowest
  `speed`, war fleet speed) and `calculateConsumption`; ogsim restates these three and a parity row checks them
  against the host on sampled origin/target pairs before training.

## The decision

State: home position, own fleet stock and research cells, and the account's espionage reports (age, position,
resources, ships, defences) for inactive or weak planets, seeded from the `PUB-*` planet positions. Action, once per
free fleet slot: one report or *wait*, and a fleet size: cargo hulls for `k x` the report's plunder (`k` in
{1, 1.5, 2}) plus the cheapest combat hulls that win against its defenders (as `RaidPlanner::launchUnits` does), or
probes only. Reward: loot + (later) recycled debris - lost units at price - fuel, per hour. Rules kept from the
host and the planner: at most 6 attacks per target per day, stale reports refused. Baseline to beat: `RaidPlanner`
(lower-tail profit > 0, loot tiers); the exit gates are the four in next-steps.md.
