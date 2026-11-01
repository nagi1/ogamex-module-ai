//! ogsim: OGame's economy as equations in time, played from one event to the next (plan/rl).
//!
//! Every number comes from the host through `ai:rl-catalogue`, so this crate names no object (Modules/AI/AGENTS.md,
//! gate 1). Only the rules that combine the tables live here, and they follow `PlanetService`: production per object
//! at a 100% factor, an energy factor floored to whole percent, totals rounded up, accrual stopped at the store's cap
//! (`updateResourcesUntil`), and a producer that burns deuterium giving no energy on a planet that has run dry
//! (`updateResourceProductionStatsInner`).

/// Hashing with a fixed key: std's `RandomState` keys every process differently, and a map iterated into a float sum or a
/// tie-break then plays one seed two ways (29 Oct: some reruns of a season diverged on day 2). Every map here uses these.
pub type HashMap<K, V> = std::collections::HashMap<K, V, std::hash::BuildHasherDefault<std::collections::hash_map::DefaultHasher>>;
pub type HashSet<K> = std::collections::HashSet<K, std::hash::BuildHasherDefault<std::collections::hash_map::DefaultHasher>>;

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
/// The errand choice a login makes with its fleet, as candidates a model scores (plan/rl/errand-spec.md).
pub mod errands;
/// The host's highscore over a world: the four scores an account is worth (plan/rl/north-star-plan.md, N1).
pub mod score;
/// The season's universe: the world `examples/season.rs` measures and the binding trains in (W24).
pub mod season;

pub use economy::*;

#[cfg(feature = "python")]
mod python;
