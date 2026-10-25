//! Many accounts in one universe on one clock (plan/rl/world-sim-spec.md). Each account's economy moves one event at
//! a time (`Catalogue::tick`) and the world always moves whatever comes first: an account's next event or a fleet
//! reaching its target or home. A fleet event lands before anything later on any account, at the second the host
//! would process it.
//!
//! Missions follow the host's mission classes: an attack fights the target planet's ships and defences with the
//! host's engine (`combat`), loots, leaves debris and may raise a moon, and flies home with what is left; a transport
//! unloads and flies home empty. Flight time and fuel are the host's (`fleet`), ships and defence the planet's
//! shipyard (`units`).
//!
//! An account owns one or more planets. Every planet is a slot of the world: its own `Game` (stock, building queue,
//! logins), shipyard, coordinates, debris and moon, addressed by slot index as accounts were before colonisation. The
//! first slot of an account is its capital (`account[slot]` names it); research is the account's, so it is done on
//! the capital's lab and every level that lands there is copied to the account's other planets at that second
//! (`share_research`), and the other planets never decide the research queue. Missions run slot to slot.

use std::collections::HashMap;
use std::sync::Arc;

use crate::combat::{self, Battle, CombatCatalogue};
use crate::economy::{as_stored, next_random, Catalogue, Game, PlanetConfig};
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
    Deploy = 4,
    Espionage = 6,
    Colonise = 7,
    Recycle = 8,
}

/// One colony the host's tables describe: the economy config of `donor`'s owner at this slot, temperature and size.
#[derive(Clone, Debug)]
pub struct Site {
    /// Index into `Catalogue::planets`.
    pub config: usize,
    /// Host planet id of the home planet whose owner (class, officers) the tables carry.
    pub donor: i64,
    pub position: i64,
    pub temp_max: i64,
    pub fields: i64,
}

/// What colonising needs from the host (the `colonisation` block of the economy export): the ship and the research
/// the mission class declares, the planet limit per research level, the research each slot needs, and the tabulated sites.
#[derive(Clone, Debug)]
pub struct Colonisation {
    pub research: i64,
    pub ship: u32,
    pub max_planets: Vec<usize>,
    pub position_levels: HashMap<i64, u32>,
    pub sites: Vec<Site>,
}

impl Colonisation {
    pub fn load(path: &str) -> Result<Colonisation, String> {
        let text = std::fs::read_to_string(path).map_err(|e| format!("{path}: {e}"))?;
        let raw: serde_json::Value = serde_json::from_str(&text).map_err(|e| format!("{path}: {e}"))?;
        Colonisation::from_json(raw.get("colonisation").ok_or_else(|| format!("{path}: no colonisation block"))?)
    }

    fn from_json(block: &serde_json::Value) -> Result<Colonisation, String> {
        let int = |v: &serde_json::Value, key: &str| v[key].as_i64().ok_or_else(|| format!("colonisation: no {key}"));
        Ok(Colonisation {
            research: int(block, "research")?,
            ship: int(block, "ship")? as u32,
            max_planets: block["max_planets"].as_array().ok_or("colonisation: no max_planets")?.iter().filter_map(|n| n.as_u64()).map(|n| n as usize).collect(),
            position_levels: block["position_levels"].as_object().ok_or("colonisation: no position_levels")?.iter()
                .filter_map(|(p, l)| Some((p.parse().ok()?, l.as_u64()? as u32))).collect(),
            sites: block["sites"].as_array().ok_or("colonisation: no sites")?.iter().map(|s| Ok(Site {
                config: int(s, "config")? as usize,
                donor: int(s, "donor")?,
                position: int(s, "position")?,
                temp_max: int(s, "temp_max")?,
                fields: int(s, "fields")?,
            })).collect::<Result<_, String>>()?,
        })
    }

    /// The host's planet limit at this research level (the last tabulated level holds beyond the table).
    pub fn max_planets(&self, level: u32) -> usize {
        self.max_planets[(level as usize).min(self.max_planets.len() - 1)]
    }
}

/// A planet config for a planet the catalogue has no tables for: the template's tables, no building, the stock of a
/// new colony and no unit. Its production is the template's, so it is for planets whose economy is not under test.
pub fn fresh_config(cat: &Catalogue, template: usize, planet_id: i64) -> PlanetConfig {
    let mut config = cat.planets[template].clone();
    config.planet_id = planet_id;
    config.resources = [500.0, 500.0, 0.0];
    config.units = vec![0; config.units.len()];
    for (k, level) in config.levels.iter_mut().enumerate() {
        if cat.objects[k].queue == 0 { *level = 0 }
    }
    config
}

/// What an espionage report shows the spying account (EspionageMission::createEspionageReport). A section the
/// probes could not reach is None; units are shipyard counts in the target's `Units` order, levels economy levels.
#[derive(Clone, Debug)]
pub struct SpyReport {
    pub owner: usize,
    pub target: usize,
    pub time: f64,
    pub resources: [i64; 3],
    pub debris: Option<[i64; 3]>,
    pub counter_chance: i64,
    pub ships: Option<Vec<u64>>,
    pub defence: Option<Vec<u64>>,
    pub buildings: Option<Vec<u32>>,
    pub research: Option<Vec<u32>>,
}

#[derive(Clone, Debug)]
pub struct Fleet {
    pub owner: usize,
    pub target: usize,
    pub mission: Mission,
    /// (host unit id, amount).
    pub ships: Vec<(u32, i64)>,
    pub cargo: [f64; 3],
    /// Deuterium paid at dispatch; the host hands half of it back inside the mission's cargo
    /// (`FleetMissionService::getResources`), so it rides with the delivery or the loot.
    pub fuel: f64,
    /// Where the fleet is going; for a colonisation the slot nobody owns yet (`target` is then the sender's own slot).
    pub to: Coord,
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
    /// Per slot, the capital slot of its account; every slot is its own account until a colony joins one.
    pub account: Vec<usize>,
    /// Per capital slot, the account's other planets in the order they were founded; empty for every other slot.
    colonies: Vec<Vec<usize>>,
    /// The host's colonisation tables; colonising is refused without them.
    pub colonisation: Option<Arc<Colonisation>>,
    /// Coordinates taken by planets outside the world (another player's colony), where nobody may colonise.
    pub taken: Vec<Coord>,
    /// The host's draw for the next colony, (temperature max, fields), handed over by the referee as `recorded` is;
    /// without it the colony takes one of the tabulated sites of that slot.
    pub recorded_colony: Option<(i64, i64)>,
    /// The second each account's clock moves next, kept beside the games so choosing the next one reads no catalogue.
    next: Vec<f64>,
    /// One per account, in account order: the tables of that account's character class.
    pub rules: Vec<Arc<Rules>>,
    pub coords: Vec<Coord>,
    pub yards: Vec<Shipyard>,
    pub fleets: Vec<Fleet>,
    /// A fight the host already settled, applied by the next attack instead of a fresh roll: the host's dice (PHP's
    /// `mt_rand` after the battle) cannot be repeated here, so the referee hands over what they produced.
    pub recorded: Option<combat::Recorded>,
    /// The host's counter-espionage roll (1..100) for the next espionage, handed over by the referee as `recorded` is.
    pub recorded_roll: Option<i64>,
    /// Colonies the host's tables could not place (no tabulated site for that slot), so the mission was cancelled.
    pub unplaced: usize,
    /// Every espionage report, oldest first; the spying account reads its own.
    pub reports: Vec<SpyReport>,
    /// Whether a debris field exists over each planet: the host keeps a field once made, even when harvested empty,
    /// and a recycler may fly only to one that exists.
    pub fields: Vec<bool>,
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
        World { games, account: (0..n).collect(), colonies: vec![Vec::new(); n], colonisation: None, taken: Vec::new(), recorded_colony: None, next, rules: Vec::new(), coords: Vec::new(), yards: Vec::new(), fleets: Vec::new(), recorded: None, recorded_roll: None, unplaced: 0, reports: Vec::new(), fields: vec![false; n], debris: vec![[0; 3]; n], moons: vec![false; n], rng: seed ^ 0x9e37_79b9_7f4a_7c15 }
    }

    /// Places every account's planet at its coordinates with the ships and defence its config starts with, so fleets can
    /// fly. One `Rules` per account: that account's character class decides its speeds, capacity, fuel and loot share.
    pub fn arm(&mut self, rules: Vec<Arc<Rules>>, coords: Vec<Coord>) {
        assert_eq!(coords.len(), self.games.len(), "one coordinate per account");
        assert_eq!(rules.len(), self.games.len(), "one rules per account");
        self.yards = rules.iter().zip(&self.games).map(|(r, g)| r.units.shipyard(g.planet.config)).collect();
        self.coords = coords;
        self.rules = rules;
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
            let deciding = self.step_account(cat, a);
            if deciding && !self.games[a].done {
                return Some(a);
            }
        }
    }

    /// Moves slot a's clock by one event (`Catalogue::tick`) and hands a research that landed to the account's other planets.
    fn step_account(&mut self, cat: &Catalogue, a: usize) -> bool {
        let deciding = cat.tick(&mut self.games[a]);
        self.share_research(cat, a);
        self.next[a] = cat.peek(&mut self.games[a]);
        deciding
    }

    /// The capital of slot a's account.
    pub fn account_of(&self, a: usize) -> usize {
        self.account[a]
    }

    /// Every planet slot of the account whose capital is `capital`, the capital first.
    pub fn planets_of(&self, capital: usize) -> Vec<usize> {
        std::iter::once(capital).chain(self.colonies[capital].iter().copied()).collect()
    }

    /// Research is the account's: a level that landed on the capital's lab is copied to its other planets at that
    /// second, so their production, requirements and fleets read it. Their research queue never runs.
    fn share_research(&mut self, cat: &Catalogue, capital: usize) {
        for s in self.colonies[capital].clone() {
            if self.games[s].done { continue }
            let behind = |w: &World, k: usize| cat.objects[k].queue == 1 && w.games[s].planet.levels[k] != w.games[capital].planet.levels[k];
            if !(0..cat.objects.len()).any(|k| behind(self, k)) { continue }
            // Nothing of the planet is due before the capital's clock: the world moves the earliest event first.
            let t = self.games[capital].t;
            self.catch_up(cat, s, t);
            for k in (0..cat.objects.len()).filter(|&k| cat.objects[k].queue == 1) {
                self.games[s].planet.levels[k] = self.games[capital].planet.levels[k];
            }
            cat.refresh(&mut self.games[s].planet);
            self.next[s] = cat.peek(&mut self.games[s]);
        }
    }

    /// Adds a planet to the account of slot `owner`, founded at second `at` on the economy config `config` (a colony's
    /// tables from the host, `Colonisation::sites`) at `coord`. It starts with the config's stock and no building,
    /// with the account's research and logins; returns its slot.
    pub fn add_planet(&mut self, cat: &Catalogue, owner: usize, config: usize, coord: Coord, at: f64) -> usize {
        let capital = self.account[owner];
        let home = &self.games[capital];
        let mut game = cat.new_paused_game(config, home.horizon / 3600.0, home.bonus_hours, home.login_rng);
        game.t = at;
        game.free = [at, f64::INFINITY];
        // The planet logs in with its account: same past logins and the same generator for the next ones.
        game.login_times = home.login_times.clone();
        game.login_rng = home.login_rng;
        for k in (0..cat.objects.len()).filter(|&k| cat.objects[k].queue == 1) {
            game.planet.levels[k] = home.planet.levels[k];
        }
        cat.refresh(&mut game.planet);
        let rules = Arc::clone(&self.rules[capital]);
        // A new planet holds no unit and builds what its owner's class may: the capital's yard says which.
        let yard = &self.yards[capital];
        self.yards.push(Shipyard { counts: vec![0; yard.counts.len()], allowed: yard.allowed.clone(), orders: Vec::new() });
        self.rules.push(rules);
        self.coords.push(coord);
        self.fields.push(false);
        self.debris.push([0; 3]);
        self.moons.push(false);
        self.account.push(capital);
        self.colonies.push(Vec::new());
        self.next.push(0.0);
        self.games.push(game);
        let slot = self.games.len() - 1;
        self.colonies[capital].push(slot);
        self.sync_units(cat, slot);
        self.next[slot] = cat.peek(&mut self.games[slot]);
        slot
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
        cat.queue_legal(&self.games[a], k) && !self.rules.get(a).is_some_and(|r| r.units.blocks_upgrade(&self.yards[a], k))
    }

    /// Account a's research levels by host id, as the fleet and combat tables read them.
    pub fn research(&self, cat: &Catalogue, a: usize) -> HashMap<u32, u32> {
        cat.objects.iter().zip(&self.games[a].planet.levels).filter(|(o, _)| o.queue == 1).map(|(o, &l)| (o.id as u32, l)).collect()
    }

    /// Brings planet p's stock and shipyard to second t before a fleet touches it; nothing of p's own is due before t.
    fn catch_up(&mut self, cat: &Catalogue, p: usize, t: f64) {
        if t <= self.games[p].t { return }
        cat.advance_to(&mut self.games[p], t, true);
        if let Some(rules) = self.rules.get(p) {
            rules.units.advance(&mut self.yards[p], t as i64);
        }
        self.sync_units(cat, p);
        self.share_research(cat, p);
    }

    /// The economy reads production from the units on the planet while the shipyard owns the counts, so the shipyard's
    /// are copied into the planet by host id whenever they may have changed; nothing else writes `planet.units`.
    fn sync_units(&mut self, cat: &Catalogue, a: usize) {
        let Some(rules) = self.rules.get(a) else { return };
        let counts = &self.yards[a].counts;
        let planet = &mut self.games[a].planet;
        for (u, &id) in cat.units.iter().enumerate() {
            planet.units[u] = rules.units.index(id).map_or(0, |k| counts[k]);
        }
        cat.refresh(planet);
    }

    /// Account a, while it decides, orders `amount` of unit u (units index) in its shipyard; the amount the host would queue.
    pub fn order_units(&mut self, cat: &Catalogue, a: usize, u: usize, amount: u64) -> u64 {
        let rules = Arc::clone(&self.rules[a]);
        let now = self.games[a].t;
        self.catch_up(cat, a, now);
        let upgrading = self.games[a].pending.iter().find(|&&(_, k)| cat.objects[k].queue == 0).map(|&(_, k)| k);
        let queued = rules.units.order(&mut self.yards[a], &mut self.games[a].planet, u, amount, now as i64, upgrading);
        self.sync_units(cat, a);
        self.next[a] = cat.peek(&mut self.games[a]);
        queued
    }

    /// Account a, while it decides, sends ships (host unit id, amount) with cargo to planet `target` at `speed`
    /// (the host's 0.5..10 steps). Pays ships, cargo and fuel from the planet now; refused as the host would refuse it.
    pub fn dispatch(&mut self, cat: &Catalogue, a: usize, target: usize, mission: Mission, ships: &[(u32, i64)], cargo: [f64; 3], speed: f64) -> Result<(), &'static str> {
        if mission == Mission::Colonise { return Err("colonise to coordinates") }
        self.launch(cat, a, Some(target), self.coords[target], mission, ships, cargo, speed)
    }

    /// Account a, while it decides, sends a colony ship (and whatever else is in `ships`) to the free slot `to`.
    /// Refused as the host refuses: no ship, a slot out of range or taken, or not enough astrophysics for that slot.
    pub fn colonise(&mut self, cat: &Catalogue, a: usize, to: Coord, ships: &[(u32, i64)], cargo: [f64; 3], speed: f64) -> Result<(), &'static str> {
        self.launch(cat, a, None, to, Mission::Colonise, ships, cargo, speed)
    }

    /// Makes slot `slot` a planet of the account whose capital is `capital` (the referee sets worlds up this way).
    pub fn join_account(&mut self, slot: usize, capital: usize) {
        self.account[slot] = capital;
        self.colonies[capital].push(slot);
    }

    #[allow(clippy::too_many_arguments)]
    fn launch(&mut self, cat: &Catalogue, a: usize, target: Option<usize>, to: Coord, mission: Mission, ships: &[(u32, i64)], cargo: [f64; 3], speed: f64) -> Result<(), &'static str> {
        let rules = Arc::clone(&self.rules[a]);
        let now = self.games[a].t;
        self.catch_up(cat, a, now);
        if target == Some(a) { return Err("own planet") }
        let own = target.is_some_and(|t| self.account[t] == self.account[a]);
        if own && matches!(mission, Mission::Attack | Mission::Espionage) { return Err("own planet") }
        if !own && mission == Mission::Deploy { return Err("not your planet") }
        let research = self.research(cat, a);
        let mut sent = Vec::new();
        for &(id, amount) in ships.iter().filter(|s| s.1 > 0) {
            let u = rules.units.index(id as i64).ok_or("unknown unit")?;
            if self.yards[a].counts[u] < amount as u64 { return Err("not enough ships") }
            if rules.fleet.speed(id, &research) <= 0 { return Err("a unit that cannot fly") }
            sent.push((id, amount));
        }
        if sent.is_empty() { return Err("no ships") }
        let sends = |ids: &[u32]| sent.iter().any(|(id, _)| ids.contains(id));
        match mission {
            Mission::Espionage if !sends(&rules.combat.missions.espionage_units) => return Err("no probe"),
            Mission::Recycle if !sends(&[self.harvester(a, target.unwrap())]) => return Err("no harvester"),
            Mission::Recycle if !self.fields[target.unwrap()] => return Err("no debris field"),
            Mission::Colonise => self.colonisable(cat, a, to, sends(&[self.colonisation.as_ref().map_or(0, |c| c.ship)]))?,
            _ => {}
        }
        let distance = fleet::distance(&rules.settings, self.coords[a], to, &|_, _| SystemState::Active);
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
        for r in 0..3 { planet.res[r] = as_stored(planet.res[r] - cargo[r]) }
        planet.res[2] = as_stored(planet.res[2] - fuel);
        self.fleets.push(Fleet { owner: a, target: target.unwrap_or(a), to, mission, ships: sent, cargo, fuel, arrive: now + flight, home: now + 2.0 * flight, returning: false });
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
        match self.fleets[f].mission {
            Mission::Deploy => return self.deploy(cat, f),
            Mission::Colonise => return self.found(cat, f),
            _ => {}
        }
        let target = self.fleets[f].target;
        self.catch_up(cat, target, at);
        match self.fleets[f].mission {
            Mission::Deploy | Mission::Colonise => unreachable!("handled above"),
            Mission::Transport => {
                let mut cargo = std::mem::take(&mut self.fleets[f].cargo);
                cargo[2] += self.fleets[f].fuel / 2.0;
                for r in 0..3 { self.games[target].planet.res[r] = as_stored(self.games[target].planet.res[r] + cargo[r]) }
            }
            Mission::Attack => self.attack(cat, f),
            Mission::Espionage => self.espionage(cat, f),
            Mission::Recycle => self.recycle(cat, f),
        }
        self.next[target] = cat.peek(&mut self.games[target]);
        self.fleets[f].returning = true;
        if self.fleets[f].ships.is_empty() {
            self.fleets.swap_remove(f);
        }
    }

    /// DeploymentMission: the ships stay on the target planet with the cargo and half the fuel; nothing flies home.
    fn deploy(&mut self, cat: &Catalogue, f: usize) {
        let fleet = self.fleets.swap_remove(f);
        let mut cargo = fleet.cargo;
        cargo[2] += fleet.fuel / 2.0;
        self.land(cat, fleet.target, fleet.arrive, &fleet.ships, cargo);
    }

    /// The host's slot test for a colony: nothing of this world or outside it stands there.
    fn free_slot(&self, to: Coord) -> bool {
        !self.coords.iter().chain(&self.taken).any(|&c| c == to)
    }

    /// Whether a colony ship may leave for `to` (ColonisationMission::isMissionPossible), given the fleet carries one.
    fn colonisable(&self, cat: &Catalogue, a: usize, to: Coord, has_ship: bool) -> Result<(), &'static str> {
        let table = self.colonisation.as_ref().ok_or("no colonisation tables")?;
        let needs = table.position_levels.get(&to.position).ok_or("not a planet slot")?;
        if !self.free_slot(to) { return Err("slot taken") }
        if self.research(cat, a).get(&(table.research as u32)).copied().unwrap_or(0) < *needs { return Err("astrophysics too low for this slot") }
        if !has_ship { return Err("no colony ship") }
        Ok(())
    }

    /// ColonisationMission::processArrival: the slot still free and the planet limit not reached, a planet comes up
    /// with the fleet's cargo and half its fuel on top of the colony stock and the ship is spent; anything else cancels
    /// the mission and the whole fleet turns home with its cargo.
    fn found(&mut self, cat: &Catalogue, f: usize) {
        let (owner, to, at) = (self.fleets[f].owner, self.fleets[f].to, self.fleets[f].arrive);
        self.catch_up(cat, owner, at);
        let capital = self.account[owner];
        let table = self.colonisation.clone();
        let site = table.as_ref().filter(|t| {
            let level = self.research(cat, owner).get(&(t.research as u32)).copied().unwrap_or(0);
            self.free_slot(to) && self.planets_of(capital).len() < t.max_planets(level)
        }).and_then(|t| self.pick_site(cat, capital, t, to.position));
        let Some(site) = site else {
            self.fleets[f].cargo[2] += (self.fleets[f].fuel / 2.0).floor();
            self.fleets[f].fuel = 0.0;
            self.fleets[f].returning = true;
            self.next[owner] = cat.peek(&mut self.games[owner]);
            return;
        };
        let slot = self.add_planet(cat, owner, site.config, to, at);
        let mut brought = self.fleets[f].cargo;
        brought[2] += self.fleets[f].fuel / 2.0;
        for r in 0..3 { self.games[slot].planet.res[r] = as_stored(self.games[slot].planet.res[r] + brought[r]) }
        self.next[slot] = cat.peek(&mut self.games[slot]);
        let ship = table.map_or(0, |t| t.ship);
        let fleet = &mut self.fleets[f];
        fleet.ships.iter_mut().find(|s| s.0 == ship).expect("a colony ship flew").1 -= 1;
        fleet.ships.retain(|s| s.1 > 0);
        fleet.cargo = [0.0; 3];
        fleet.fuel = 0.0;
        fleet.returning = true;
        if fleet.ships.is_empty() {
            self.fleets.swap_remove(f);
        }
    }

    /// The tabulated colony the host's draw lands on: the referee's draw when it handed one over, otherwise one of the
    /// sites tabulated for this owner's home planet at that slot, by the world's own dice. None when the host's tables
    /// have no such colony (counted in `unplaced`: the mission is then cancelled rather than invented).
    fn pick_site(&mut self, cat: &Catalogue, capital: usize, table: &Colonisation, position: i64) -> Option<Site> {
        let donor = cat.planets[self.games[capital].planet.config].planet_id;
        let sites: Vec<&Site> = table.sites.iter().filter(|s| s.donor == donor && s.position == position).collect();
        let site = match self.recorded_colony.take() {
            Some((temp_max, fields)) => sites.into_iter().find(|s| s.temp_max == temp_max && s.fields == fields),
            None if sites.is_empty() => None,
            None => Some(sites[(next_random(&mut self.rng) % sites.len() as u64) as usize]),
        };
        if site.is_none() { self.unplaced += 1 }
        site.cloned()
    }

    fn land(&mut self, cat: &Catalogue, p: usize, at: f64, ships: &[(u32, i64)], cargo: [f64; 3]) {
        let rules = Arc::clone(&self.rules[p]);
        self.catch_up(cat, p, at);
        for &(id, amount) in ships {
            self.yards[p].counts[rules.units.index(id as i64).unwrap()] += amount as u64;
        }
        for r in 0..3 { self.games[p].planet.res[r] = as_stored(self.games[p].planet.res[r] + cargo[r]) }
        self.next[p] = cat.peek(&mut self.games[p]);
    }

    /// The ship that harvests the field over planet `target` for account a (RecycleMission, by the slot's position).
    fn harvester(&self, a: usize, target: usize) -> u32 {
        let harvesters = &self.rules[a].combat.missions.harvesters;
        *harvesters.get(&self.coords[target].position.to_string()).expect("the export names a harvester for every slot")
    }

    /// Fleet f fights `defender` (host unit id, amount) on its target with `cargo` in its hold, as the host's engine and
    /// settling do, or applies the fight the referee recorded. The defender's units that fought are set to what stood
    /// plus what was repaired, the debris lands in the field, and the fleet keeps its survivors.
    fn fight(&mut self, cat: &Catalogue, f: usize, defender: &[(u32, i64)], cargo: [f64; 3], plunder: bool) -> combat::Outcome {
        let (owner, target) = (self.fleets[f].owner, self.fleets[f].target);
        let (attacker_rules, defender_rules) = (Arc::clone(&self.rules[owner]), Arc::clone(&self.rules[target]));
        let (attacker_research, defender_research) = (self.research(cat, owner), self.research(cat, target));
        // The world's current seed is the fight's, then it draws the next one, so a recorded fight is replayable.
        let seed = self.rng;
        self.rng = next_random(&mut self.rng);
        let battle = Battle {
            attacker: &self.fleets[f].ships,
            attacker_research: &attacker_research,
            attacker_cargo: cargo,
            defender,
            defender_research: &defender_research,
            defender_stock: self.games[target].planet.res,
            defender_engineer: false,
            moon_exists: self.moons[target],
            seed,
        };
        let fought = combat::defender_units(&defender_rules.combat, &battle);
        let outcome = match self.recorded.take() {
            Some(fight) => combat::settle_in(&attacker_rules.combat, &defender_rules.combat, &battle, fight.engine, fight.repaired, fight.moon_roll, plunder),
            None => combat::fight_in(&attacker_rules.combat, &defender_rules.combat, &battle, plunder),
        };
        for (u, unit) in defender_rules.units.units.iter().enumerate() {
            let id = unit.id as u32;
            if fought.contains_key(&id) {
                self.yards[target].counts[u] = (outcome.defender_left.get(&id).copied().unwrap_or(0) + outcome.repaired.get(&id).copied().unwrap_or(0)) as u64;
            }
        }
        for r in 0..3 { self.debris[target][r] += outcome.debris[r] }
        self.fields[target] = true;
        self.fleets[f].ships = outcome.attacker_left.iter().map(|(&id, &n)| (id, n)).collect();
        outcome
    }

    /// The units on planet p (host unit id, amount), defences only or ships only.
    fn held(&self, p: usize, defence: bool) -> Vec<(u32, i64)> {
        let rules = &self.rules[p];
        rules.units.units.iter().zip(&self.yards[p].counts)
            .filter(|(u, &n)| n > 0 && rules.combat.is_defense(u.id as u32) == defence)
            .map(|(u, &n)| (u.id as u32, n as i64)).collect()
    }

    /// AttackMission: the host's battle on the target's ships and defence, then repairs, loot, debris and the moon roll.
    fn attack(&mut self, cat: &Catalogue, f: usize) {
        let target = self.fleets[f].target;
        let mut defender = self.held(target, false);
        defender.extend(self.held(target, true));
        // The fleet enters the fight with its cargo and the half fuel the host hands back inside it
        // (FleetMissionService::getResources): the fuel refund is destructible cargo like the rest.
        let mut cargo = self.fleets[f].cargo;
        cargo[2] += self.fleets[f].fuel / 2.0;
        let outcome = self.fight(cat, f, &defender, cargo, true);
        for r in 0..3 {
            self.games[target].planet.res[r] = as_stored(self.games[target].planet.res[r] - outcome.loot[r] as f64);
            self.games[target].planet.res[r] = as_stored(self.games[target].planet.res[r] + outcome.salvage[r] as f64);
        }
        self.moons[target] |= outcome.moon_created;
        self.fleets[f].cargo = outcome.cargo.map(|r| r as f64);
    }

    /// EspionageMission: the counter-espionage chance and roll, a fight against the target's ships alone (its defence
    /// stands aside, no loot, no moon), then a report as deep as the probes sent and the research gap allow. The probes
    /// that survive fly home with the mission's resources, half the fuel included.
    fn espionage(&mut self, cat: &Catalogue, f: usize) {
        let (owner, target) = (self.fleets[f].owner, self.fleets[f].target);
        let missions = self.rules[owner].combat.missions.clone();
        let level = |w: &World, a: usize| missions.espionage_research.map_or(0, |id| w.research(cat, a).get(&id).copied().unwrap_or(0)) as i64;
        let (spy, guard) = (level(self, owner), level(self, target));
        let probes: i64 = self.fleets[f].ships.iter().filter(|(id, _)| missions.espionage_units.contains(id)).map(|s| s.1).sum();
        let ships = self.held(target, false);
        let guards: i64 = ships.iter().map(|s| s.1).sum();
        // CounterEspionageService::calculateChance.
        let factor = guard - spy + 1;
        let chance = if probes <= 0 || guards <= 0 || factor <= 0 { 0 } else { ((guards * factor) as f64 / (probes * 4) as f64 * 100.0).floor().clamp(0.0, 100.0) as i64 };
        let roll = match self.recorded_roll.take() {
            Some(roll) => roll,
            None if chance > 0 && chance < 100 => 1 + (next_random(&mut self.rng) % 100) as i64,
            None => 0,
        };
        if chance >= 100 || (chance > 0 && roll <= chance) {
            // Only an attack loots and lets collectors haul the field (AttackMission), so here nothing leaves it.
            self.fight(cat, f, &ships, [0.0; 3], false);
        }
        // The depth counts the probes sent, not the ones that survived (EspionageMission::createEspionageReport).
        let left = (probes - (guard - spy).max(0).pow(2)).max(0);
        let reveals = |p: i64, l: i64| left >= p || spy - l >= guard;
        let rules = &self.rules[target];
        let counts = &self.yards[target].counts;
        let only = |defence: bool| rules.units.units.iter().zip(counts).map(|(u, &n)| if rules.combat.is_defense(u.id as u32) == defence { n } else { 0 }).collect::<Vec<u64>>();
        let levels = |queue: usize| cat.objects.iter().zip(&self.games[target].planet.levels).map(|(o, &l)| if o.queue == queue { l } else { 0 }).collect::<Vec<u32>>();
        let report = SpyReport {
            owner,
            target,
            time: self.fleets[f].arrive,
            resources: self.games[target].planet.res.map(|r| r as i64),
            debris: self.fields[target].then_some(self.debris[target]).filter(|d| d.iter().any(|&r| r > 0)),
            counter_chance: chance,
            ships: reveals(2, 1).then(|| only(false)),
            defence: reveals(3, 2).then(|| only(true)),
            buildings: reveals(5, 3).then(|| levels(0)),
            research: reveals(7, 4).then(|| levels(1)),
        };
        self.reports.push(report);
        // The return flight's cargo is an integer column, so an odd fuel loses its half unit.
        self.fleets[f].cargo[2] += (self.fleets[f].fuel / 2.0).floor();
    }

    /// RecycleMission: the slot's harvester ships alone carry, the field is shared out as loot is, and the harvest flies
    /// home on top of the mission's own resources (here the host reads them directly: no half fuel).
    fn recycle(&mut self, cat: &Catalogue, f: usize) {
        let (owner, target) = (self.fleets[f].owner, self.fleets[f].target);
        let harvester = self.harvester(owner, target);
        let count: i64 = self.fleets[f].ships.iter().filter(|(id, _)| *id == harvester).map(|s| s.1).sum();
        let capacity = self.rules[owner].combat.capacity(&[(harvester, count)].into_iter().collect(), &self.research(cat, owner));
        let harvested = combat::distribute_loot(self.debris[target].map(|r| r as f64), capacity);
        for r in 0..3 {
            // The harvest is fractional when the hold cuts it; the field keeps a whole number below what is left.
            self.debris[target][r] = (self.debris[target][r] as f64 - harvested[r]).floor() as i64;
            self.fleets[f].cargo[r] += harvested[r].floor();
        }
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
            w.arm(vec![Arc::clone(&rules); 2], coords.clone());
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
        let u = w.rules[a].units.index(id as i64).unwrap();
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

    /// An account with a colony researches once, at its capital's lab, and every level lands on both planets at the
    /// second it completes; the colony builds on its own queue and never decides research.
    #[test]
    fn research_is_the_accounts_and_each_planet_builds_alone() {
        let Some((cat, mut world, _)) = pair(240.0) else { return eprintln!("no u1 catalogues; skipped") };
        let colony = world.add_planet(&cat, 0, 0, Coord { galaxy: 1, system: 11, position: 6 }, 0.0);
        assert_eq!((world.planets_of(0), world.account_of(colony)), (vec![0, colony], 0));
        // A lab and a full store, so the capital has research to start.
        let lab = (0..cat.objects.len()).find(|&b| cat.objects[b].queue == 0 && cat.objects.iter().any(|r| r.queue == 1 && r.requires.contains(&(Some(b), 1)))).expect("a building that unlocks research");
        world.games[0].planet.levels[lab] = 3;
        world.games[0].planet.res = [1e6; 3];
        cat.refresh(&mut world.games[0].planet);
        let mut rngs = vec![5u64, 6, 7];
        let mut last = 0.0;
        while let Some(a) = world.next_decision(&cat) {
            assert!(world.games[a].t >= last, "the world clock went back");
            last = world.games[a].t;
            let game = &world.games[a];
            if a == colony { assert_eq!(game.queue, 0, "the colony decided research") }
            let pick = match game.queue {
                1 => (0..cat.objects.len()).find(|&k| world.legal(&cat, a, k)),
                _ => cat.baseline(&game.planet, game.queue, Baseline::Player, &mut rngs[a]),
            };
            match pick {
                Some(k) => world.commit(&cat, a, k),
                None => world.wait(&cat, a),
            }
        }
        let research = |a: usize| world.research(&cat, a);
        assert!(research(0).values().any(|&l| l > 0), "nothing was researched");
        assert_eq!(research(0), research(colony), "the colony has the account's research");
        let built = |a: usize| world.games[a].history.iter().filter(|h| cat.objects[h.1].queue == 1).count();
        assert!(built(0) > 0 && built(colony) == 0, "research is paid and timed at the capital's lab only");
        assert!(!world.games[colony].history.is_empty(), "the colony built nothing");
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
        // The host hands half the fuel back inside the mission's cargo, so only half of it is spent on the raid.
        let won: Vec<f64> = (0..3).map(|r| raided.games[0].planet.res[r] - quiet.games[0].planet.res[r] + if r == 2 { fuel / 2.0 } else { 0.0 }).collect();
        assert!(lost.iter().sum::<f64>() > 0.0, "nothing looted");
        for r in 0..3 { assert!((lost[r] - won[r]).abs() < 1e-6, "resource {r}: lost {} won {}", lost[r], won[r]) }
        assert_eq!(raided.yards[0].counts, quiet.yards[0].counts, "no defence, no losses");
    }

    /// The host's own answers to missions it flew (`ai:rl-mission-cases`): a world that starts at the case's planets must
    /// reproduce the host's departure, flight, fuel, stock, ships, debris and moon, second for second.
    mod mission_parity {
        use super::super::*;
        use super::DATA;
        use serde::Deserialize;
        use std::collections::HashMap;

        #[derive(Deserialize)]
        struct Cases { cases: Vec<Case> }

        #[derive(Deserialize)]
        struct Case {
            mission: String,
            input: Input,
            dispatch: Dispatch,
            arrival: Option<Arrival>,
            #[serde(rename = "return")]
            back: Option<Back>,
        }

        #[derive(Deserialize)]
        struct Input {
            origin: PlanetState,
            target: Option<PlanetState>,
            origin_player: PlayerState,
            target_player: Option<PlayerState>,
            units: HashMap<String, i64>,
            cargo: [f64; 3],
            speed: f64,
            #[serde(default)] target_coordinates: Option<[i64; 3]>,
            #[serde(default)] max_planets: Option<usize>,
            #[serde(default)] debris_before: Option<[i64; 3]>,
        }

        #[derive(Deserialize)]
        struct PlanetState {
            id: i64,
            coordinates: [i64; 3],
            resources: [f64; 3],
            #[serde(default)] ships: HashMap<String, i64>,
            #[serde(default)] defence: HashMap<String, i64>,
        }

        #[derive(Deserialize)]
        struct PlayerState { #[serde(default)] id: i64, class: u32, research: HashMap<String, u32> }

        #[derive(Deserialize)]
        struct State { resources: [f64; 3], #[serde(default)] ships: HashMap<String, i64>, #[serde(default)] defence: HashMap<String, i64> }

        #[derive(Deserialize)]
        struct Dispatch { accepted: bool, departure: Option<f64>, arrival: Option<f64>, fuel: Option<f64>, origin_after: Option<State> }

        #[derive(Deserialize)]
        struct Arrival {
            time: f64,
            #[serde(default)] target_before: Option<State>,
            target_after: Option<State>,
            #[serde(default)] debris_after: Option<[i64; 3]>,
            /// `false` when no moon came up, and the moon itself when one did.
            #[serde(default)] moon: Option<serde_json::Value>,
            return_mission: Option<Again>,
            /// Colonise: why the host cancelled it (`astrophysics`, `slot_taken`), the sender's planets before the
            /// arrival, and the planet that came up with the draws that made it.
            #[serde(default)] refusal: Option<String>,
            #[serde(default)] planet_created: bool,
            #[serde(default)] planets_before: Option<usize>,
            #[serde(default)] planets_after: Option<usize>,
            #[serde(default)] new_planet: Option<NewPlanet>,
            #[serde(default)] battle: Option<BattleAnswer>,
            #[serde(default)] espionage: Option<Spy>,
        }

        #[derive(Deserialize)]
        struct NewPlanet { coordinates: [i64; 3], resources: [f64; 3], temp_max: i64, field_max: i64 }

        /// The host's espionage report and, when the probes were met by a fight, that fight (the same shape as an attack's).
        #[derive(Deserialize)]
        struct Spy {
            counter_espionage_chance: i64,
            roll: Option<i64>,
            counter_battle: bool,
            battle_seed: Option<u64>,
            sections: Vec<String>,
            resources: Vec<i64>,
            debris: Option<[i64; 3]>,
            revealed: HashMap<String, HashMap<String, i64>>,
            #[serde(default)] battle: Option<Report>,
        }

        #[derive(Deserialize)]
        struct Again { departure: f64, arrival: f64, #[serde(default)] cargo: Option<[i64; 3]>, #[serde(default)] units: Option<HashMap<String, i64>> }

        #[derive(Deserialize)]
        struct BattleAnswer { #[serde(default)] seed: u64, #[serde(default)] report: Option<Report> }

        /// The host's own fight: its survivors, the defences it repaired, and whether its moon roll made a moon.
        #[derive(Deserialize)]
        struct Report {
            #[serde(default)] rounds: u32,
            #[serde(default)] attacker_left: serde_json::Value,
            #[serde(default)] defender_left: serde_json::Value,
            #[serde(default)] repaired: serde_json::Value,
            #[serde(default)] moon_chance: i64,
            #[serde(default)] moon_created: bool,
        }

        #[derive(Deserialize)]
        struct Back { time: f64, origin_after: Option<State> }

        /// The fleet settings and combat tables of one character class: the class changes fuel and loot, and the export
        /// writes the plain files for its own player's class and `-class2`/`-class3` for the others.
        fn rules_for(cat: &Catalogue, class: u32) -> Option<Arc<Rules>> {
            let suffix = match class {
                2 => "-class2",
                3 => "-class3",
                _ => "",
            };
            Rules::load(
                cat,
                &format!("{DATA}/units-parity/units-u1.json"),
                &format!("{DATA}/fleet-settings-u1{suffix}.json"),
                &format!("{DATA}/combat-u1{suffix}.json"),
            ).ok().map(Arc::new)
        }

        fn config_of(cat: &Catalogue, id: i64) -> Option<usize> {
            cat.planets.iter().position(|p| p.planet_id == id)
        }

        /// Plays every fleet event and account tick due by `t`, and nothing after it.
        fn run_until(cat: &Catalogue, w: &mut World, t: f64) {
            let mut steps = 0usize;
            loop {
                steps += 1;
                assert!(steps < 100_000, "the world's clock stopped before {t}: at {:?} next {:?} free {:?} commit {:?} pending {:?}", w.now(), w.next, w.games[0].free, w.games[0].commit, w.games[0].pending);
                let a = (0..w.games.len()).filter(|&a| !w.games[a].done).min_by(|&x, &y| w.next[x].total_cmp(&w.next[y]));
                let fleet = (0..w.fleets.len()).min_by(|&x, &y| w.fleets[x].next().total_cmp(&w.fleets[y].next()));
                let account_at = a.map_or(f64::INFINITY, |a| w.next[a]);
                if let Some(f) = fleet.filter(|&f| w.fleets[f].next() <= t && w.fleets[f].next() <= account_at) {
                    w.fleet_event(cat, f);
                    continue;
                }
                let Some(a) = a.filter(|_| account_at <= t) else { return };
                if w.step_account(cat, a) { cat.decide(&mut w.games[a], None) }
                w.next[a] = cat.peek(&mut w.games[a]);
            }
        }

        fn units_of(map: &HashMap<String, i64>) -> Vec<(u32, i64)> {
            map.iter().filter(|(_, &n)| n > 0).map(|(id, &n)| (id.parse().unwrap(), n)).collect()
        }

        /// A unit map straight from the case file, where PHP writes an empty one as `[]`.
        fn counted(value: &serde_json::Value) -> combat::Units {
            value.as_object().map_or_else(combat::Units::new, |m| {
                m.iter().filter_map(|(id, n)| Some((id.parse().ok()?, n.as_i64()?))).filter(|&(_, n)| n > 0).collect()
            })
        }

        fn holds(w: &World, a: usize) -> HashMap<String, i64> {
            let units = &w.rules[a].units;
            units.units.iter().zip(&w.yards[a].counts).filter(|(_, &n)| n > 0)
                .map(|(u, &n)| (u.id.to_string(), n as i64)).collect()
        }

        fn stock(state: Option<&State>, w: &World, a: usize, case: usize, when: &str) {
            let Some(state) = state else { return };
            let planet = &w.games[a].planet;
            for r in 0..3 {
                assert!((planet.res[r] - state.resources[r]).abs() < 1e-6, "case {case} {when}: resource {r} world {} host {}", planet.res[r], state.resources[r]);
            }
            let mut host = state.ships.clone();
            host.extend(state.defence.clone());
            assert_eq!(holds(w, a), host, "case {case} {when}: units on the planet");
        }

        fn coords(planet: &PlanetState) -> Coord {
            Coord { galaxy: planet.coordinates[0], system: planet.coordinates[1], position: planet.coordinates[2] }
        }

        /// What a case needs beyond the home planets: the colonisation tables and, per home planet, a planet config for
        /// the planets the catalogue has no tables for (a deploy's second planet, the sender's other colonies).
        struct Setup {
            colonisation: Option<Arc<Colonisation>>,
            fresh: Vec<usize>,
            /// Where the host's universe has planets, as far as its cases show them (a slot the world does not hold).
            universe: Vec<Coord>,
        }

        #[test]
        fn replays_the_hosts_missions() {
            // Transports are the mission the world plays end to end by default; the others run with OGSIM_MISSION=attack,
            // espionage, recycle, deploy or colonise (colonise reads the second case file and the catalogue with the
            // colonies the host drew: `ai:rl-catalogue ... --sites`).
            let only = std::env::var("OGSIM_MISSION").unwrap_or_else(|_| "transport".into());
            let (cases_file, econ_file) = match only.as_str() {
                "colonise" => ("more-mission-cases-u1.json", "units-parity/econ-u1-colonies.json"),
                _ => ("mission-cases-u1.json", "units-parity/econ-u1.json"),
            };
            let Ok(text) = std::fs::read_to_string(format!("{DATA}/{cases_file}")) else { return eprintln!("no mission cases; skipped") };
            let cases: Cases = serde_json::from_str(&text).expect("mission cases");
            let Ok(mut cat) = Catalogue::load(&format!("{DATA}/{econ_file}")) else { return eprintln!("no catalogue {econ_file}; skipped") };
            cat.login_gaps = vec![1800.0];
            let homes = cat.planets.iter().take_while(|p| p.planet_id > 0).count();
            let fresh: Vec<usize> = (0..homes).map(|c| {
                let config = fresh_config(&cat, c, -1_000_000 - c as i64);
                cat.planets.push(config);
                cat.planets.len() - 1
            }).collect();
            let colonisation = Colonisation::load(&format!("{DATA}/{econ_file}")).ok().map(Arc::new);
            let mut universe: Vec<Coord> = cases.cases.iter().flat_map(|c| [Some(&c.input.origin), c.input.target.as_ref()]).flatten().map(coords).collect();
            universe.sort_by_key(|c| (c.galaxy, c.system, c.position));
            universe.dedup();
            let setup = Setup { colonisation, fresh, universe };
            let (mut flown, mut refused) = (0usize, 0usize);
            let limit: usize = std::env::var("OGSIM_CASES").ok().and_then(|n| n.parse().ok()).unwrap_or(usize::MAX);
            let played = cases.cases.iter().enumerate().take(limit).filter(|(_, c)| c.mission == only);
            // Every case runs, and a case that differs is reported rather than stopping the run: one pass must name all
            // the host's answers the world still gets wrong.
            let mut wrong: Vec<String> = Vec::new();
            for (index, case) in played {
                if index % 50 == 0 { eprintln!("case {index}...") }
                match std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| play_case(&cat, case, index, &setup))) {
                    Ok(Some(true)) => flown += 1,
                    Ok(Some(false)) => refused += 1,
                    Ok(None) => {}
                    Err(failure) => wrong.push(said(failure.as_ref())),
                }
            }
            eprintln!("mission parity: {flown} mission(s) flown as the host flew them, {refused} refused as the host refused them, {} differ", wrong.len());
            assert!(flown > 0, "no mission was flown");
            assert!(wrong.is_empty(), "the world plays these missions differently from the host:\n{}", wrong.join("\n"));
        }

        /// The first line of a failing case's panic.
        fn said(failure: &(dyn std::any::Any + Send)) -> String {
            failure.downcast_ref::<String>().and_then(|s| s.lines().next()).map(str::to_string)
                .or_else(|| failure.downcast_ref::<&str>().map(|s| s.to_string()))
                .unwrap_or_else(|| "panicked".into())
        }

        fn recorded(report: &Report) -> combat::Recorded {
            combat::Recorded {
                engine: combat::EngineResult {
                    rounds: report.rounds,
                    attacker_left: counted(&report.attacker_left),
                    defender_left: counted(&report.defender_left),
                },
                repaired: counted(&report.repaired),
                moon_roll: if report.moon_created { report.moon_chance } else { report.moon_chance + 1 },
            }
        }

        /// The report the world wrote for the spying account against the host's: chance, the sections the probes reached,
        /// what each shows, the resources and the debris seen, and whether the probes lived.
        fn spied(cat: &Catalogue, spy: &Spy, world: &World, index: usize) {
            let report = world.reports.last().unwrap_or_else(|| panic!("case {index}: the world wrote no report"));
            assert_eq!(report.counter_chance, spy.counter_espionage_chance, "case {index}: counter-espionage chance");
            let mut sections: Vec<&str> = [("ships", report.ships.is_some()), ("defence", report.defence.is_some()), ("buildings", report.buildings.is_some()), ("research", report.research.is_some())]
                .into_iter().filter(|s| s.1).map(|s| s.0).collect();
            let mut host: Vec<&str> = spy.sections.iter().map(String::as_str).collect();
            sections.sort_unstable();
            host.sort_unstable();
            assert_eq!(sections, host, "case {index}: sections revealed");
            assert_eq!(report.resources.to_vec(), spy.resources[..3].to_vec(), "case {index}: resources seen");
            assert_eq!(report.debris, spy.debris, "case {index}: debris seen");
            let units = |counts: &Option<Vec<u64>>| counts.as_ref().map(|c| {
                world.rules[report.target].units.units.iter().zip(c).filter(|(_, &n)| n > 0).map(|(u, &n)| (u.id.to_string(), n as i64)).collect::<HashMap<_, _>>()
            });
            // The host keys buildings by machine name, so only the units and research it shows by id are compared.
            assert_eq!(units(&report.ships), spy.revealed.get("ships").cloned(), "case {index}: ships seen");
            assert_eq!(units(&report.defence), spy.revealed.get("defence").cloned(), "case {index}: defence seen");
            let research = report.research.as_ref().map(|levels| {
                cat.objects.iter().zip(levels).filter(|(o, &l)| o.queue == 1 && l > 0).map(|(o, &l)| (o.id.to_string(), l as i64)).collect::<HashMap<_, _>>()
            });
            assert_eq!(research, spy.revealed.get("research").cloned(), "case {index}: research seen");
        }

        /// One case end to end: `Some(true)` when the host flew it and the world did too, `Some(false)` when both refused
        /// it, `None` when it cannot be set up from the catalogue (another planet, another mission).
        fn play_case(cat: &Catalogue, case: &Case, index: usize, setup: &Setup) -> Option<bool> {
            if case.mission == "colonise" {
                return play_colonise(cat, case, index, setup);
            }
            let deploying = case.mission == "deploy";
            // A recycler flies to a debris field, not a planet: the world needs some planet under the field, so any other
            // account of the catalogue stands in for it, at the field's coordinates and with the sender's class.
            let recycling = case.mission == "recycle";
            // Nothing at the coordinates to spy on: the world has no planet to send to, and the host refused it too.
            if case.input.target.is_none() && !recycling && !case.dispatch.accepted {
                return Some(false);
            }
            let stand_in;
            let (target, target_player) = if recycling {
                let at = case.input.target_coordinates?;
                stand_in = PlanetState { id: -1, coordinates: at, resources: [0.0; 3], ships: HashMap::new(), defence: HashMap::new() };
                (&stand_in, &case.input.origin_player)
            } else {
                (case.input.target.as_ref()?, case.input.target_player.as_ref()?)
            };
            // Each side fights with its own player's class, so each side gets its own tables.
            let attacker_rules = rules_for(cat, case.input.origin_player.class)?;
            let defender_rules = rules_for(cat, target_player.class)?;
            // The catalogue holds the AI home planets; a case on any other planet cannot be set up and is skipped.
            let origin_config = config_of(cat, case.input.origin.id)?;
            // A deploy goes to the sender's second planet, which the catalogue has no tables for: the sender's home config
            // stands in (its buildings are cleared below, and the planet's production is switched off).
            let target_config = match () {
                _ if recycling => (0..cat.planets.len()).find(|&c| c != origin_config)?,
                _ if deploying => config_of(cat, target.id).unwrap_or(origin_config),
                _ => config_of(cat, target.id)?,
            };
            let mut world = World::new(cat, &[origin_config, target_config], 1e6, 0.0, 1);
            world.arm(vec![attacker_rules, defender_rules], vec![coords(&case.input.origin), coords(target)]);
            if let Some(debris) = case.input.debris_before {
                world.debris[1] = debris;
                world.fields[1] = true;
            }
            if deploying && target_player.id == case.input.origin_player.id {
                world.join_account(1, 0);
            }
            for (a, player) in [(0, &case.input.origin_player), (1, target_player)] {
                for (id, &level) in &player.research {
                    let k = cat.objects.iter().position(|o| o.id == id.parse::<i64>().unwrap() && o.queue == 1).expect("a research in the catalogue");
                    world.games[a].planet.levels[k] = level;
                }
            }
            for (a, planet) in [(0, &case.input.origin), (1, target)] {
                let mut held = planet.ships.clone();
                held.extend(planet.defence.clone());
                for (id, amount) in units_of(&held) {
                    let u = world.rules[a].units.index(id as i64).expect("a unit in the catalogue");
                    world.yards[a].counts[u] = amount as u64;
                }
                world.games[a].planet.res = planet.resources;
                world.games[a].t = case.dispatch.departure.unwrap_or(0.0);
                // The login clock walks second by second from its last entry, so it starts where the case does.
                world.games[a].login_times = vec![world.games[a].t];
                cat.refresh(&mut world.games[a].planet);
            }
            let mission = match case.mission.as_str() {
                "attack" => Mission::Attack,
                "espionage" => Mission::Espionage,
                "recycle" => Mission::Recycle,
                "deploy" => Mission::Deploy,
                _ => Mission::Transport,
            };
            if deploying {
                // The host's second planets did not produce between the dispatch and the arrival.
                for k in (0..cat.objects.len()).filter(|&k| cat.objects[k].queue == 0) {
                    world.games[1].planet.levels[k] = 0;
                }
                world.games[1].planet.rate = [0.0; 3];
            }
            let before = world.games[0].planet.res;
            let flew = world.dispatch(cat, 0, 1, mission, &units_of(&case.input.units), case.input.cargo, case.input.speed);
            if !case.dispatch.accepted {
                assert!(flew.is_err(), "case {index}: the host refused this mission and the world flew it");
                return Some(false);
            }
            flew.unwrap_or_else(|e| panic!("case {index}: the host flew this mission and the world refused it: {e}"));
            let (departure, arrival, host_fuel) = (
                case.dispatch.departure.expect("a departure on an accepted mission"),
                case.dispatch.arrival.expect("an arrival on an accepted mission"),
                case.dispatch.fuel.expect("fuel on an accepted mission"),
            );
            assert_eq!(world.games[0].t, departure, "case {index}: departure");
            assert_eq!(world.fleets[0].arrive, arrival, "case {index}: arrival");
            let fuel = before[2] - case.input.cargo[2] - world.games[0].planet.res[2];
            assert!((fuel - host_fuel).abs() < 1e-6, "case {index}: fuel {fuel} host {host_fuel}");
            stock(case.dispatch.origin_after.as_ref(), &world, 0, index, "after dispatch");
            let Some(arrival) = &case.arrival else { return Some(true) };
            if let Some(battle) = &arrival.battle {
                world.rng = battle.seed;
                // The host fought with PHP's dice after the battle too, so the referee hands its fight over rather than
                // ask the world to roll the same numbers again.
                if let Some(report) = &battle.report {
                    world.recorded = Some(recorded(report));
                }
            }
            if let Some(spy) = &arrival.espionage {
                // The host's roll and fight are handed over as an attack's fight is.
                world.recorded_roll = spy.roll;
                world.rng = spy.battle_seed.unwrap_or(0);
                if let Some(report) = &spy.battle {
                    world.recorded = Some(recorded(report));
                }
            }
            if let (true, Some(before)) = (deploying, &arrival.target_before) {
                // The host's second planet had been producing with buildings the catalogue knows nothing about, so the
                // referee hands over its stock at the arrival.
                world.games[1].planet.res = before.resources;
                world.games[1].t = arrival.time;
            }
            run_until(cat, &mut world, arrival.time);
            if let Some(spy) = &arrival.espionage {
                spied(cat, spy, &world, index);
            }
            if let (Some(back), true) = (arrival.return_mission.as_ref().and_then(|b| b.cargo), case.mission != "attack") {
                assert_eq!(world.fleets[0].cargo.map(|r| r as i64), back, "case {index}: the cargo flying home");
            }
            stock(arrival.target_after.as_ref(), &world, 1, index, "at arrival");
            if deploying {
                assert!(world.fleets.is_empty(), "case {index}: a deployed fleet stays on the planet");
            }
            if let Some(debris) = arrival.debris_after {
                assert_eq!(world.debris[1], debris, "case {index}: debris at arrival");
            }
            if let Some(moon) = &arrival.moon {
                assert_eq!(world.moons[1], moon.as_bool().unwrap_or(!moon.is_null()), "case {index}: moon at arrival");
            }
            let Some(back) = case.arrival.as_ref().and_then(|a| a.return_mission.as_ref()) else { return Some(true) };
            let home = world.fleets.iter().find(|f| f.owner == 0).expect("the fleet comes home");
            assert_eq!((home.arrive, home.home), (back.departure, back.arrival), "case {index}: the flight home");
            let Some(done) = &case.back else { return Some(true) };
            run_until(cat, &mut world, done.time);
            stock(done.origin_after.as_ref(), &world, 0, index, "back home");
            Some(true)
        }

        /// A colony ship case: the dispatch rules, the arrival (a planet comes up or the fleet is sent home) and the
        /// sender's planets, stock and research afterwards, against the host's.
        fn play_colonise(cat: &Catalogue, case: &Case, index: usize, setup: &Setup) -> Option<bool> {
            let table = Arc::clone(setup.colonisation.as_ref()?);
            let origin_config = config_of(cat, case.input.origin.id)?;
            let to = case.input.target_coordinates.map(|c| Coord { galaxy: c[0], system: c[1], position: c[2] })?;
            let mut world = World::new(cat, &[origin_config], 1e6, 0.0, 1);
            world.arm(vec![rules_for(cat, case.input.origin_player.class)?], vec![coords(&case.input.origin)]);
            world.colonisation = Some(Arc::clone(&table));
            for (id, &level) in &case.input.origin_player.research {
                let k = cat.objects.iter().position(|o| o.id == id.parse::<i64>().unwrap() && o.queue == 1).expect("a research in the catalogue");
                world.games[0].planet.levels[k] = level;
            }
            let held = &case.input.origin;
            let mut units = held.ships.clone();
            units.extend(held.defence.clone());
            for (id, amount) in units_of(&units) {
                let u = world.rules[0].units.index(id as i64).expect("a unit in the catalogue");
                world.yards[0].counts[u] = amount as u64;
            }
            world.games[0].planet.res = held.resources;
            world.games[0].t = case.dispatch.departure.unwrap_or(0.0);
            world.games[0].login_times = vec![world.games[0].t];
            cat.refresh(&mut world.games[0].planet);
            // The sender's other planets only count toward the limit.
            for i in 1..case.arrival.as_ref().and_then(|a| a.planets_before).unwrap_or(1) {
                world.add_planet(cat, 0, setup.fresh[origin_config], Coord { galaxy: 9, system: 400 + i as i64, position: 1 }, world.games[0].t);
            }
            world.taken.extend(setup.universe.iter().copied().filter(|&c| c != coords(&case.input.origin)));
            let level = world.research(cat, 0).get(&(table.research as u32)).copied().unwrap_or(0);
            if let Some(max) = case.input.max_planets {
                assert_eq!(table.max_planets(level), max, "case {index}: the planet limit at astrophysics {level}");
            }
            let before = world.games[0].planet.res;
            let planets = world.games.len();
            let flew = world.colonise(cat, 0, to, &units_of(&case.input.units), case.input.cargo, case.input.speed);
            if !case.dispatch.accepted {
                assert!(flew.is_err(), "case {index}: the host refused this mission and the world flew it");
                return Some(false);
            }
            flew.unwrap_or_else(|e| panic!("case {index}: the host flew this mission and the world refused it: {e}"));
            let (departure, arrive, host_fuel) = (case.dispatch.departure?, case.dispatch.arrival?, case.dispatch.fuel?);
            assert_eq!(world.games[0].t, departure, "case {index}: departure");
            assert_eq!(world.fleets[0].arrive, arrive, "case {index}: arrival");
            let fuel = before[2] - case.input.cargo[2] - world.games[0].planet.res[2];
            assert!((fuel - host_fuel).abs() < 1e-6, "case {index}: fuel {fuel} host {host_fuel}");
            stock(case.dispatch.origin_after.as_ref(), &world, 0, index, "after dispatch");
            let arrival = case.arrival.as_ref()?;
            if arrival.refusal.as_deref() == Some("slot_taken") {
                world.taken.push(to);
            }
            if let Some(planet) = &arrival.new_planet {
                world.recorded_colony = Some((planet.temp_max, planet.field_max));
            }
            run_until(cat, &mut world, arrival.time);
            assert_eq!(world.games.len() > planets, arrival.planet_created, "case {index}: a planet comes up (host refusal {:?}, unplaced {})", arrival.refusal, world.unplaced);
            if let (Some(planet), true) = (&arrival.new_planet, arrival.planet_created) {
                let slot = world.games.len() - 1;
                assert_eq!(world.coords[slot], Coord { galaxy: planet.coordinates[0], system: planet.coordinates[1], position: planet.coordinates[2] }, "case {index}: the colony's slot");
                assert_eq!(world.account_of(slot), 0, "case {index}: the colony belongs to the sender");
                for r in 0..3 {
                    assert!((world.games[slot].planet.res[r] - planet.resources[r]).abs() < 1e-6, "case {index}: colony stock {r} world {} host {}", world.games[slot].planet.res[r], planet.resources[r]);
                }
                for k in (0..cat.objects.len()).filter(|&k| cat.objects[k].queue == 1) {
                    assert_eq!(world.games[slot].planet.levels[k], world.games[0].planet.levels[k], "case {index}: the colony shares the account's research");
                }
                assert_eq!(world.planets_of(0).len(), arrival.planets_after?, "case {index}: the sender's planets after");
            }
            let Some(back) = arrival.return_mission.as_ref() else {
                assert!(world.fleets.is_empty(), "case {index}: no ship is left to fly home");
                return Some(true);
            };
            let home = world.fleets.iter().find(|f| f.owner == 0).expect("the fleet comes home");
            assert_eq!((home.arrive, home.home), (back.departure, back.arrival), "case {index}: the flight home");
            let mut ships: HashMap<String, i64> = home.ships.iter().map(|&(id, n)| (id.to_string(), n)).collect();
            ships.retain(|_, n| *n > 0);
            assert_eq!(Some(ships), back.units.clone(), "case {index}: the ships flying home");
            if let Some(cargo) = back.cargo {
                assert_eq!(home.cargo.map(|r| r as i64), cargo, "case {index}: the cargo flying home");
            }
            let Some(done) = &case.back else { return Some(true) };
            run_until(cat, &mut world, done.time);
            stock(done.origin_after.as_ref(), &world, 0, index, "back home");
            Some(true)
        }
    }
}

