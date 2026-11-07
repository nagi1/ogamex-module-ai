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

/// FxHash: a fixed multiply-rotate hash, much cheaper than SipHash on integer keys. Only for maps that are looked up and
/// never iterated (research levels by id, unit tables by id), so their order can reach no result.
#[derive(Clone, Copy, Default)]
pub struct FxHasher(u64);

impl FxHasher {
    #[inline]
    fn add(&mut self, word: u64) {
        self.0 = (self.0.rotate_left(5) ^ word).wrapping_mul(0x51_7c_c1_b7_27_22_0a_95);
    }
}

impl std::hash::Hasher for FxHasher {
    #[inline]
    fn write(&mut self, bytes: &[u8]) {
        for chunk in bytes.chunks(8) {
            let mut word = [0u8; 8];
            word[..chunk.len()].copy_from_slice(chunk);
            self.add(u64::from_le_bytes(word));
        }
    }
    #[inline]
    fn write_u8(&mut self, n: u8) { self.add(n as u64) }
    #[inline]
    fn write_u16(&mut self, n: u16) { self.add(n as u64) }
    #[inline]
    fn write_u32(&mut self, n: u32) { self.add(n as u64) }
    #[inline]
    fn write_u64(&mut self, n: u64) { self.add(n) }
    #[inline]
    fn write_usize(&mut self, n: usize) { self.add(n as u64) }
    #[inline]
    fn finish(&self) -> u64 { self.0 }
}

/// A map only looked up, never iterated (`FxHasher`).
pub type LookupMap<K, V> = std::collections::HashMap<K, V, std::hash::BuildHasherDefault<FxHasher>>;
/// Research levels by host id, as the fleet and combat tables read them.
pub type Levels = LookupMap<u32, u32>;

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
/// Social gauges (arm `social`): alliance structure, dominance and the war graph, measured at a season's end.
pub mod social;
/// Alliance diplomacy (arm `diplomacy`): grudges, wars, pacts, betrayal and coalitions, decided daily from visible data.
pub mod diplomacy;
/// Social persona (arm `persona`): 12 traits per account from social-persona.yaml, drifting with experience.
pub mod persona;
/// Living minds (arm `minds`): per-account social state the cognition engines update through the gateway.
pub mod mind;
/// The season's universe: the world `examples/season.rs` measures and the binding trains in (W24).
pub mod season;
/// The personas' tunable decision numbers (`params=`, `params-es=`).
pub mod tuning;

pub use economy::*;

#[cfg(feature = "python")]
mod python;
