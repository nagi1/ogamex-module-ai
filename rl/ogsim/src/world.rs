//! Many accounts in one universe on one clock (plan/rl/world-sim-spec.md). Each account's economy moves one event at
//! a time (`Catalogue::tick`) and the world always moves whatever comes first: an account's next event or a fleet
//! reaching its target or home. A fleet event lands before anything later on any account, at the second the host
//! would process it.
//!
//! Missions follow the host's mission classes: an attack fights the target planet's ships and defences with the
//! host's engine (`combat`), loots, leaves debris and may raise a moon, and flies home with what is left; a transport
//! unloads and flies home empty. Flight time and fuel are the host's (`fleet`), ships and defence the planet's
//! shipyard (`units`). One planet per account until colonisation comes.

use std::collections::HashMap;
use std::sync::Arc;

use crate::combat::{self, Battle, CombatCatalogue};
use crate::economy::{next_random, Catalogue, Game};
use crate::fleet::{self, Coord, FleetCatalogue, FleetSettings, SystemState};
use crate::units::{Shipyard, Units};

/// The host's tables for everything past the economy, read once and shared by every world.
pub struct Rules {
    pub units: Units,
    pub settings: FleetSettings,
    pub fleet: FleetCatalogue,
    pub combat: CombatCatalogue,
}

impl Rules {
    /// The units export, the fleet settings and the combat catalogue (which also holds speeds and fuel) of one universe.
    pub fn load(cat: &Catalogue, units: &str, settings: &str, combat: &str) -> Result<Rules, String> {
        Ok(Rules { units: Units::load(units, cat)?, settings: FleetSettings::load(settings)?, fleet: FleetCatalogue::load(combat)?, combat: CombatCatalogue::load(combat)? })
    }
}

/// The host's mission type ids (GameMissionType) for the missions the world plays.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Mission {
    Attack = 1,
    Transport = 3,
}

#[derive(Clone, Debug)]
pub struct Fleet {
    pub owner: usize,
    pub target: usize,
    pub mission: Mission,
    /// (host unit id, amount).
    pub ships: Vec<(u32, i64)>,
    pub cargo: [f64; 3],
    pub arrive: f64,
    pub home: f64,
    pub returning: bool,
}

impl Fleet {
    fn next(&self) -> f64 {
        if self.returning { self.home } else { self.arrive }
    }
}

pub struct World {
    pub games: Vec<Game>,
    /// The second each account's clock moves next, kept beside the games so choosing the next one reads no catalogue.
    next: Vec<f64>,
    rules: Option<Arc<Rules>>,
    pub coords: Vec<Coord>,
    pub yards: Vec<Shipyard>,
    pub fleets: Vec<Fleet>,
    /// Debris over each planet, and whether it has a moon.
    pub debris: Vec<[i64; 3]>,
    pub moons: Vec<bool>,
    rng: u64,
}

impl World {
    /// One account per planet config, each with its own login draws; every account has made its first decision
    /// ready once `next_decision` reaches it.
    pub fn new(cat: &Catalogue, configs: &[usize], horizon_hours: f64, bonus_hours: f64, seed: u64) -> World {
        let mut games: Vec<Game> = configs.iter().enumerate()
            .map(|(a, &c)| cat.new_paused_game(c, horizon_hours, bonus_hours, seed.wrapping_add(a as u64)))
            .collect();
        let next = games.iter_mut().map(|g| cat.peek(g)).collect();
        let n = configs.len();
        World { games, next, rules: None, coords: Vec::new(), yards: Vec::new(), fleets: Vec::new(), debris: vec![[0; 3]; n], moons: vec![false; n], rng: seed ^ 0x9e37_79b9_7f4a_7c15 }
    }

    /// Places every account's planet at its coordinates with the ships and defence its config starts with, so fleets can fly.
    pub fn arm(&mut self, rules: Arc<Rules>, coords: Vec<Coord>) {
        assert_eq!(coords.len(), self.games.len(), "one coordinate per account");
        self.yards = self.games.iter().map(|g| rules.units.shipyard(g.planet.config)).collect();
        self.coords = coords;
        self.rules = Some(rules);
    }

    /// The world's clock: the earliest second any account or fleet moves next.
    pub fn now(&self) -> f64 {
        self.next.iter().copied().chain(self.fleets.iter().map(Fleet::next)).fold(f64::INFINITY, f64::min)
    }

    /// Moves the world event by event until an account has a queue to decide (its index; `games[a].queue` is the
    /// queue), or None when every account is done. A fleet due at the same second as an account's event goes first.
    pub fn next_decision(&mut self, cat: &Catalogue) -> Option<usize> {
        loop {
            let a = (0..self.games.len()).filter(|&a| !self.games[a].done).min_by(|&x, &y| self.next[x].total_cmp(&self.next[y]).then(x.cmp(&y)))?;
            let fleet = (0..self.fleets.len()).min_by(|&x, &y| self.fleets[x].next().total_cmp(&self.fleets[y].next()));
            if let Some(f) = fleet.filter(|&f| self.fleets[f].next() <= self.next[a]) {
                self.fleet_event(cat, f);
                continue;
            }
            let deciding = cat.tick(&mut self.games[a]);
            self.next[a] = cat.peek(&mut self.games[a]);
            if deciding && !self.games[a].done {
                return Some(a);
            }
        }
    }

    /// Account a commits its deciding queue to object k (`Catalogue::step` without running its clock ahead of the others).
    pub fn commit(&mut self, cat: &Catalogue, a: usize, k: usize) {
        debug_assert!(self.legal(cat, a, k), "account {a} cannot start object {k} now");
        cat.decide(&mut self.games[a], Some(k));
        self.next[a] = cat.peek(&mut self.games[a]);
    }

    /// Account a lets this login pass and decides again at its next one.
    pub fn wait(&mut self, cat: &Catalogue, a: usize) {
        cat.decide(&mut self.games[a], None);
        self.next[a] = cat.peek(&mut self.games[a]);
    }

    /// What the deciding queue may start: the economy's rule, and no upgrade the running shipyard forbids (UnitQueueService).
    pub fn legal(&self, cat: &Catalogue, a: usize, k: usize) -> bool {
        cat.queue_legal(&self.games[a], k) && !self.rules.as_ref().is_some_and(|r| r.units.blocks_upgrade(&self.yards[a], k))
    }

    /// Account a's research levels by host id, as the fleet and combat tables read them.
    pub fn research(&self, cat: &Catalogue, a: usize) -> HashMap<u32, u32> {
        cat.objects.iter().zip(&self.games[a].planet.levels).filter(|(o, _)| o.queue == 1).map(|(o, &l)| (o.id as u32, l)).collect()
    }

    /// Brings planet p's stock and shipyard to second t before a fleet touches it; nothing of p's own is due before t.
    fn catch_up(&mut self, cat: &Catalogue, p: usize, t: f64) {
        if t <= self.games[p].t { return }
        cat.advance_to(&mut self.games[p], t, true);
        if let Some(rules) = &self.rules {
            rules.units.advance(&mut self.yards[p], t as i64);
        }
    }

    /// Account a, while it decides, orders `amount` of unit u (units index) in its shipyard; the amount the host would queue.
    pub fn order_units(&mut self, cat: &Catalogue, a: usize, u: usize, amount: u64) -> u64 {
        let rules = Arc::clone(self.rules.as_ref().expect("an armed world"));
        let now = self.games[a].t;
        self.catch_up(cat, a, now);
        let upgrading = self.games[a].pending.iter().find(|&&(_, k)| cat.objects[k].queue == 0).map(|&(_, k)| k);
        let queued = rules.units.order(&mut self.yards[a], &mut self.games[a].planet, u, amount, now as i64, upgrading);
        self.next[a] = cat.peek(&mut self.games[a]);
        queued
    }

    /// Account a, while it decides, sends ships (host unit id, amount) with cargo to planet `target` at `speed`
    /// (the host's 0.5..10 steps). Pays ships, cargo and fuel from the planet now; refused as the host would refuse it.
    pub fn dispatch(&mut self, cat: &Catalogue, a: usize, target: usize, mission: Mission, ships: &[(u32, i64)], cargo: [f64; 3], speed: f64) -> Result<(), &'static str> {
        let rules = Arc::clone(self.rules.as_ref().expect("an armed world"));
        let now = self.games[a].t;
        self.catch_up(cat, a, now);
        if target == a { return Err("own planet") }
        if mission == Mission::Attack && cargo.iter().any(|&c| c > 0.0) { return Err("an attack carries no cargo") }
        let research = self.research(cat, a);
        let mut sent = Vec::new();
        for &(id, amount) in ships.iter().filter(|s| s.1 > 0) {
            let u = rules.units.index(id as i64).ok_or("unknown unit")?;
            if self.yards[a].counts[u] < amount as u64 { return Err("not enough ships") }
            if rules.fleet.speed(id, &research) <= 0 { return Err("a unit that cannot fly") }
            sent.push((id, amount));
        }
        if sent.is_empty() { return Err("no ships") }
        let distance = fleet::distance(&rules.settings, self.coords[a], self.coords[target], &|_, _| SystemState::Active);
        let fuel = fleet::consumption(&rules.settings, &rules.fleet, &sent, &research, distance, 0, speed) as f64;
        let held: combat::Units = sent.iter().copied().collect();
        if cargo.iter().sum::<f64>() > rules.combat.capacity(&held, &research) as f64 { return Err("cargo over capacity") }
        let stock = self.games[a].planet.res;
        if (0..3).any(|r| cargo[r] > stock[r]) || cargo[2] + fuel > stock[2] { return Err("not enough resources") }
        let flight = fleet::flight_seconds(&rules.settings, mission as u32, distance, rules.fleet.slowest_speed(&sent, &research), speed) as f64;
        for &(id, amount) in &sent {
            self.yards[a].counts[rules.units.index(id as i64).unwrap()] -= amount as u64;
        }
        let planet = &mut self.games[a].planet;
        for r in 0..3 { planet.res[r] -= cargo[r] }
        planet.res[2] -= fuel;
        self.fleets.push(Fleet { owner: a, target, mission, ships: sent, cargo, arrive: now + flight, home: now + 2.0 * flight, returning: false });
        self.next[a] = cat.peek(&mut self.games[a]);
        Ok(())
    }

    /// A fleet reaches its target (the mission acts and the fleet turns home) or comes home (ships and cargo land).
    fn fleet_event(&mut self, cat: &Catalogue, f: usize) {
        let at = self.fleets[f].next();
        if self.fleets[f].returning {
            let fleet = self.fleets.swap_remove(f);
            self.land(cat, fleet.owner, at, &fleet.ships, fleet.cargo);
            return;
        }
        let target = self.fleets[f].target;
        self.catch_up(cat, target, at);
        match self.fleets[f].mission {
            Mission::Transport => {
                let cargo = std::mem::take(&mut self.fleets[f].cargo);
                for r in 0..3 { self.games[target].planet.res[r] += cargo[r] }
            }
            Mission::Attack => self.attack(cat, f),
        }
        self.next[target] = cat.peek(&mut self.games[target]);
        self.fleets[f].returning = true;
        if self.fleets[f].ships.is_empty() {
            self.fleets.swap_remove(f);
        }
    }

    fn land(&mut self, cat: &Catalogue, p: usize, at: f64, ships: &[(u32, i64)], cargo: [f64; 3]) {
        let rules = Arc::clone(self.rules.as_ref().expect("an armed world"));
        self.catch_up(cat, p, at);
        for &(id, amount) in ships {
            self.yards[p].counts[rules.units.index(id as i64).unwrap()] += amount as u64;
        }
        for r in 0..3 { self.games[p].planet.res[r] += cargo[r] }
        self.next[p] = cat.peek(&mut self.games[p]);
    }

    /// AttackMission: the host's battle on the target's ships and defence, then repairs, loot, debris and the moon roll.
    fn attack(&mut self, cat: &Catalogue, f: usize) {
        let rules = Arc::clone(self.rules.as_ref().expect("an armed world"));
        let (owner, target) = (self.fleets[f].owner, self.fleets[f].target);
        let defender: Vec<(u32, i64)> = rules.units.units.iter().zip(&self.yards[target].counts)
            .filter(|(_, &n)| n > 0).map(|(u, &n)| (u.id as u32, n as i64)).collect();
        let (attacker_research, defender_research) = (self.research(cat, owner), self.research(cat, target));
        let battle = Battle {
            attacker: &self.fleets[f].ships,
            attacker_research: &attacker_research,
            attacker_cargo: [0; 3],
            defender: &defender,
            defender_research: &defender_research,
            defender_stock: self.games[target].planet.res,
            defender_engineer: false,
            moon_exists: self.moons[target],
            seed: next_random(&mut self.rng),
        };
        let fought = combat::defender_units(&rules.combat, &battle);
        let outcome = combat::fight(&rules.combat, &battle);
        for (u, unit) in rules.units.units.iter().enumerate() {
            let id = unit.id as u32;
            if fought.contains_key(&id) {
                self.yards[target].counts[u] = (outcome.defender_left.get(&id).copied().unwrap_or(0) + outcome.repaired.get(&id).copied().unwrap_or(0)) as u64;
            }
        }
        for r in 0..3 {
            self.games[target].planet.res[r] -= outcome.loot[r] as f64;
            self.debris[target][r] += outcome.debris[r];
        }
        self.moons[target] |= outcome.moon_created;
        self.fleets[f].ships = outcome.attacker_left.into_iter().collect();
        self.fleets[f].cargo = outcome.loot.map(|r| r as f64);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::economy::Baseline;

    /// A world of independent accounts plays each of them exactly as the one-account simulator does on its own.
    #[test]
    fn a_world_of_accounts_plays_each_one_as_the_single_planet_game() {
        let path = std::env::var("OGSIM_CATALOGUE").unwrap_or_else(|_| "../../../../storage/rl/ogsim/plain-eval.json".into());
        let Ok(mut cat) = Catalogue::load(&path) else { return eprintln!("no catalogue at {path}; skipped") };
        cat.login_gaps = vec![600.0, 1800.0, 3600.0, 7200.0, 21600.0];
        let configs: Vec<usize> = (0..cat.planets.len().min(8)).collect();
        let mut world = World::new(&cat, &configs, 240.0, 0.0, 7);
        let mut rngs: Vec<u64> = (0..configs.len() as u64).map(|a| a + 100).collect();
        let mut last = 0.0;
        while let Some(a) = world.next_decision(&cat) {
            assert!(world.games[a].t >= last, "the world clock went back");
            last = world.games[a].t;
            let game = &world.games[a];
            match cat.baseline(&game.planet, game.queue, Baseline::Player, &mut rngs[a]) {
                Some(k) => world.commit(&cat, a, k),
                None => world.wait(&cat, a),
            }
        }
        for (a, &c) in configs.iter().enumerate() {
            let mut alone = cat.new_game(c, 240.0, 0.0, 7u64.wrapping_add(a as u64));
            let mut rng = a as u64 + 100;
            while !alone.done {
                match cat.baseline(&alone.planet, alone.queue, Baseline::Player, &mut rng) {
                    Some(k) => { cat.step(&mut alone, k); }
                    None => cat.wait_turn(&mut alone),
                }
            }
            assert!(!alone.history.is_empty(), "account {a}: nothing built");
            let world_game = &world.games[a];
            assert_eq!(world_game.history.len(), alone.history.len(), "account {a}: build count");
            for (x, y) in world_game.history.iter().zip(&alone.history) {
                assert_eq!((x.0, x.1, x.2), (y.0, y.1, y.2), "account {a}: build differs");
            }
            assert_eq!(world_game.planet.res, alone.planet.res, "account {a}: final stock");
        }
    }

    const DATA: &str = "../../../../storage/rl/ogsim";

    /// Two accounts on u1 that never build: every difference from a quiet twin world is what the fleet moved.
    fn pair(hours: f64) -> Option<(Catalogue, World, World)> {
        let mut cat = Catalogue::load(&format!("{DATA}/units-parity/econ-u1.json")).ok()?;
        cat.login_gaps = vec![1800.0];
        let rules = Arc::new(Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"), &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")).ok()?);
        let coords = vec![Coord { galaxy: 1, system: 10, position: 4 }, Coord { galaxy: 1, system: 14, position: 8 }];
        let mut worlds = (0..2).map(|_| {
            let mut w = World::new(&cat, &[0, 1], hours, 0.0, 3);
            w.arm(Arc::clone(&rules), coords.clone());
            w
        });
        let (a, b) = (worlds.next().unwrap(), worlds.next().unwrap());
        Some((cat, a, b))
    }

    fn idle(cat: &Catalogue, w: &mut World) {
        while let Some(a) = w.next_decision(cat) {
            w.wait(cat, a);
        }
    }

    fn give(w: &mut World, a: usize, id: u32, amount: u64) {
        let u = w.rules.as_ref().unwrap().units.index(id as i64).unwrap();
        w.yards[a].counts[u] = amount;
    }

    /// A transport unloads exactly its cargo, pays the host's fuel, and its ships come home at twice the flight time.
    #[test]
    fn a_transport_moves_its_cargo_and_comes_home() {
        let Some((cat, mut sent, mut quiet)) = pair(6.0) else { return eprintln!("no u1 catalogues; skipped") };
        for w in [&mut sent, &mut quiet] {
            give(w, 0, 202, 10);
            w.games[0].planet.res = [50_000.0, 20_000.0, 20_000.0];
        }
        let a = sent.next_decision(&cat).unwrap();
        assert_eq!(a, 0);
        sent.dispatch(&cat, 0, 1, Mission::Transport, &[(202, 10)], [1000.0, 500.0, 0.0], 10.0).unwrap();
        let fuel = 20_000.0 - sent.games[0].planet.res[2];
        let fleet = sent.fleets[0].clone();
        assert!(fuel > 0.0 && fleet.home == 2.0 * fleet.arrive - sent.games[0].t);
        sent.wait(&cat, 0);
        idle(&cat, &mut sent);
        idle(&cat, &mut quiet);
        assert!(sent.fleets.is_empty());
        assert_eq!(sent.yards[0].counts, quiet.yards[0].counts, "the ships came home");
        let (to, back) = (&sent.games[1].planet.res, &quiet.games[1].planet.res);
        assert!((to[0] - back[0] - 1000.0).abs() < 1e-6 && (to[1] - back[1] - 500.0).abs() < 1e-6, "target {to:?} vs {back:?}");
        let (from, home) = (&sent.games[0].planet.res, &quiet.games[0].planet.res);
        assert!((home[0] - from[0] - 1000.0).abs() < 1e-6 && (home[2] - from[2] - fuel).abs() < 1e-6, "origin {from:?} vs {home:?}");
    }

    /// An attack on an undefended planet takes the host's loot share home: what the target lost, the attacker gained.
    #[test]
    fn a_raid_brings_home_what_the_target_lost() {
        let Some((cat, mut raided, mut quiet)) = pair(6.0) else { return eprintln!("no u1 catalogues; skipped") };
        for w in [&mut raided, &mut quiet] {
            give(w, 0, 202, 20);
            w.yards[1].counts.iter_mut().for_each(|n| *n = 0);
            w.games[0].planet.res[2] = 20_000.0;
            w.games[1].planet.res = [30_000.0, 20_000.0, 10_000.0];
            // No production, so storage caps cannot hide or add anything: only the fleet moves stock.
            w.games.iter_mut().for_each(|g| g.planet.rate = [0.0; 3]);
        }
        raided.next_decision(&cat).unwrap();
        raided.dispatch(&cat, 0, 1, Mission::Attack, &[(202, 20)], [0.0; 3], 10.0).unwrap();
        let fuel = 20_000.0 - raided.games[0].planet.res[2];
        raided.wait(&cat, 0);
        idle(&cat, &mut raided);
        idle(&cat, &mut quiet);
        let lost: Vec<f64> = (0..3).map(|r| quiet.games[1].planet.res[r] - raided.games[1].planet.res[r]).collect();
        let won: Vec<f64> = (0..3).map(|r| raided.games[0].planet.res[r] - quiet.games[0].planet.res[r] + if r == 2 { fuel } else { 0.0 }).collect();
        assert!(lost.iter().sum::<f64>() > 0.0, "nothing looted");
        for r in 0..3 { assert!((lost[r] - won[r]).abs() < 1e-6, "resource {r}: lost {} won {}", lost[r], won[r]) }
        assert_eq!(raided.yards[0].counts, quiet.yards[0].counts, "no defence, no losses");
    }
}

