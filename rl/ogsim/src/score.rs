//! The host's highscore over a world (plan/rl/north-star-plan.md, N1).
//!
//! Four scores, as `HighscoreService` sums them: a planet counts the cost of every level of its buildings and stations
//! up to the one held, all of its defences and all of its ships; economy counts the buildings and the defences in full
//! and half of each civil ship; military counts the defences and the military ships in full and half of each civil ship;
//! research counts the cost of every research level held. Ships in flight count where they are, not on the planet they
//! left, and a moon counts as its own planet with no buildings. The host floors every planet and every half of the
//! fleet's contribution over 1000 on its own, so `total` is not `economy + research + military`. Every price is the
//! host's (`ai:rl-units-catalogue`), so no object is named here.
use crate::units::{Cost, UnitKind, Units};
use crate::world::World;

/// The host's four scores for one account, to the point.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Score {
    pub total: i64,
    pub economy: i64,
    pub research: i64,
    pub military: i64,
}

/// The four scores of the account whose capital slot is `account`.
pub fn of(world: &World, account: usize) -> Score {
    let units = &world.rules[account].units;
    let (mut total, mut economy, mut military) = (0i64, 0i64, 0i64);
    for slot in world.planets_of(account) {
        let (general, econ, mil) = planet(units, &world.games[slot].planet.levels, &world.yards[slot].counts);
        total += general;
        economy += econ;
        military += mil;
        if !world.moons[slot] {
            continue;
        }
        // A moon is a planet of its own to the host (`planets->all()` lists it), with nothing built on it.
        let (moon_general, moon_econ, moon_mil) = planet(units, &[], &world.moon_holds[slot].counts);
        total += moon_general;
        economy += moon_econ;
        military += moon_mil;
    }
    let research = research(units, &world.games[account].planet.levels);
    let fleet = in_flight(world, account);

    Score {
        total: total + research + fleet.0,
        economy: economy + fleet.1,
        research,
        military: military + fleet.2,
    }
}

/// One planet's or moon's (general, economy, military) score, each floored over 1000 on its own as the host does.
fn planet(units: &Units, levels: &[u32], counts: &[u64]) -> (i64, i64, i64) {
    let (mut all, mut economy, mut military) = (0.0f64, 0.0, 0.0);
    for (k, &level) in levels.iter().enumerate() {
        let Some(cost) = &units.cost[k] else { continue };
        if cost.research || level == 0 {
            continue;
        }
        let spent = levels_cost(cost, level);
        all += spent;
        economy += spent;
    }
    for (u, &held) in counts.iter().enumerate() {
        if held > 0 {
            add(&units, u, held as f64, &mut all, &mut economy, &mut military);
        }
    }
    (points(all), points(economy), points(military))
}

/// The account's research score: every research level held, floored once over 1000 (`PlayerService::getResearchScore`).
fn research(units: &Units, levels: &[u32]) -> i64 {
    let spent: f64 = levels.iter().enumerate().filter_map(|(k, &level)| {
        let cost = units.cost[k].as_ref()?;
        (cost.research && level > 0).then(|| levels_cost(cost, level))
    }).sum();

    points(spent)
}

/// Ships in flight: every unprocessed mission of the account, floored once per score as the host does.
fn in_flight(world: &World, account: usize) -> (i64, i64, i64) {
    let units = &world.rules[account].units;
    let (mut all, mut economy, mut military) = (0.0f64, 0.0, 0.0);
    for fleet in world.fleets.iter().filter(|f| world.account_of(f.owner) == account) {
        for &(id, amount) in &fleet.ships {
            if let Some(u) = units.index(id as i64).filter(|_| amount > 0) {
                add(units, u, amount as f64, &mut all, &mut economy, &mut military);
            }
        }
    }

    (points(all), points(economy), points(military))
}

/// Adds `amount` of unit `u` to the three sums the way the highscore splits it.
fn add(units: &Units, u: usize, amount: f64, all: &mut f64, economy: &mut f64, military: &mut f64) {
    let price = units.units[u].price.iter().sum::<f64>() * amount;
    *all += price;
    match units.kind[u] {
        UnitKind::MilitaryShip => *military += price,
        UnitKind::CivilShip => {
            *economy += price * 0.5;
            *military += price * 0.5;
        }
        UnitKind::Defence => {
            *economy += price;
            *military += price;
        }
    }
}

/// The host's cost of every level up to `level`. A level past the table reads its edge, which is outside what the export
/// tabulated; `ponytail:` the table stops at the export's `score-levels` (40), so a deeper level needs a re-export.
fn levels_cost(cost: &Cost, level: u32) -> f64 {
    cost.cumulative[(level as usize - 1).min(cost.cumulative.len() - 1)].iter().sum()
}

/// `(int) floor($sum / 1000)`, the point the host makes out of a resource sum.
fn points(sum: f64) -> i64 {
    (sum / 1000.0).floor() as i64
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::economy::Catalogue;
    use crate::fleet::Coord;
    use crate::world::{Fleet, Mission, Rules};
    use serde::Deserialize;
    use crate::HashMap;
    use std::sync::Arc;

    /// Where `ai:rl-score-cases` (score-cases-u1.json) sits beside the u1 catalogues.
    const DATA: &str = "../../../../storage/rl/ogsim";

    #[derive(Deserialize)]
    struct Cases {
        cases: Vec<Case>,
    }

    #[derive(Deserialize)]
    struct Case {
        index: usize,
        #[serde(default)]
        research: HashMap<String, u32>,
        planets: Vec<Planet>,
        #[serde(default)]
        fleets: Vec<Flight>,
        score: Expected,
    }

    #[derive(Deserialize)]
    struct Planet {
        id: i64,
        #[serde(default)]
        levels: HashMap<String, u32>,
        #[serde(default)]
        ships: HashMap<String, i64>,
        #[serde(default)]
        defence: HashMap<String, i64>,
        #[serde(default)]
        moon_ships: HashMap<String, i64>,
    }

    #[derive(Deserialize)]
    struct Flight {
        #[serde(default)]
        ships: HashMap<String, i64>,
    }

    #[derive(Deserialize)]
    struct Expected {
        total: i64,
        economy: i64,
        research: i64,
        military: i64,
    }

    impl Case {
        /// The world the host scored: the case's planets, their levels and units, the account's research and every
        /// fleet in flight, and nothing else (the score reads no clock and no queue).
        fn world(&self, cat: &Catalogue, rules: &Arc<Rules>) -> World {
            let configs: Vec<usize> = self.planets.iter()
                .map(|p| cat.planets.iter().position(|c| c.planet_id == p.id).unwrap_or_else(|| panic!("case {}: planet {} is not in the catalogue", self.index, p.id)))
                .collect();
            let mut world = World::new(cat, &configs, 1e6, 0.0, 1);
            let coords = (0..configs.len()).map(|slot| Coord { galaxy: 1, system: slot as i64 + 1, position: 8 }).collect();
            world.arm(vec![rules.clone(); configs.len()], coords);
            for slot in 1..configs.len() {
                world.join_account(slot, 0);
            }
            for (slot, planet) in self.planets.iter().enumerate() {
                let object = |id: &String| cat.objects.iter().position(|o| o.id == id.parse::<i64>().unwrap()).expect("an object in the catalogue");
                let units = &world.rules[slot].units;
                let unit = |id: &String| units.index(id.parse::<i64>().unwrap()).expect("a unit in the catalogue");
                for (id, &level) in planet.levels.iter().chain(&self.research) {
                    world.games[slot].planet.levels[object(id)] = level;
                }
                for (id, &held) in planet.ships.iter().chain(&planet.defence) {
                    world.yards[slot].counts[unit(id)] = held as u64;
                }
                if planet.moon_ships.is_empty() {
                    continue;
                }
                world.moons[slot] = true;
                world.moon_holds[slot].counts = vec![0; units.units.len()];
                for (id, &held) in &planet.moon_ships {
                    world.moon_holds[slot].counts[unit(id)] = held as u64;
                }
            }
            for flight in &self.fleets {
                world.fleets.push(Fleet {
                    owner: 0,
                    target: 0,
                    mission: Mission::Transport,
                    ships: flight.ships.iter().map(|(id, &held)| (id.parse::<u32>().unwrap(), held)).collect(),
                    cargo: [0.0; 3],
                    fuel: 0.0,
                    to: Coord { galaxy: 1, system: 1, position: 8 },
                    arrive: 1.0,
                    home: 2.0,
                    returning: false,
                    departed: 0.0,
                    holding: 0.0,
                    id: usize::MAX,
                    priority: 0,
                    moon: false,
                });
            }
            world
        }
    }

    /// The host's own answers to `ai:rl-score-cases`: the four scores of every drawn account, to the point.
    #[test]
    fn equals_host() {
        let path = format!("{DATA}/score-cases-u1.json");
        let text = std::fs::read_to_string(&path).unwrap_or_else(|e| panic!("{path}: {e}; run ai:rl-score-cases first"));
        let cases: Cases = serde_json::from_str(&text).expect("score cases");
        assert!(!cases.cases.is_empty(), "{path} holds no case");
        let cat = Catalogue::load(&format!("{DATA}/units-parity/econ-u1.json")).expect("the u1 economy catalogue");
        let rules = Arc::new(Rules::load(
            &cat,
            &format!("{DATA}/units-parity/units-u1.json"),
            &format!("{DATA}/fleet-settings-u1.json"),
            &format!("{DATA}/combat-u1.json"),
        ).expect("the u1 units, fleet settings and combat catalogue"));

        let mut differ: Vec<String> = Vec::new();
        for case in &cases.cases {
            let world = case.world(&cat, &rules);
            let got = of(&world, 0);
            let host = Score { total: case.score.total, economy: case.score.economy, research: case.score.research, military: case.score.military };
            if got != host {
                differ.push(format!("case {}: simulator {got:?} host {host:?}", case.index));
            }
        }

        eprintln!("equals_host: {} cases, {} differ", cases.cases.len(), differ.len());
        assert!(differ.is_empty(), "the simulator scores these accounts differently from the host:\n{}", differ.join("\n"));
    }
}
