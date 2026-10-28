//! Shipyard and defence production (owned by the units agent, plan/rl/world-sim-spec.md).
//!
//! Every number comes from the host through `ai:rl-units-catalogue`: prices, requirements, the host's own seconds per
//! unit over a grid of the levels that change them, the caps on how many of a unit a planet may hold and the slots a
//! unit takes in a store, and which objects may not upgrade while the shipyard works. This file names no unit and holds
//! no formula; the rules that combine the tables follow `UnitQueueService::add`, `ObjectService::getObjectMaxBuildAmount`
//! and `PlanetService::updateUnitQueue`.
//!
//! Public API (times are whole host seconds on the caller's clock, `i64`):
//! - `Units::load(path, &economy)` reads the export and binds every object id to the economy catalogue's index, so the
//!   levels of an economy `Planet` (buildings, stations and researches) are the ones the rules read.
//! - `Units::shipyard(config)` is the start state of economy planet `config`: its unit counts and the units its
//!   owner's class may build. `Shipyard::counts[u]` is how many of unit `u` the planet holds.
//! - `Units::index(id)` turns a host unit id into the index `u` every call takes.
//! - `Units::order(&mut yard, &mut planet, u, amount, now, upgrading)` is one shipyard order at `now`: it first hands
//!   over what is due, refuses while `upgrading` (the economy object under construction, if any) is one that sets unit
//!   time, caps the amount as the host does, pays the planet's stock and queues it behind the orders already waiting.
//!   It returns the amount queued (0 when refused). The caller brings `planet.res` up to `now` before calling.
//! - `Units::max_amount(&yard, &planet, u)` is the host's cap on an order right now; `Units::seconds(&planet, u)` the
//!   seconds one unit takes at the planet's levels.
//! - `Units::advance(&mut yard, now)` hands over every unit finished by `now`, exactly as the host does on an update.
//! - `Shipyard::next_landing()` is the second the next unit lands (for the world's event queue); `Shipyard::busy()`
//!   is true while an order is unfinished, and `Units::blocks_upgrade(&yard, k)` says that economy object k may not
//!   start upgrading now.
use crate::economy::{weighted, Catalogue as Economy, Planet};
use serde::Deserialize;
use std::collections::HashMap;

#[derive(Deserialize)]
struct RawCatalogue {
    economy_speed: f64,
    time_grid_level: usize,
    time_modifiers: Vec<i64>,
    blocked_while_upgrading: Vec<i64>,
    stores: Vec<RawStore>,
    units: Vec<RawUnit>,
    planets: Vec<RawPlanet>,
    /// The host's highscore tables (`score`): what it counts for each object level and how it splits each unit.
    score: RawScore,
}

#[derive(Deserialize)]
struct RawScore {
    objects: Vec<RawScoreObject>,
    /// Unit id => the host list it is scored from (`military_ship`, `civil_ship`, `defense`).
    kinds: HashMap<String, String>,
}

#[derive(Deserialize)]
struct RawScoreObject {
    id: i64,
    research: bool,
    /// The host's cost of levels 1..=n, energy excluded (`ObjectService::getObjectCumulativeCost`).
    cumulative: Vec<[f64; 3]>,
}

#[derive(Deserialize)]
struct RawStore {
    id: i64,
    capacity_per_level: u64,
}

#[derive(Deserialize)]
struct RawUnit {
    id: i64,
    price: [f64; 3],
    requirements: Vec<RawRequirement>,
    build_seconds_grid: Vec<i64>,
    owned_limit: Option<u64>,
    store: Option<RawUnitStore>,
    moves_production: bool,
}

#[derive(Deserialize)]
struct RawRequirement {
    id: i64,
    level: u32,
}

#[derive(Deserialize)]
struct RawUnitStore {
    id: i64,
    slots: u64,
}

#[derive(Deserialize)]
struct RawPlanet {
    class_allowed: Vec<i64>,
    units: HashMap<String, u64>,
}

pub struct Unit {
    pub id: i64,
    pub price: [f64; 3],
    /// (economy object index, level); no index means an object the economy does not hold, met only at level 0.
    pub requires: Vec<(Option<usize>, u32)>,
    /// The host's seconds per unit for every combination of modifier levels up to `grid_level`, the first slowest.
    pub seconds: Vec<i64>,
    /// At most this many may be held, counting only built units (queued ones are not counted by the host).
    pub owned_limit: Option<u64>,
    /// (store index, slots one unit takes there).
    pub store: Option<(usize, u64)>,
    /// Holding it changes the planet's production or energy (the host counts it there); the economy module owns that.
    pub moves_production: bool,
}

pub struct Store {
    /// The economy object whose level gives the capacity; none means capacity zero.
    pub object: Option<usize>,
    pub capacity_per_level: u64,
    /// Every unit that takes slots in this store.
    pub members: Vec<usize>,
}

/// Which of the host's three unit lists a unit comes from: the highscore gives a military ship all of its price to the
/// military score, a civil ship half to the military and half to the economy, and a defence all to both.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum UnitKind {
    MilitaryShip,
    CivilShip,
    Defence,
}

/// What the host's highscore counts for one economy object: whether it is a research, and the cost of every level up to
/// the one held.
#[derive(Clone)]
pub struct Cost {
    pub research: bool,
    /// `cumulative[level - 1]` is the cost of levels 1..=level, in [metal, crystal, deuterium].
    pub cumulative: Vec<[f64; 3]>,
}

pub struct Units {
    pub speed: f64,
    pub grid_level: usize,
    /// Economy indices of the objects whose level sets unit time, in the grid's order.
    pub modifiers: Vec<Option<usize>>,
    /// Economy indices of the objects whose upgrade blocks an order, and that cannot upgrade while an order runs.
    pub blocked: Vec<usize>,
    pub stores: Vec<Store>,
    pub units: Vec<Unit>,
    /// Per unit index, the host list the highscore scores it from.
    pub kind: Vec<UnitKind>,
    /// Per economy object index, the highscore's tables (`score::of`); none for an object the export leaves out.
    pub cost: Vec<Option<Cost>>,
    /// Per economy planet config: (units held at the start, whether the owner's class may build each unit).
    pub planets: Vec<(Vec<u64>, Vec<bool>)>,
    index: HashMap<i64, usize>,
}

#[derive(Clone, Debug)]
pub struct Order {
    pub unit: usize,
    pub amount: u64,
    pub done: u64,
    pub start: i64,
    pub end: i64,
    /// Seconds per unit, fixed when the order was placed.
    pub each: i64,
}

#[derive(Clone, Debug)]
pub struct Shipyard {
    pub counts: Vec<u64>,
    pub allowed: Vec<bool>,
    /// Unfinished orders in queue order; each starts when the one before it ends.
    pub orders: Vec<Order>,
}

impl Shipyard {
    pub fn busy(&self) -> bool {
        !self.orders.is_empty()
    }

    pub fn next_landing(&self) -> Option<i64> {
        self.orders.first().map(|o| (o.start + o.each * (o.done as i64 + 1)).min(o.end))
    }

    /// Ordered but not yet finished orders count in full for a store, units already handed over included
    /// (`getObjectMaxBuildAmount` sums `object_amount` of every unprocessed row besides the units held).
    fn queued(&self, unit: usize) -> u64 {
        self.orders.iter().filter(|o| o.unit == unit).map(|o| o.amount).sum()
    }
}

impl Units {
    pub fn load(path: &str, economy: &Economy) -> Result<Units, String> {
        let text = std::fs::read_to_string(path).map_err(|e| format!("{path}: {e}"))?;
        let raw: RawCatalogue = serde_json::from_str(&text).map_err(|e| format!("{path}: {e}"))?;
        let object: HashMap<i64, usize> = economy.objects.iter().enumerate().map(|(k, o)| (o.id, k)).collect();
        let index: HashMap<i64, usize> = raw.units.iter().enumerate().map(|(u, o)| (o.id, u)).collect();
        let store_index: HashMap<i64, usize> = raw.stores.iter().enumerate().map(|(s, o)| (o.id, s)).collect();
        let units: Vec<Unit> = raw.units.iter().map(|u| Unit {
            id: u.id,
            price: u.price,
            requires: u.requirements.iter().map(|r| (object.get(&r.id).copied(), r.level)).collect(),
            seconds: u.build_seconds_grid.clone(),
            owned_limit: u.owned_limit,
            store: u.store.as_ref().and_then(|s| store_index.get(&s.id).map(|&i| (i, s.slots))),
            moves_production: u.moves_production,
        }).collect();
        let stores = raw.stores.iter().enumerate().map(|(s, o)| Store {
            object: object.get(&o.id).copied(),
            capacity_per_level: o.capacity_per_level,
            members: (0..units.len()).filter(|&u| units[u].store.is_some_and(|(i, _)| i == s)).collect(),
        }).collect();
        let planets = raw.planets.iter().map(|p| {
            let counts = units.iter().map(|u| p.units.get(&u.id.to_string()).copied().unwrap_or(0)).collect();
            let allowed = units.iter().map(|u| p.class_allowed.contains(&u.id)).collect();
            (counts, allowed)
        }).collect();
        let mut kind = Vec::with_capacity(units.len());
        for u in &units {
            kind.push(match raw.score.kinds.get(&u.id.to_string()).map(String::as_str) {
                Some("military_ship") => UnitKind::MilitaryShip,
                Some("civil_ship") => UnitKind::CivilShip,
                Some("defense") => UnitKind::Defence,
                other => return Err(format!("{path}: unit {} has kind {other:?}; re-export it (ai:rl-units-catalogue)", u.id)),
            });
        }
        let scored: HashMap<i64, &RawScoreObject> = raw.score.objects.iter().map(|o| (o.id, o)).collect();
        let cost = economy.objects.iter().map(|o| scored.get(&o.id).map(|s| Cost { research: s.research, cumulative: s.cumulative.clone() })).collect();
        Ok(Units {
            speed: raw.economy_speed,
            grid_level: raw.time_grid_level,
            modifiers: raw.time_modifiers.iter().map(|id| object.get(id).copied()).collect(),
            blocked: raw.blocked_while_upgrading.iter().filter_map(|id| object.get(id).copied()).collect(),
            stores,
            units,
            kind,
            cost,
            planets,
            index,
        })
    }

    pub fn index(&self, id: i64) -> Option<usize> {
        self.index.get(&id).copied()
    }

    pub fn shipyard(&self, config: usize) -> Shipyard {
        let (counts, allowed) = &self.planets[config];
        Shipyard { counts: counts.clone(), allowed: allowed.clone(), orders: Vec::new() }
    }

    /// Seconds per unit at the planet's modifier levels. A level past the grid reads the grid's edge, which is outside
    /// what the host answered; the export's `--grid-level` sets how far that is.
    pub fn seconds(&self, planet: &Planet, u: usize) -> i64 {
        let side = self.grid_level + 1;
        let cell = self.modifiers.iter().fold(0usize, |at, k| at * side + k.map_or(0, |k| (planet.levels[k] as usize).min(self.grid_level)));
        self.units[u].seconds[cell]
    }

    pub fn requirements_met(&self, planet: &Planet, u: usize) -> bool {
        self.units[u].requires.iter().all(|&(k, level)| k.map_or(level == 0, |k| planet.levels[k] >= level))
    }

    /// `ObjectService::getObjectMaxBuildAmount` with the planet's stock as it is now.
    pub fn max_amount(&self, yard: &Shipyard, planet: &Planet, u: usize) -> u64 {
        let unit = &self.units[u];
        if !self.requirements_met(planet, u) {
            return 0;
        }
        // The host answers an owned limit before it looks at the price; the payment then fails whole or not at all.
        if let Some(limit) = unit.owned_limit {
            return limit.saturating_sub(yard.counts[u]);
        }
        let mut bounds: Vec<f64> = Vec::with_capacity(4);
        if let Some((s, slots)) = unit.store {
            let store = &self.stores[s];
            let level = store.object.map_or(0, |k| planet.levels[k]) as i64;
            let used: i64 = store.members.iter().map(|&v| (yard.counts[v] + yard.queued(v)) as i64 * self.units[v].store.map_or(0, |(_, n)| n) as i64).sum();
            let free = (store.capacity_per_level as i64 * level - used).div_euclid(slots.max(1) as i64);
            if free <= 0 {
                return 0;
            }
            bounds.push(free as f64);
        }
        for r in 0..3 {
            if unit.price[r] > 0.0 {
                bounds.push((planet.res[r] / unit.price[r]).floor());
            }
        }
        bounds.into_iter().reduce(f64::min).map_or(0, |m| m.max(0.0) as u64)
    }

    /// `UnitQueueService::add` behind the shipyard controller's check, after the update that comes first.
    pub fn order(&self, yard: &mut Shipyard, planet: &mut Planet, u: usize, amount: u64, now: i64, upgrading: Option<usize>) -> u64 {
        self.advance(yard, now);
        if amount < 1 || upgrading.is_some_and(|k| self.blocked.contains(&k)) || !yard.allowed[u] {
            return 0;
        }
        let amount = amount.min(self.max_amount(yard, planet, u));
        if amount < 1 {
            return 0;
        }
        let total = self.units[u].price.map(|p| p * amount as f64);
        // deductResourcesAtomic: every priced resource must cover the whole order, or nothing is queued.
        if (0..3).any(|r| total[r] > 0.0 && planet.res[r] < total[r]) {
            return 0;
        }
        for r in 0..3 {
            planet.res[r] -= total[r];
        }
        // Row 23: a yard order is its own flow, so an account spending on ships and defence is visible as such.
        planet.books.spent_units += weighted(total);
        let each = self.seconds(planet, u);
        let start = yard.orders.last().map_or(now, |o| o.end);
        yard.orders.push(Order { unit: u, amount, done: 0, start, end: start + each * amount as i64, each });
        amount
    }

    /// `PlanetService::updateUnitQueue` at `now`: an order hands over a unit every `each` seconds from its start, and
    /// whatever is left the moment it ends.
    pub fn advance(&self, yard: &mut Shipyard, now: i64) {
        for o in yard.orders.iter_mut().filter(|o| o.start <= now) {
            let due = if now >= o.end { o.amount } else { (((now - o.start) / o.each) as u64).min(o.amount) };
            yard.counts[o.unit] += due - o.done;
            o.done = due;
        }
        yard.orders.retain(|o| o.done < o.amount);
    }

    pub fn blocks_upgrade(&self, yard: &Shipyard, k: usize) -> bool {
        yard.busy() && self.blocked.contains(&k)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::Value;
    use std::path::PathBuf;

    /// Where `ai:rl-units-replay` answers sit beside `econ-<u>.json` (ai:rl-catalogue) and `units-<u>.json`
    /// (ai:rl-units-catalogue) of the same universe file.
    fn parity_dir() -> PathBuf {
        std::env::var("OGSIM_UNITS_PARITY").map(PathBuf::from)
            .unwrap_or_else(|_| PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../../../../storage/rl/ogsim/units-parity"))
    }

    fn close(a: f64, b: f64) -> bool {
        (a - b).abs() <= 1e-6 * a.abs().max(b.abs()).max(1.0)
    }

    /// Plays one host replay and returns (orders, accepted, observations, orders paid from the simulator's own stock)
    /// after asserting every number. Once the planet holds a unit that moves production, the economy alone no longer
    /// predicts the stock, so from then on the host's stock before each order is taken as given.
    fn replay(economy: &Economy, units: &Units, econ: &Value, run: &Value) -> (usize, usize, usize, usize) {
        let planet_id = run["planet_id"].as_i64().unwrap();
        let config = econ["planets"].as_array().unwrap().iter().position(|p| p["planet_id"].as_i64() == Some(planet_id)).unwrap();
        let setup = &run["setup"];
        let mut planet = Planet { config, levels: economy.planets[config].levels.clone(), res: [0.0; 3], rate: [0.0; 3], cap: [0.0; 3], energy_max: 0.0, energy_used: 0.0, factor: 1.0, units: Vec::new(), books: Default::default() };
        for (k, o) in economy.objects.iter().enumerate() {
            if let Some(level) = setup["levels"][o.id.to_string()].as_u64() {
                planet.levels[k] = level as u32;
            }
        }
        for r in 0..3 {
            planet.res[r] = setup["resources"][r].as_f64().unwrap();
        }
        economy.refresh(&mut planet);
        let mut yard = units.shipyard(config);
        for (u, unit) in units.units.iter().enumerate() {
            yard.counts[u] = setup["units"][unit.id.to_string()].as_u64().unwrap_or(0);
        }

        let orders = run["orders"].as_array().unwrap();
        let looks = run["observations"].as_array().unwrap();
        let mut events: Vec<(i64, usize, usize)> = orders.iter().enumerate().map(|(i, o)| (o["at"].as_i64().unwrap(), 0, i))
            .chain(looks.iter().enumerate().map(|(i, o)| (o["at"].as_i64().unwrap(), 1, i))).collect();
        events.sort();
        let (mut t, mut accepted, mut own_stock) = (0i64, 0usize, 0usize);
        for (at, kind, i) in events {
            economy.accrue(&mut planet, (at - t) as f64);
            economy.refresh(&mut planet);
            t = at;
            if kind == 1 {
                units.advance(&mut yard, at);
                for (u, unit) in units.units.iter().enumerate() {
                    let host = looks[i]["units"][unit.id.to_string()].as_u64().unwrap();
                    assert_eq!(yard.counts[u], host, "planet {planet_id} second {at}: unit {} held", unit.id);
                }
                continue;
            }
            let o = &orders[i];
            units.advance(&mut yard, at);
            let coupled = units.units.iter().enumerate().any(|(u, unit)| unit.moves_production && yard.counts[u] > 0);
            for r in 0..3 {
                let host = o["stock"][r].as_f64().unwrap();
                if coupled {
                    planet.res[r] = host;
                    continue;
                }
                assert!(close(planet.res[r], host), "planet {planet_id} second {at}: stock {r} sim {} host {host}", planet.res[r]);
            }
            own_stock += usize::from(!coupled);
            let u = units.index(o["id"].as_i64().unwrap()).unwrap();
            let got = units.order(&mut yard, &mut planet, u, o["amount"].as_u64().unwrap(), at, None);
            assert_eq!(got, o["accepted"].as_u64().unwrap(), "planet {planet_id} second {at}: unit {} accepted", o["id"]);
            if got > 0 {
                accepted += 1;
                let placed = yard.orders.last().unwrap();
                assert_eq!((placed.start, placed.end), (o["start"].as_i64().unwrap(), o["end"].as_i64().unwrap()), "planet {planet_id} second {at}: order times");
            }
        }
        (orders.len(), accepted, looks.len(), own_stock)
    }

    #[test]
    fn host_unit_orders_replay_exactly() {
        let dir = parity_dir();
        let Ok(entries) = std::fs::read_dir(&dir) else {
            eprintln!("units parity: no answers at {}; run ai:rl-units-replay first", dir.display());
            return;
        };
        let mut names: Vec<String> = entries.filter_map(|e| e.ok()?.file_name().into_string().ok())
            .filter_map(|n| n.strip_prefix("replay-")?.strip_suffix(".json").map(str::to_string)).collect();
        names.sort();
        assert!(!names.is_empty(), "no replay-*.json in {}", dir.display());
        for name in names {
            let path = |kind: &str| dir.join(format!("{kind}-{name}.json")).to_string_lossy().into_owned();
            let economy = Economy::load(&path("econ")).unwrap();
            let units = Units::load(&path("units"), &economy).unwrap();
            let econ: Value = serde_json::from_str(&std::fs::read_to_string(path("econ")).unwrap()).unwrap();
            let host: Value = serde_json::from_str(&std::fs::read_to_string(path("replay")).unwrap()).unwrap();
            let runs = host["replays"].as_array().unwrap();
            let totals = runs.iter().map(|run| replay(&economy, &units, &econ, run)).fold((0, 0, 0, 0), |a, b| (a.0 + b.0, a.1 + b.1, a.2 + b.2, a.3 + b.3));
            eprintln!("units parity {name}: {} planet(s), {} order(s) ({} accepted, {} paid from the simulated stock), {} observation(s), all equal to the host", runs.len(), totals.0, totals.1, totals.3, totals.2);
        }
    }

    fn tiny() -> (Units, Planet) {
        let unit = |id, price, limit, store| Unit { id, price, requires: vec![(Some(0), 1)], seconds: vec![10, 5, 7, 3], owned_limit: limit, store, moves_production: false };
        let units = Units {
            speed: 1.0,
            grid_level: 1,
            modifiers: vec![Some(0), Some(1)],
            blocked: vec![0, 1],
            stores: vec![Store { object: Some(2), capacity_per_level: 10, members: vec![2, 3] }],
            units: vec![unit(1, [10.0, 0.0, 0.0], None, None), unit(2, [10.0, 0.0, 0.0], Some(1), None), unit(3, [1.0, 0.0, 0.0], None, Some((0, 1))), unit(4, [1.0, 0.0, 0.0], None, Some((0, 2)))],
            kind: vec![UnitKind::MilitaryShip; 4],
            cost: vec![None; 3],
            planets: vec![(vec![0; 4], vec![true; 4])],
            index: (1..=4).map(|id| (id, id as usize - 1)).collect(),
        };
        let planet = Planet { config: 0, levels: vec![1, 0, 1], res: [1000.0, 0.0, 0.0], rate: [0.0; 3], cap: [0.0; 3], energy_max: 0.0, energy_used: 0.0, factor: 1.0, units: Vec::new(), books: Default::default() };
        (units, planet)
    }

    #[test]
    fn orders_queue_behind_each_other_and_hand_over_one_unit_at_a_time() {
        let (units, mut planet) = tiny();
        let mut yard = units.shipyard(0);
        assert_eq!(units.order(&mut yard, &mut planet, 0, 3, 100, None), 3);
        assert_eq!((yard.orders[0].start, yard.orders[0].end), (100, 121));
        assert_eq!(units.order(&mut yard, &mut planet, 0, 2, 104, None), 2);
        assert_eq!((yard.orders[1].start, yard.orders[1].end), (121, 135));
        assert_eq!(yard.next_landing(), Some(107));
        units.advance(&mut yard, 114);
        assert_eq!(yard.counts[0], 2);
        assert!(units.blocks_upgrade(&yard, 0));
        units.advance(&mut yard, 135);
        assert_eq!((yard.counts[0], yard.busy()), (5, false));
        assert_eq!(planet.res[0], 950.0);
    }

    #[test]
    fn caps_follow_the_host() {
        let (units, mut planet) = tiny();
        let mut yard = units.shipyard(0);
        assert_eq!(units.order(&mut yard, &mut planet, 0, 1, 0, Some(1)), 0, "an upgrading time modifier blocks the order");
        assert_eq!(units.order(&mut yard, &mut planet, 1, 5, 0, None), 1, "an owned limit caps the order");
        assert_eq!(units.order(&mut yard, &mut planet, 1, 5, 1, None), 1, "a queued unit does not count against an owned limit");
        assert_eq!(units.order(&mut yard, &mut planet, 3, 9, 2, None), 5, "a store caps by slots");
        assert_eq!(units.order(&mut yard, &mut planet, 2, 9, 3, None), 0, "the store is full while the order is unfinished");
        planet.levels[0] = 0;
        assert_eq!(units.max_amount(&yard, &planet, 2), 0, "a requirement below its level");
    }
}
