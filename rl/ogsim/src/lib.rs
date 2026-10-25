//! ogsim: OGame's economy as equations in time, played from one event to the next (plan/rl).
//!
//! Every number comes from the host through `ai:rl-catalogue`, so this crate names no object (Modules/AI/AGENTS.md,
//! gate 1). Only the rules that combine the tables live here, and they follow `PlanetService`: production per object
//! at a 100% factor, an energy factor floored to whole percent, totals rounded up, accrual stopped at the store's cap
//! (`updateResourcesUntil`), and a producer that burns deuterium giving no energy on a planet that has run dry
//! (`updateResourceProductionStatsInner`).

pub mod economy;
/// Shipyard: ships and defence built on a planet (world sim, plan/rl/world-sim-spec.md).
pub mod units;
/// Fleets between coordinates: distance, flight time and fuel as the host computes them.
pub mod fleet;
/// Battles through the host's own Rust battle engine, with loot, debris and moons as the host applies them.
pub mod combat;
/// Many accounts in one universe on one clock: missions, arrivals and the events that join the modules.
pub mod world;
/// Expedition outcomes.
pub mod expedition;
/// Missile strikes and moon destruction.
pub mod strike;
/// Scripted human-like players on the world: miner, raider, fleet saver.
pub mod players;

pub use economy::*;

#[cfg(feature = "python")]
mod python;
