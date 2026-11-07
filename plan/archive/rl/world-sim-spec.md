# The whole game as a fast simulator (owner direction, 25 October 2026)

OGame is equations of time plus a persisted state. `rl/ogsim` already holds one planet's economy with both queues, exact
against the host (`economy.rs`). This spec makes it the whole universe, so training and evaluation run in the simulator
and the PHP world is used only to check that the simulator still matches it.

## Rules every module follows

- **Gate 1:** no object, mission number or formula constant typed in Rust. The host exports its answers as tables
  (an artisan command per module, the pattern of `ai:rl-catalogue` and `ai:rl-combat-catalogue`) and Rust combines them.
  A closed formula the host computes (distance, flight time) is still checked against the host on many cases.
- **Parity before use:** each module ships a check against the host: a PHP command that computes N host answers on
  random inputs into JSON, and a Rust test (or `python -m ogrl.econ`-style check) that reproduces every one exactly.
  Integer-rounded host values must match to the unit.
- **Speed:** a module's per-event cost is microseconds; no allocation in the hot path that a reused buffer avoids.
- **Ownership:** each module is its own file (`units.rs`, `fleet.rs`, `combat.rs`, `world.rs`); only its owner edits
  it. `economy.rs` and `python.rs` belong to the lead.

## Modules

| Module | State it owns | Host source to read | Parity check |
| --- | --- | --- | --- |
| `units` | ships and defence per planet, the shipyard queue | `ObjectService` prices, `PlanetService::getUnitConstructionTime` (shipyard and nanite levels), queue rules in the unit queue service | a unit order replayed on the host planet gives the same finish seconds and counts |
| `fleet` | nothing persistent: pure functions | `FleetMissionService` / the fleet controller: distance (galaxy, system, position, donut settings), speed with drives, flight duration with fleet speed setting and speed percent, deuterium consumption, holding time | 10 000 random cases (coordinates, fleets, research, speed percent, universe settings) equal to the host |
| `combat` | nothing persistent | the host's Rust battle engine crate (`/rust/battle_engine_*`) linked as a library; loot, debris, moon chance as `AttackMission` and the engine apply them | the same battle input as the host builds, the same seed, the same rounds, losses, loot and debris |
| `world` | accounts, planets (each an `economy` planet plus `units`), fleets in flight, one event queue | mission rules: transport, deploy, colonise, espionage, attack, recycle, expedition, missile, moon destruction | a recorded PHP world replayed event by event in the simulator ends in the same planets, fleets and scores |

## Order

1. In parallel: `units`, `fleet`, `combat` (independent), each with its export command and parity check.
2. `world`: the event loop over many accounts that calls them, missions one by one in the order the planner uses them
   (transport, espionage, attack, recycle, colonise, then the rest), each with a parity replay.
3. Policies run on `world` with logins, all accounts at once (self-play); PHP closed loops become a rare final check.
