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

use crate::HashMap;
use std::sync::Arc;

use crate::combat::{self, Battle, CombatCatalogue};
use crate::economy::{as_stored, next_random, weighted, Books, Catalogue, Game, PlanetConfig};
use crate::expedition::{self, Draws};
use crate::fleet::{self, Coord, FleetCatalogue, FleetSettings, SystemState};
use crate::strike::{self, StrikeRules};
use crate::units::{Shipyard, Units};

mod strikes;

/// The largest moon the host makes (x = 20 over 2 000 000 debris), so that deathstars can always destroy one.
const MOON_DIAMETER_CAP: i64 = 8944;
/// A second no clock reaches.
const NEVER: f64 = f64::INFINITY;
/// An account that has not logged in for this long is inactive (OGame's 'i' status).
pub const INACTIVE_SECONDS: f64 = 7.0 * 86_400.0;



/// The host's tables for everything past the economy, read once and shared by every world.
pub struct Rules {
    pub units: Units,
    pub settings: FleetSettings,
    pub fleet: FleetCatalogue,
    pub combat: CombatCatalogue,
    /// The `strike` and `expedition` sections of the combat catalogue; a catalogue without them refuses those missions.
    pub strike: Option<StrikeRules>,
    pub expedition: Option<expedition::Rules>,
}

impl Rules {
    /// The units export, the fleet settings and the combat catalogue (which also holds speeds and fuel) of one universe.
    pub fn load(cat: &Catalogue, units: &str, settings: &str, combat: &str) -> Result<Rules, String> {
        Ok(Rules {
            units: Units::load(units, cat)?,
            settings: FleetSettings::load(settings)?,
            fleet: FleetCatalogue::load(combat)?,
            combat: CombatCatalogue::load(combat)?,
            strike: StrikeRules::load(combat).ok(),
            expedition: expedition::Rules::load(combat).ok(),
        })
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
    MoonDestroy = 9,
    Missile = 10,
    Expedition = 15,
}

/// What an event in the log is about.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum EventKind {
    /// A fleet reached its target and the mission acted (a missile struck, an expedition came back with its find).
    Arrival,
    /// A battle was fought: by an attack, a moon destruction, an espionage that met a counter-fight or an expedition.
    Fight,
    /// A fleet came home and its ships and cargo landed.
    Return,
    /// A fleet is gone: wiped out, swallowed or spent.
    Lost,
    /// The sender turned a fleet home early.
    Recall,
}

/// How an event ended.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Outcome {
    /// Nothing contested: a delivery, a report, a landing.
    Done,
    /// The fleet held the field (a fight's attacker won).
    Won,
    /// The fleet was beaten.
    Defeated,
    /// Both sides stood after the last round.
    Draw,
    /// The mission could not do its work and the fleet turned home (a colony slot taken, a limit reached).
    Cancelled,
    /// An expedition's find, as the dice picked it after the host's fallbacks.
    Found(expedition::Kind),
    /// A moon destruction destroyed the moon.
    MoonDestroyed,
}

/// One thing that happened to a fleet, in the order the world played it; players read results here.
#[derive(Clone, Debug, PartialEq)]
pub struct Event {
    pub kind: EventKind,
    pub time: f64,
    pub mission: Mission,
    /// The slot that sent the fleet and the slot it went to (the sender's own for coordinates nobody holds).
    pub owner: usize,
    pub target: usize,
    /// The second the fleet left. A trainer reading the log can tie an event back to the decision that dispatched it,
    /// which is what credit attribution needs.
    pub departed: f64,
    /// Ships the fleet lost.
    pub ships_lost: Vec<(u32, i64)>,
    /// Ships and defences the target lost for good (repairs taken out).
    pub target_lost: Vec<(u32, i64)>,
    /// The ships (not defences) standing on the target when a fight began: what a fleet save would have taken away.
    pub present: Vec<(u32, i64)>,
    /// Resources gained: the loot of a fight, the harvest of a recycler, the cargo a delivery or a landing carried.
    pub gained: [f64; 3],
    /// Debris a fight left over the target.
    pub debris: [i64; 3],
    /// The moon chance the host rolled against, and whether that roll made a moon (row 17). Zero when the debris was
    /// under the host's threshold or a moon already stood - so a non-zero chance is exactly "this fight rolled".
    pub moon_chance: i64,
    pub moon_created: bool,
    pub outcome: Outcome,
    /// The seed of the arrival's dice: its draws are `Randomizer(Mt19937(seed))`'s, as the host's are (0: none drawn).
    pub seed: u32,
    /// The fleet's `id`.
    pub fleet: usize,
}

/// What a moon holds: it has its own diameter, stock and units (in its owner's `Units` order), and makes nothing.
#[derive(Clone, Debug, Default)]
pub struct MoonHold {
    pub diameter: i64,
    pub stock: [f64; 3],
    pub counts: Vec<u64>,
}

/// What kind of decision an account faces at a login.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Decision {
    /// A queue is free and something is legal: the economy's choice, plus fleet and shipyard actions.
    Build,
    /// Nothing to build at this login: only fleet and shipyard actions (`wait` lets it pass).
    Idle,
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
    /// The host's expedition slot limit per level of the same research; empty in an export that predates it (no limit).
    pub max_expeditions: Vec<usize>,
    /// The research behind the host's fleet limit and the limit per level, without class slots; absent in older exports (no limit).
    pub fleet_research: Option<u32>,
    pub max_fleets: Vec<usize>,
    /// The host's size range per position (`PlanetServiceFactory::planetData` plus the server bonus); absent in older
    /// exports, where a colony keeps its tabulated site's size.
    pub field_ranges: HashMap<i64, (u32, u32)>,
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
            max_expeditions: block["max_expeditions"].as_array().map_or(Vec::new(), |a| a.iter().filter_map(|n| n.as_u64()).map(|n| n as usize).collect()),
            fleet_research: block["fleet_research"].as_u64().map(|n| n as u32),
            max_fleets: block["max_fleets"].as_array().map_or(Vec::new(), |a| a.iter().filter_map(|n| n.as_u64()).map(|n| n as usize).collect()),
            field_ranges: block["field_ranges"].as_object().map_or(HashMap::default(), |o| o.iter()
                .filter_map(|(p, r)| Some((p.parse().ok()?, (r[0].as_u64()? as u32, r[1].as_u64()? as u32)))).collect()),
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

    /// The host's expedition slots at a research level, `None` when the export has no table.
    pub fn max_expeditions(&self, level: u32) -> Option<usize> {
        self.max_expeditions.get((level as usize).min(self.max_expeditions.len().saturating_sub(1))).copied()
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
    /// The second it left, which a recall measures the time flown from.
    pub departed: f64,
    /// Seconds it stays at the target after arriving (an expedition's hold), real time.
    pub holding: f64,
    /// A missile strike's priority code.
    pub priority: usize,
    /// The fleet goes for the target slot's moon rather than its planet (always so for a moon destruction).
    pub moon: bool,
    /// Its index in `launch_log`, which its events carry (W14: fleets leaving in one second stay apart).
    pub id: usize,
}

impl Fleet {
    /// The second the world next acts on this fleet: its arrival and hold, or its landing at home.
    pub fn next(&self) -> f64 {
        if self.returning { self.home } else { self.arrive + self.holding }
    }
}

/// Row 66: planets a player holds in a galaxy before it colonises the next one within its reach (`colony_site`).
const PLANETS_PER_GALAXY: usize = 3;

pub struct World {
    pub games: Vec<Game>,
    /// Per slot, the capital slot of its account; every slot is its own account until a colony joins one.
    pub account: Vec<usize>,
    /// Per capital slot, the account's other planets in the order they were founded; empty for every other slot.
    colonies: Vec<Vec<usize>>,
    /// The host's colonisation tables; colonising is refused without them.
    pub colonisation: Option<Arc<Colonisation>>,
    /// Row 66: per capital, how many galaxies, nearest first, its player colonises in (`colony_site`); empty or 1 keeps
    /// every colony in the home galaxy. Players differ in how far they spread, so the season draws it per account.
    pub reach: Vec<u32>,
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
    /// Debris over each planet (a double in the host: a harvest the hold cuts leaves its fraction), and whether it has a moon.
    pub debris: Vec<[f64; 3]>,
    pub moons: Vec<bool>,
    /// What each slot's moon holds (empty counts: nothing yet); read only where `moons` is true.
    pub moon_holds: Vec<MoonHold>,
    /// Every fleet arrival, fight, landing, loss and recall, oldest first.
    pub events: Vec<Event>,
    /// Dark matter per slot; an account's is the capital's.
    pub dark_matter: Vec<i64>,
    /// W14: every yard order (slot, second, host unit id, amount asked, amount queued), for the host's replay of the world.
    pub unit_log: Vec<(usize, f64, u32, u64, u64)>,
    /// Every fleet as it left, with the speed it was sent at (W14: the trace a host replay dispatches).
    pub launch_log: Vec<(Fleet, f64)>,
    /// Debris over deep space (slot 16) by (galaxy, system); nothing harvests it yet.
    pub deep_space: HashMap<(i64, i64), [i64; 3]>,
    /// The best score in the highscore, which sets what an expedition can find.
    pub rank1_points: i64,
    /// The host's expedition draws for the next expedition, handed over by the referee as `recorded` is: the seed of
    /// its random stream and, for a fight, the engine's result from the expedition fleet's side.
    pub recorded_expedition: Option<(u32, Option<combat::EngineResult>)>,
    /// The host's two moon destruction rolls (destroy, then loss) for the next one.
    pub recorded_rolls: Option<[i64; 2]>,
    /// The seed of the arrival being played, which its events carry (W14: a host replay binds the same stream), and
    /// that stream: the host's `Randomizer` draws of one arrival (a counter-espionage roll, a battle's seed, a moon's size).
    dice: u32,
    stream: expedition::Mt19937,
    /// Whether accounts also decide at logins where nothing can be built (see `decision_kind`).
    pub idle_logins: bool,
    /// The second of each slot's next login that no build decision serves; NEVER with `idle_logins` off and for colonies.
    idle_next: Vec<f64>,
    /// Whether the account that is deciding now has no build to decide.
    idle_now: Vec<bool>,
    rng: u64,
}

impl World {
    /// One account per planet config, each with its own login draws; every account has made its first decision
    /// ready once `next_decision` reaches it.
    pub fn new(cat: &Catalogue, configs: &[usize], horizon_hours: f64, bonus_hours: f64, seed: u64) -> World {
        let mut games: Vec<Game> = configs.iter().enumerate()
            .map(|(a, &c)| cat.new_paused_game(c, horizon_hours, bonus_hours, seed.wrapping_add(a as u64)))
            .collect();
        // Row 47: a world does not retire an account that has nothing to build (`Catalogue::next_event` reads this).
        for g in games.iter_mut() { g.in_world = true }
        let next = games.iter_mut().map(|g| cat.peek(g)).collect();
        let n = configs.len();
        World { games, account: (0..n).collect(), colonies: vec![Vec::new(); n], colonisation: None, taken: Vec::new(), recorded_colony: None, next, rules: Vec::new(), coords: Vec::new(), yards: Vec::new(), fleets: Vec::new(), recorded: None, recorded_roll: None, unplaced: 0, reports: Vec::new(), fields: vec![false; n], debris: vec![[0.0; 3]; n], moons: vec![false; n], moon_holds: vec![MoonHold::default(); n], events: Vec::new(), dark_matter: vec![0; n], unit_log: Vec::new(), launch_log: Vec::new(), deep_space: HashMap::default(), rank1_points: 100_000, recorded_expedition: None, recorded_rolls: None, dice: 0, stream: expedition::Mt19937::new(0), idle_logins: false, idle_next: vec![NEVER; n], idle_now: vec![false; n], reach: Vec::new(), rng: seed ^ 0x9e37_79b9_7f4a_7c15 }
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
        (0..self.games.len()).map(|a| self.moves_at(a)).chain(self.fleets.iter().map(Fleet::next)).fold(f64::INFINITY, f64::min)
    }

    /// The second slot a's clock moves next: its economy's next event or a login with nothing to build, whichever is first.
    pub fn moves_at(&self, a: usize) -> f64 {
        self.next[a].min(self.idle_next[a])
    }

    /// Moves the world event by event until an account has something to decide (its index; `decision_kind` says whether
    /// that is a build, and `games[a].queue` is then the queue), or None when every account is done. A fleet due at the
    /// same second as an account's event goes first, and an account's own economy goes before its idle login.
    pub fn next_decision(&mut self, cat: &Catalogue) -> Option<usize> {
        loop {
            let a = (0..self.games.len()).filter(|&a| !self.games[a].done).min_by(|&x, &y| self.moves_at(x).total_cmp(&self.moves_at(y)).then(x.cmp(&y)))?;
            let fleet = (0..self.fleets.len()).min_by(|&x, &y| self.fleets[x].next().total_cmp(&self.fleets[y].next()));
            if let Some(f) = fleet.filter(|&f| self.fleets[f].next() <= self.moves_at(a)) {
                self.fleet_event(cat, f);
                continue;
            }
            if self.idle_next[a] < self.next[a] {
                // W14: an idle login is a page load like any other; it hands over the yard's units and writes the stock.
                self.catch_up(cat, a, self.idle_next[a]);
                self.settle_yard(cat, a);
                self.games[a].planet.settle();
                self.next[a] = cat.peek(&mut self.games[a]);
                self.idle_now[a] = true;
                return Some(a);
            }
            let deciding = self.step_account(cat, a);
            if deciding && !self.games[a].done {
                self.games[a].planet.settle();
                self.settle_yard(cat, a);
                self.idle_now[a] = false;
                self.pass_login(cat, a, self.games[a].t);
                return Some(a);
            }
        }
    }

    /// What account slot a decides now: a build (its economy's choice and everything else), or at an idle login only
    /// fleet and shipyard actions.
    pub fn decision_kind(&self, a: usize) -> Decision {
        if self.idle_now[a] { Decision::Idle } else { Decision::Build }
    }

    /// Turns on logins with nothing to build for every account's capital, from its first login at or after its clock:
    /// without them a fully built account never decides and so can never raid or save its fleet. A build decision at a
    /// login serves that login. Colonies share their account's logins and never decide idle.
    pub fn enable_idle_logins(&mut self, cat: &Catalogue) {
        self.idle_logins = true;
        let capitals: Vec<usize> = (0..self.games.len()).filter(|&a| self.account[a] == a).collect();
        for a in capitals {
            let t = self.games[a].t;
            self.idle_next[a] = self.login_after(cat, a, t, false);
        }
    }

    /// The account's first login at or after x (after, with `later`), NEVER when the horizon comes first or logins are off.
    fn login_after(&mut self, cat: &Catalogue, a: usize, x: f64, later: bool) -> f64 {
        if !self.idle_logins || self.account[a] != a || cat.login_gaps.is_empty() { return NEVER }
        let at = cat.login(&mut self.games[a], x, later);
        if at < self.games[a].horizon { at } else { NEVER }
    }

    /// Slot a decided at its login at second `at`: the next idle login is a later one.
    fn pass_login(&mut self, cat: &Catalogue, a: usize, at: f64) {
        if self.idle_logins { self.idle_next[a] = self.login_after(cat, a, at, true) }
    }

    /// Whether the account of slot a counts as inactive at second `now` (OGame's 'i' status): its last login is more than
    /// seven days old. An account with no login table is always there.
    pub fn inactive(&mut self, cat: &Catalogue, a: usize, now: f64) -> bool {
        if cat.login_gaps.is_empty() { return false }
        let game = &mut self.games[self.account[a]];
        cat.login(game, now, true);
        let seen = game.login_times.partition_point(|&t| t <= now);
        let last = if seen == 0 { 0.0 } else { game.login_times[seen - 1] };
        now - last > INACTIVE_SECONDS
    }

    /// Moves slot a's clock by one event (`Catalogue::tick`) and hands a research that landed to the account's other planets.
    fn step_account(&mut self, cat: &Catalogue, a: usize) -> bool {
        self.sync_peers(cat, a);
        self.games[a].account_pending = self.account_holds(cat, a);
        let holds = self.holding(cat, a);
        let deciding = cat.tick(&mut self.games[a]);
        if self.holding(cat, a) != holds {
            // A research or a lab started or landed here: the account's other planets may now wait, or not, from another second.
            for s in self.planets_of(self.account[a]) {
                if s == a || self.games[s].done { continue }
                self.games[s].account_pending = self.account_holds(cat, s);
                self.next[s] = cat.peek(&mut self.games[s]);
            }
        }
        self.share_research(cat, a);
        // Research is the account's and only its capital decides it (`share_research`): a colony's research queue that a
        // session woke goes back to sleep instead of letting the colony spend the account's next research (row 12).
        if deciding && self.account[a] != a && self.games[a].queue == 1 {
            self.games[a].free[1] = crate::economy::NEVER;
            self.next[a] = cat.peek(&mut self.games[a]);
            return false;
        }
        self.next[a] = cat.peek(&mut self.games[a]);
        deciding
    }

    /// Slot a's builds under way that can hold another planet of its account: researches and the objects that set research time.
    fn holding(&self, cat: &Catalogue, a: usize) -> Vec<(f64, usize)> {
        self.games[a].pending.iter().copied().filter(|&(_, k)| cat.objects[k].queue == 1 || cat.sets_research_time(k)).collect()
    }

    /// What the account's other planets hold that can hold slot a's builds.
    fn account_holds(&self, cat: &Catalogue, a: usize) -> Vec<(f64, usize)> {
        self.planets_of(self.account[a]).into_iter().filter(|&s| s != a).flat_map(|s| self.holding(cat, s)).collect()
    }

    /// The capital of slot a's account.
    pub fn account_of(&self, a: usize) -> usize {
        self.account[a]
    }

    /// Every planet slot of the account whose capital is `capital`, the capital first.
    pub fn planets_of(&self, capital: usize) -> Vec<usize> {
        std::iter::once(capital).chain(self.colonies[capital].iter().copied()).collect()
    }

    /// Row 23: the account's books, summed over the planets it holds (a colony's are its owner's).
    pub fn books(&self, capital: usize) -> Books {
        let mut books = Books::default();
        for slot in self.planets_of(capital) {
            books.add(&self.games[slot].planet.books);
        }
        books
    }

    /// Row 23: the stock those books reconcile - every planet's stores plus the cargo flying, which the fleet took out
    /// of its planet at dispatch and delivers when it lands. A sample is only comparable with another taken at the same
    /// second, which is how the season reads them.
    pub fn stock(&self, capital: usize) -> f64 {
        let planets = self.planets_of(capital);
        let stored: f64 = planets.iter().map(|&slot| weighted(self.games[slot].planet.res)).sum();
        let flying: f64 = self.fleets.iter().filter(|fleet| planets.contains(&fleet.owner)).map(|fleet| weighted(fleet.cargo)).sum();
        stored + flying
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
        // Row 47: a colony is played by the world like any other slot, so it is not finished for having nothing to build.
        game.in_world = true;
        // Row 23: a colony comes up with the host's starting stock, the only inflow its first line has to name.
        game.planet.books.produced += weighted(game.planet.res);
        game.free = [at, f64::INFINITY];
        // The planet logs in with its account: same past logins and the same generator for the next ones.
        game.login_times = home.login_times.clone();
        game.login_rng = home.login_rng;
        // And the same night: a colony drawing its own would log in while its account sleeps (69 "logins" a day).
        game.sleep_start = home.sleep_start;
        game.sleep_hours = home.sleep_hours;
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
        self.debris.push([0.0; 3]);
        self.moons.push(false);
        self.moon_holds.push(MoonHold::default());
        self.dark_matter.push(0);
        self.idle_next.push(NEVER);
        self.idle_now.push(false);
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
        if self.idle_now[a] { return self.wait(cat, a) }
        debug_assert!(self.legal(cat, a, k), "account {a} cannot start object {k} now");
        self.sync_peers(cat, a);
        cat.decide(&mut self.games[a], Some(k));
        self.next[a] = cat.peek(&mut self.games[a]);
    }

    /// Account a lets this login pass and decides again at its next one.
    pub fn wait(&mut self, cat: &Catalogue, a: usize) {
        if self.idle_now[a] {
            self.idle_now[a] = false;
            self.pass_login(cat, a, self.idle_next[a]);
            self.next[a] = cat.peek(&mut self.games[a]);
            return;
        }
        cat.decide(&mut self.games[a], None);
        self.next[a] = cat.peek(&mut self.games[a]);
    }

    /// What the deciding queue may start: the economy's rule, and no upgrade the running shipyard forbids (UnitQueueService).
    pub fn legal(&self, cat: &Catalogue, a: usize, k: usize) -> bool {
        !self.idle_now[a] && cat.queue_legal(&self.games[a], k) && !self.rules.get(a).is_some_and(|r| r.units.blocks_upgrade(&self.yards[a], k))
    }

    /// Seconds of `from..to` during which the account's building queue was busy, summed over its planets and capped
    /// at the window: what a "how much of the day was the queue working" measure needs (row 11).
    pub fn busy_seconds(&self, account: usize, from: f64, to: f64) -> f64 {
        if to <= from { return 0.0 }
        self.planets_of(account).iter().flat_map(|&s| self.games[s].history.iter()).map(|h| (h.2.min(to) - h.0.max(from)).max(0.0)).sum::<f64>().min(to - from)
    }

    /// Account a's research levels by host id, as the fleet and combat tables read them.
    pub fn research(&self, cat: &Catalogue, a: usize) -> HashMap<u32, u32> {
        // Research is the account's (the capital researches); a colony's copy is refreshed only when it is caught up.
        cat.objects.iter().zip(&self.games[self.account[a]].planet.levels).filter(|(o, _)| o.queue == 1).map(|(o, &l)| (o.id as u32, l)).collect()
    }

    /// Account a's expedition slots as `PlayerService::getExpeditionSlotsMax` counts them: the colonisation table's at its
    /// research level plus its class's own. `None` without the tables.
    pub fn expedition_slots(&self, cat: &Catalogue, a: usize) -> Option<usize> {
        let table = self.colonisation.as_ref()?;
        let level = self.research(cat, a).get(&(table.research as u32)).copied().unwrap_or(0);
        Some(table.max_expeditions(level)? + self.rules[a].expedition.as_ref()?.class_slots)
    }

    /// Account a's fleet slots as `PlayerService::getFleetSlotsMax` counts them (no officers): the table's at its research
    /// level plus its class's own. `None` without the table.
    pub fn fleet_slots(&self, cat: &Catalogue, a: usize) -> Option<usize> {
        let table = self.colonisation.as_ref()?;
        let level = self.research(cat, a).get(&table.fleet_research?).copied().unwrap_or(0) as usize;
        Some(*table.max_fleets.get(level.min(table.max_fleets.len().checked_sub(1)?))? + self.rules[a].combat.rules.class_fleet_slots)
    }

    /// Brings planet p's stock to second t before a fleet touches it; nothing of p's own is due before t. The yard's
    /// finished units wait for the planet's next load (`settle_yard`): the host hands them over only there.
    fn catch_up(&mut self, cat: &Catalogue, p: usize, t: f64) {
        if t <= self.games[p].t { return }
        cat.advance_to(&mut self.games[p], t, true);
        self.share_research(cat, p);
    }

    /// W14: a decision is a page load, and the host's page load hands over the units the yard has finished
    /// (`PlanetService::update`), so they count from here on, not from the yard's next order. It is also the only place
    /// the host refreshes the production rates it stores: satellites lost in a fight keep producing until the next load.
    fn settle_yard(&mut self, cat: &Catalogue, a: usize) {
        let Some(rules) = self.rules.get(a) else { return };
        rules.units.advance(&mut self.yards[a], self.games[a].t as i64);
        self.sync_units(cat, a);
    }

    /// A networked capital's lab reads its colonies' levels (`Catalogue::network_labs`); only the capital researches.
    fn sync_peers(&mut self, cat: &Catalogue, a: usize) {
        let Some(network) = cat.research_network else { return };
        if self.account[a] != a || self.games[a].planet.levels[network] == 0 { return }
        let peers: Vec<Vec<u32>> = self.colonies[a].iter().map(|&s| self.games[s].planet.levels.clone()).collect();
        self.games[a].planet.peers = std::sync::Arc::new(peers);
    }

    /// The economy reads production from the units on the planet while the shipyard owns the counts, so the shipyard's
    /// are copied into the planet by host id whenever they may have changed; nothing else writes `planet.units`.
    fn sync_units(&mut self, cat: &Catalogue, a: usize) {
        let Some(rules) = self.rules.get(a) else { return };
        let counts = &self.yards[a].counts;
        let units: Vec<u64> = cat.units.iter().map(|&id| rules.units.index(id).map_or(0, |k| counts[k])).collect();
        let planet = &mut self.games[a].planet;
        if planet.units == units { return }
        planet.units = units;
        cat.refresh(planet);
    }

    /// Account a, while it decides, orders `amount` of unit u (units index) in its shipyard; the amount the host would queue.
    pub fn order_units(&mut self, cat: &Catalogue, a: usize, u: usize, amount: u64) -> u64 {
        let rules = Arc::clone(&self.rules[a]);
        let now = self.games[a].t;
        self.catch_up(cat, a, now);
        self.settle_yard(cat, a);
        let upgrading = self.games[a].pending.iter().find(|&&(_, k)| cat.objects[k].queue == 0).map(|&(_, k)| k);
        let queued = rules.units.order(&mut self.yards[a], &mut self.games[a].planet, u, amount, now as i64, upgrading);
        self.games[a].planet.settle();
        self.unit_log.push((a, now, rules.units.units[u].id as u32, amount, queued));
        self.sync_units(cat, a);
        self.next[a] = cat.peek(&mut self.games[a]);
        queued
    }

    /// Account a, while it decides, sends ships (host unit id, amount) with cargo to planet `target` at `speed`
    /// (the host's 0.5..10 steps). Pays ships, cargo and fuel from the planet now; refused as the host would refuse it.
    pub fn dispatch(&mut self, cat: &Catalogue, a: usize, target: usize, mission: Mission, ships: &[(u32, i64)], cargo: [f64; 3], speed: f64) -> Result<(), &'static str> {
        match mission {
            Mission::Colonise => return Err("colonise to coordinates"),
            Mission::Expedition => return Err("expedition to coordinates"),
            Mission::Missile => return Err("missiles launch by count"),
            _ => {}
        }
        self.launch(cat, a, Some(target), self.coords[target], mission, ships, cargo, speed, 0)
    }

    /// Account a, while it decides, sends ships on an expedition to deep space at `to` (position 16) for `hold_hours`.
    /// Refused as the host refuses: not deep space, no astrophysics, nothing but probes, or the usual checks.
    #[allow(clippy::too_many_arguments)]
    pub fn expedition(&mut self, cat: &Catalogue, a: usize, to: Coord, ships: &[(u32, i64)], cargo: [f64; 3], speed: f64, hold_hours: i64) -> Result<(), &'static str> {
        self.launch(cat, a, None, to, Mission::Expedition, ships, cargo, speed, hold_hours)
    }

    /// Account a, while it decides, sends a colony ship (and whatever else is in `ships`) to the free slot `to`.
    /// Refused as the host refuses: no ship, a slot out of range or taken, or not enough astrophysics for that slot.
    pub fn colonise(&mut self, cat: &Catalogue, a: usize, to: Coord, ships: &[(u32, i64)], cargo: [f64; 3], speed: f64) -> Result<(), &'static str> {
        self.launch(cat, a, None, to, Mission::Colonise, ships, cargo, speed, 0)
    }

    /// Row 45 C: at its account's login a colony whose store is filling (a resource at 80% of its cap) sends home what it
    /// holds above half the cap, in the ships of `cargo_ship` stationed on it: production it would spill goes home, and its
    /// own builds keep their budget (row 46b: emptying it to two hours of production froze its mines from day 60). The
    /// host does not cap a delivery at the store, so a price above the capital's cap becomes payable. Returns how many
    /// more of those ships the surplus needed.
    pub fn consolidate(&mut self, cat: &Catalogue, colony: usize, cargo_ship: u32) -> i64 {
        let capital = self.account[colony];
        self.catch_up(cat, colony, self.games[capital].t);
        self.next[colony] = cat.peek(&mut self.games[colony]);
        let rules = Arc::clone(&self.rules[colony]);
        let research = self.research(cat, colony);
        let planet = &self.games[colony].planet;
        if !(0..3).any(|r| planet.res[r] >= 0.8 * planet.cap[r]) { return 0 }
        let mut load: [f64; 3] = std::array::from_fn(|r| (planet.res[r] - 0.5 * planet.cap[r]).max(0.0).floor());
        let hold = rules.combat.capacity(&[(cargo_ship, 1)].into_iter().collect(), &research).max(1) as f64;
        let need = (load.iter().sum::<f64>() / hold).ceil() as i64;
        let shelf = rules.units.index(cargo_ship as i64).map_or(0, |u| self.yards[colony].counts[u] as i64);
        // Ships on their way to the colony, or out on its last transport, are the colony's too.
        let coming: i64 = self.fleets.iter().filter(|f| f.owner == colony || (f.target == colony && f.mission == Mission::Deploy))
            .flat_map(|f| f.ships.iter()).filter(|s| s.0 == cargo_ship).map(|s| s.1).sum();
        let ships = need.min(shelf);
        if ships == 0 { return (need - coming).max(0) }
        let distance = fleet::distance(&rules.settings, self.coords[colony], self.coords[capital], &|_, _| SystemState::Active);
        let fuel = fleet::consumption(&rules.settings, &rules.fleet, &[(cargo_ship, ships)], &research, distance, 0, 10.0) as f64;
        load[2] = (load[2] - fuel).max(0.0);
        let scale = (ships as f64 * hold / load.iter().sum::<f64>().max(1.0)).min(1.0);
        for r in &mut load { *r = (*r * scale).floor() }
        if self.dispatch(cat, colony, capital, Mission::Transport, &[(cargo_ship, ships)], load, 10.0).is_ok() {
        }
        (need - ships - coming).max(0)
    }

    /// Makes slot `slot` a planet of the account whose capital is `capital` (the referee sets worlds up this way).
    pub fn join_account(&mut self, slot: usize, capital: usize) {
        self.account[slot] = capital;
        self.colonies[capital].push(slot);
        self.idle_next[slot] = NEVER;
    }

    #[allow(clippy::too_many_arguments)]
    fn launch(&mut self, cat: &Catalogue, a: usize, target: Option<usize>, to: Coord, mission: Mission, ships: &[(u32, i64)], cargo: [f64; 3], speed: f64, hold_hours: i64) -> Result<(), &'static str> {
        let rules = Arc::clone(&self.rules[a]);
        let now = self.games[a].t;
        self.catch_up(cat, a, now);
        self.settle_yard(cat, a);
        if target == Some(a) { return Err("own planet") }
        let own = target.is_some_and(|t| self.account[t] == self.account[a]);
        if own && matches!(mission, Mission::Attack | Mission::Espionage | Mission::MoonDestroy) { return Err("own planet") }
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
        // GameMission::startMissionSanityChecks: every fleet of the account still out, missiles aside, holds a slot.
        let out = self.fleets.iter().filter(|f| f.mission != Mission::Missile && self.account[f.owner] == self.account[a]).count();
        if self.fleet_slots(cat, a).is_some_and(|slots| out >= slots) { return Err("maximum number of fleets") }
        let sends = |ids: &[u32]| sent.iter().any(|(id, _)| ids.contains(id));
        match mission {
            Mission::Espionage if !sends(&rules.combat.missions.espionage_units) => return Err("no probe"),
            Mission::Recycle if !sends(&[self.harvester(a, target.unwrap())]) => return Err("no harvester"),
            Mission::Recycle if !self.fields[target.unwrap()] => return Err("no debris field"),
            Mission::Colonise => self.colonisable(cat, a, to, sends(&[self.colonisation.as_ref().map_or(0, |c| c.ship)]))?,
            Mission::MoonDestroy => self.moon_destroyable(&rules, target.unwrap(), &sends)?,
            Mission::Expedition => self.expeditionable(cat, a, to, &rules, &sent)?,
            _ => {}
        }
        let distance = fleet::distance(&rules.settings, self.coords[a], to, &|_, _| SystemState::Active);
        let fuel = fleet::consumption(&rules.settings, &rules.fleet, &sent, &research, distance, hold_hours, speed) as f64;
        let held: combat::Units = sent.iter().copied().collect();
        if rules.combat.fuel_capacity(&held, &research) < fuel as i64 { return Err("fuel over the tanks' capacity") }
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
        planet.settle();
        // Row 23: the fuel is burnt at dispatch; the cargo is still the account's, in the hold, until it lands.
        planet.books.fuel += weighted([0.0, 0.0, fuel]);
        let holding = fleet::holding_seconds(hold_hours) as f64;
        self.fleets.push(Fleet { owner: a, target: target.unwrap_or(a), to, mission, ships: sent, cargo, fuel, arrive: now + flight, home: now + 2.0 * flight + holding, returning: false, departed: now, holding, priority: 0, moon: mission == Mission::MoonDestroy, id: self.launch_log.len() });
        self.launch_log.push((self.fleets.last().expect("just sent").clone(), speed));
        self.next[a] = cat.peek(&mut self.games[a]);
        Ok(())
    }

    /// A fleet reaches its target (the mission acts and the fleet turns home) or comes home (ships and cargo land).
    fn fleet_event(&mut self, cat: &Catalogue, f: usize) {
        let at = self.fleets[f].next();
        self.dice = (next_random(&mut self.rng) >> 32) as u32;
        self.stream = expedition::Mt19937::new(self.dice);
        if self.fleets[f].returning {
            let fleet = self.fleets.swap_remove(f);
            self.land(cat, fleet.owner, at, &fleet.ships, fleet.cargo);
            self.log(EventKind::Return, at, &fleet, Outcome::Done, |e| e.gained = fleet.cargo);
            return;
        }
        match self.fleets[f].mission {
            Mission::Deploy => return self.deploy(cat, f),
            Mission::Colonise => return self.found(cat, f),
            Mission::Expedition => return self.expedition_arrives(cat, f),
            Mission::Missile => return self.missile_strikes(cat, f),
            _ => {}
        }
        let (owner, target) = (self.fleets[f].owner, self.fleets[f].target);
        self.catch_up(cat, target, at);
        // A fight or a scan loads the target first (`AttackMission`, `EspionageMission`, `MoonDestructionMission`); a delivery does not.
        if matches!(self.fleets[f].mission, Mission::Attack | Mission::Espionage | Mission::MoonDestroy) {
            self.settle_yard(cat, target);
            self.games[target].planet.settle();
        }
        let mut outcome = Outcome::Done;
        let mut gained = [0.0; 3];
        match self.fleets[f].mission {
            Mission::Deploy | Mission::Colonise | Mission::Expedition | Mission::Missile => unreachable!("handled above"),
            Mission::Transport => {
                let mut cargo = std::mem::take(&mut self.fleets[f].cargo);
                let refund = self.fleets[f].fuel / 2.0;
                cargo[2] += refund;
                self.games[target].planet.deliver(cargo);
                // Row 23: the half fuel comes back into the hold, and goods handed to another player leave these books
                // (the same resources arrive in that account's, so loot_out is what left here, refund included there).
                self.games[owner].planet.books.fuel -= weighted([0.0, 0.0, refund]);
                if self.account[target] != self.account[owner] {
                    self.games[owner].planet.books.loot_out += weighted(cargo);
                    self.games[target].planet.books.loot_in += weighted(cargo);
                }
                gained = cargo;
            }
            Mission::Attack => self.attack(cat, f),
            Mission::Espionage => self.espionage(cat, f),
            Mission::Recycle => gained = self.recycle(cat, f),
            Mission::MoonDestroy => outcome = self.moon_destruction(cat, f),
        }
        let fleet = self.fleets[f].clone();
        self.log(EventKind::Arrival, at, &fleet, outcome, |e| e.gained = gained);
        self.next[target] = cat.peek(&mut self.games[target]);
        self.fleets[f].returning = true;
        if self.fleets[f].ships.is_empty() {
            // Row 23s: a fleet lost in battle takes the cargo it still held with it, as an outflow of its own.
            self.games[owner].planet.books.cargo_lost += weighted(self.fleets[f].cargo);
            self.fleets.swap_remove(f);
            self.log(EventKind::Lost, at, &fleet, Outcome::Defeated, |_| {});
        }
    }

    /// Appends an event about `fleet`; `fill` sets the fields that differ from an empty event.
    fn log(&mut self, kind: EventKind, time: f64, fleet: &Fleet, outcome: Outcome, fill: impl FnOnce(&mut Event)) {
        let mut event = Event { kind, time, mission: fleet.mission, owner: fleet.owner, target: fleet.target, departed: fleet.departed, ships_lost: Vec::new(), target_lost: Vec::new(), present: Vec::new(), gained: [0.0; 3], debris: [0; 3], moon_chance: 0, moon_created: false, outcome, seed: self.dice, fleet: fleet.id };
        fill(&mut event);
        self.events.push(event);
    }

    /// Account a, while it decides, turns fleet f (an index into `fleets`) home from where it is. The host's rule
    /// (`GameMission::cancel`): only before it has arrived, never a returning fleet, an expedition on hold or a
    /// missile; the way back takes the time already flown, and half the fuel paid comes home in the hold.
    pub fn recall(&mut self, a: usize, f: usize) -> Result<(), &'static str> {
        let now = self.games[a].t;
        let fleet = self.fleets.get_mut(f).ok_or("no such fleet")?;
        if fleet.owner != a { return Err("not your fleet") }
        if fleet.returning { return Err("already returning") }
        if fleet.mission == Mission::Missile { return Err("missiles cannot be recalled") }
        if now > fleet.arrive { return Err("already arrived") }
        // The refund is a flow, so an idle login's rollback must not undo it (as every other action at a login).
        // Row 23: the recall hands half the fuel back into the hold, where the stock counts it again.
        self.games[fleet.owner].planet.books.fuel -= weighted([0.0, 0.0, (fleet.fuel / 2.0).floor()]);
        fleet.cargo[2] += (fleet.fuel / 2.0).floor();
        fleet.fuel = 0.0;
        fleet.holding = 0.0;
        fleet.home = now + (now - fleet.departed);
        fleet.arrive = now;
        fleet.returning = true;
        let fleet = fleet.clone();
        self.log(EventKind::Recall, now, &fleet, Outcome::Cancelled, |_| {});
        Ok(())
    }

    /// Account a's unit of host id `id` (a ship or defence) is set to `amount` on its planet, and the economy's view of
    /// it (production from satellites and the like) with it. False when the account's yard has no such unit.
    pub fn set_units(&mut self, cat: &Catalogue, a: usize, id: u32, amount: u64) -> bool {
        let Some(u) = self.rules[a].units.index(id as i64) else { return false };
        self.yards[a].counts[u] = amount;
        self.sync_units(cat, a);
        self.next[a] = cat.peek(&mut self.games[a]);
        true
    }

    /// DeploymentMission: the ships stay on the target planet with the cargo and half the fuel; nothing flies home.
    fn deploy(&mut self, cat: &Catalogue, f: usize) {
        let fleet = self.fleets.swap_remove(f);
        let mut cargo = fleet.cargo;
        // Row 23: half the fuel lands with the ships, so the fuel line pays only the half that was burnt.
        self.games[fleet.owner].planet.books.fuel -= weighted([0.0, 0.0, fleet.fuel / 2.0]);
        cargo[2] += fleet.fuel / 2.0;
        self.land(cat, fleet.target, fleet.arrive, &fleet.ships, cargo);
        self.log(EventKind::Arrival, fleet.arrive, &fleet, Outcome::Done, |e| e.gained = cargo);
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

    /// Where a scripted account founds its next colony: the first free slot whose position its own astrophysics
    /// reaches, on a donor the host's tables carry a site for. The limit is the host's `max_planets`; no object is
    /// named here, the catalogue's colonisation block supplies every number.
    pub fn colony_site(&self, cat: &Catalogue, a: usize) -> Option<Coord> {
        let table = self.colonisation.as_ref()?;
        let capital = self.account_of(a);
        let level = self.research(cat, a).get(&(table.research as u32)).copied().unwrap_or(0);
        if self.planets_of(capital).len() >= table.max_planets(level) { return None }
        let donor = cat.planets[self.games[capital].planet.config].planet_id;
        let settings = &self.rules.get(a)?.settings;
        let home = self.coords[capital];
        // A player colonises next door before anywhere else: the home galaxy and system first, then outward.
        let near = |count: i64, at: i64| std::iter::once(at).chain((1..=count).filter(move |&x| x != at));
        // Row 66: within its reach (the home galaxy, then outward by distance) a player fills a galaxy before opening the
        // next: about three planets each in the real bands (bottom 4 planets in 1 galaxy, mid 10 in 3, top 13 in 6).
        let count = settings.number_of_galaxies as i64;
        let reach = self.reach.get(capital).copied().unwrap_or(1).max(1) as usize;
        let mut galaxies: Vec<i64> = Vec::new();
        for g in (0..count).flat_map(|d| [d, -d]).map(|d| (home.galaxy - 1 + d).rem_euclid(count) + 1) {
            if !galaxies.contains(&g) { galaxies.push(g) }
        }
        let held = |x: i64| self.planets_of(capital).iter().filter(|&&p| self.coords[p].galaxy == x).count();
        let within = reach.min(galaxies.len());
        galaxies[..within].sort_by_key(|&x| held(x) >= PLANETS_PER_GALAXY);
        for position in 4..=15 {
            if table.position_levels.get(&position).copied().unwrap_or(u32::MAX) > level { continue }
            if !table.sites.iter().any(|s| s.donor == donor && s.position == position) { continue }
            for &galaxy in &galaxies {
                for system in near(settings.number_of_systems as i64, home.system) {
                    let to = Coord { galaxy, system, position };
                    if self.free_slot(to) { return Some(to) }
                }
            }
        }
        None
    }

    /// ColonisationMission::processArrival: the slot still free and the planet limit not reached, a planet comes up
    /// with the fleet's cargo and half its fuel on top of the colony stock and the ship is spent; anything else cancels
    /// the mission and the whole fleet turns home with its cargo.
    fn found(&mut self, cat: &Catalogue, f: usize) {
        let (owner, to, at) = (self.fleets[f].owner, self.fleets[f].to, self.fleets[f].arrive);
        self.catch_up(cat, owner, at);
        let capital = self.account[owner];
        let recorded = self.recorded_colony.is_some();
        let table = self.colonisation.clone();
        let site = table.as_ref().filter(|t| {
            let level = self.research(cat, owner).get(&(t.research as u32)).copied().unwrap_or(0);
            self.free_slot(to) && self.planets_of(capital).len() < t.max_planets(level)
        }).and_then(|t| self.pick_site(cat, capital, t, to.position));
        let Some(site) = site else {
            self.games[owner].planet.books.fuel -= weighted([0.0, 0.0, (self.fleets[f].fuel / 2.0).floor()]);
            self.fleets[f].cargo[2] += (self.fleets[f].fuel / 2.0).floor();
            self.fleets[f].fuel = 0.0;
            self.fleets[f].returning = true;
            self.next[owner] = cat.peek(&mut self.games[owner]);
            let fleet = self.fleets[f].clone();
            self.log(EventKind::Arrival, at, &fleet, Outcome::Cancelled, |_| {});
            return;
        };
        let slot = self.add_planet(cat, owner, site.config, to, at);
        // The site lends its temperature's tables; the size is the host's own draw for the slot and the owner's class.
        if let Some(&(low, high)) = table.as_ref().filter(|_| !recorded).and_then(|t| t.field_ranges.get(&to.position)) {
            let drawn = low + (next_random(&mut self.rng) % (high - low + 1) as u64) as u32;
            self.games[slot].planet.field_max = (drawn as f64 * self.rules[capital].combat.rules.planet_size_multiplier) as u32;
        }
        let mut brought = self.fleets[f].cargo;
        // Row 23: the fleet's half fuel lands on the colony with the cargo it carried (`add_planet` books the stock
        // the host gives a colony; the cargo was already the account's, flying).
        self.games[owner].planet.books.fuel -= weighted([0.0, 0.0, self.fleets[f].fuel / 2.0]);
        brought[2] += self.fleets[f].fuel / 2.0;
        self.games[slot].planet.deliver(brought);
        self.next[slot] = cat.peek(&mut self.games[slot]);
        let ship = table.map_or(0, |t| t.ship);
        let fleet = &mut self.fleets[f];
        fleet.ships.iter_mut().find(|s| s.0 == ship).expect("a colony ship flew").1 -= 1;
        fleet.ships.retain(|s| s.1 > 0);
        fleet.cargo = [0.0; 3];
        fleet.fuel = 0.0;
        fleet.returning = true;
        let fleet = fleet.clone();
        if fleet.ships.is_empty() {
            self.fleets.swap_remove(f);
        }
        self.log(EventKind::Arrival, at, &fleet, Outcome::Done, |e| { e.target = slot; e.gained = brought; });
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
        self.games[p].planet.deliver(cargo);
        self.next[p] = cat.peek(&mut self.games[p]);
    }

    /// The ship that harvests the field over planet `target` for account a (RecycleMission, by the slot's position).
    pub fn harvester(&self, a: usize, target: usize) -> u32 {
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
        // BattleEngine::simulateBattle draws its seed from the arrival's stream.
        let seed = self.stream.int(0, i64::MAX) as u64;
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
        for r in 0..3 { self.debris[target][r] += outcome.debris[r] as f64 }
        self.fields[target] = true;
        self.fleets[f].ships = outcome.attacker_left.iter().map(|(&id, &n)| (id, n)).collect();
        let fleet = self.fleets[f].clone();
        let result = match outcome.winner {
            combat::Winner::Attacker => Outcome::Won,
            combat::Winner::Defender => Outcome::Defeated,
            combat::Winner::Draw => Outcome::Draw,
        };
        let present: Vec<(u32, i64)> = defender.iter().filter(|(id, _)| !defender_rules.combat.is_defense(*id)).copied().collect();
        self.log(EventKind::Fight, fleet.next(), &fleet, result, |e| {
            e.ships_lost = outcome.attacker_lost.iter().map(|(&id, &n)| (id, n)).collect();
            e.target_lost = outcome.defender_lost.iter().map(|(&id, &n)| (id, n - outcome.repaired.get(&id).copied().unwrap_or(0))).filter(|s| s.1 > 0).collect();
            e.present = present;
            e.gained = outcome.loot.map(|r| r as f64);
            e.debris = outcome.debris;
            e.moon_chance = outcome.moon_chance;
            e.moon_created = outcome.moon_created;
        });
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
        let refund = self.fleets[f].fuel / 2.0;
        cargo[2] += refund;
        let held = weighted(cargo);
        let outcome = self.fight(cat, f, &defender, cargo, true);
        for r in 0..3 {
            self.games[target].planet.res[r] = as_stored(self.games[target].planet.res[r] - outcome.loot[r] as f64);
            self.games[target].planet.res[r] = as_stored(self.games[target].planet.res[r] + outcome.salvage[r] as f64);
        }
        self.games[target].planet.settle();
        if outcome.moon_created && !self.moons[target] { self.raise_moon(target, outcome.debris.iter().sum()) }
        let owner = self.fleets[f].owner;
        self.fleets[f].cargo = outcome.cargo.map(|r| r as f64);
        // Row 23s: the hold is the account's stock while the fleet flies, so the plunder (and what the collectors
        // hauled) is booked when the hold takes it, at the second of the fight, and the cargo the fight destroyed with
        // the ships is booked as its own outflow. Booking the loot at the fight is what makes a raid whose hold grows
        // on one day and lands on the next reconcile: the stock rose here, under the hold, not at the landing.
        let held_now = weighted(self.fleets[f].cargo);
        let books = &mut self.games[owner].planet.books;
        books.fuel -= weighted([0.0, 0.0, refund]);
        if held_now >= held { books.loot_in += held_now - held } else { books.cargo_lost += held - held_now }
        // The target paid the loot out of its stores and hauled its collectors' share of the field back onto it.
        let target_books = &mut self.games[target].planet.books;
        target_books.loot_out += weighted(outcome.loot.map(|r| r as f64));
        target_books.loot_in += weighted(outcome.salvage);
    }

    /// A moon comes up over `target`, sized as the host sizes it: a random 10..20 plus the debris of the fight, rooted.
    fn raise_moon(&mut self, target: usize, debris: i64) {
        let x = self.stream.int(10, 20);
        let diameter = (((x as f64) + 3.0 * debris as f64 / 100_000.0).sqrt() * 1000.0).floor() as i64;
        self.moons[target] = true;
        self.moon_holds[target] = MoonHold { diameter: diameter.min(MOON_DIAMETER_CAP), stock: [0.0; 3], counts: vec![0; self.yards[target].counts.len()] };
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
        // CounterEspionageService::rollCounterEspionage: the arrival's first draw, and only between the bounds.
        let roll = match self.recorded_roll.take() {
            Some(roll) => roll,
            None if chance > 0 && chance < 100 => self.stream.int(1, 100),
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
            debris: self.fields[target].then_some(self.debris[target].map(|r| r as i64)).filter(|d| d.iter().any(|&r| r > 0)),
            counter_chance: chance,
            ships: reveals(2, 1).then(|| only(false)),
            defence: reveals(3, 2).then(|| only(true)),
            buildings: reveals(5, 3).then(|| levels(0)),
            research: reveals(7, 4).then(|| levels(1)),
        };
        self.reports.push(report);
        // The return flight's cargo is an integer column, so an odd fuel loses its half unit.
        self.games[owner].planet.books.fuel -= weighted([0.0, 0.0, (self.fleets[f].fuel / 2.0).floor()]);
        self.fleets[f].cargo[2] += (self.fleets[f].fuel / 2.0).floor();
    }

    /// RecycleMission: the slot's harvester ships alone carry, the field is shared out as loot is, and the harvest flies
    /// home on top of the mission's own resources (here the host reads them directly: no half fuel).
    fn recycle(&mut self, cat: &Catalogue, f: usize) -> [f64; 3] {
        let (owner, target) = (self.fleets[f].owner, self.fleets[f].target);
        let harvester = self.harvester(owner, target);
        let count: i64 = self.fleets[f].ships.iter().filter(|(id, _)| *id == harvester).map(|s| s.1).sum();
        let capacity = self.rules[owner].combat.capacity(&[(harvester, count)].into_iter().collect(), &self.research(cat, owner));
        let harvested = combat::distribute_loot(self.debris[target], capacity);
        // The harvest is fractional when the hold cuts it: the field (a double) loses all of it, the hold takes the whole part.
        let carried = harvested.map(f64::floor);
        for r in 0..3 {
            self.debris[target][r] -= harvested[r];
            self.fleets[f].cargo[r] += carried[r];
        }
        // Row 23: the harvest is stock that came out of the field into the hold.
        self.games[owner].planet.books.loot_in += weighted(carried);
        carried
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
        // With idle logins on, an account also decides at logins with nothing to build and does nothing there: the
        // economy must come out bit for bit as it does without them.
        let mut worlds = Vec::new();
        let mut idle_decisions = 0;
        for idle_logins in [false, true] {
            let mut world = World::new(&cat, &configs, 240.0, 0.0, 7);
            if idle_logins { world.enable_idle_logins(&cat) }
            let mut rngs: Vec<u64> = (0..configs.len() as u64).map(|a| a + 100).collect();
            let mut last = 0.0;
            while let Some(a) = world.next_decision(&cat) {
                assert!(world.games[a].t >= last, "the world clock went back");
                last = world.games[a].t;
                if world.decision_kind(a) == Decision::Idle {
                    idle_decisions += 1;
                    world.wait(&cat, a);
                    continue;
                }
                let game = &world.games[a];
                match cat.baseline(&game.planet, game.queue, Baseline::Player, &mut rngs[a]) {
                    Some(k) => world.commit(&cat, a, k),
                    None => world.wait(&cat, a),
                }
            }
            worlds.push(world);
        }
        assert!(idle_decisions > 0, "no login was left without a build to decide");
        for world in &worlds {
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
            // An idle login settles the planet, so accrual runs in finer steps: the same stock up to float rounding.
            assert!((0..3).all(|r| (world_game.planet.res[r] - alone.planet.res[r]).abs() < 1e-6), "account {a}: final stock {:?} {:?}", world_game.planet.res, alone.planet.res);
        }
        }
    }

    const DATA: &str = "../../../../storage/rl/ogsim";

    /// Row 47: a world planet with nothing legal and nothing pending is not finished. It sleeps to the account's next
    /// login, books what it produced there, and keeps deciding, so the account stays in the world to its horizon.
    #[test]
    fn a_world_planet_with_nothing_to_build_keeps_logging_in() {
        let Ok(mut cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1.json")) else { return eprintln!("no catalogue; skipped") };
        cat.login_gaps = vec![600.0, 1800.0, 3600.0, 7200.0, 21600.0];
        let mut world = World::new(&cat, &[0], 10.0 * 24.0, 0.0, 5);
        world.enable_idle_logins(&cat);
        // Every object at the top of its own table: nothing legal, nothing pending, nothing committed. That is the state
        // the economy used to read as the end of the account's season.
        world.games[0].planet.levels = cat.objects.iter().map(|o| o.price.len() as u32).collect();
        cat.refresh(&mut world.games[0].planet);
        // A colony slot is a game the world creates after `World::new`, and in the season it was the slot that still ran
        // its clock out to the horizon, so it is covered here too.
        let rules = Arc::new(Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"),
            &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")).expect("the units, fleet and combat exports"));
        world.arm(vec![Arc::clone(&rules)], vec![Coord { galaxy: 1, system: 10, position: 4 }]);
        let colony = world.add_planet(&cat, 0, 0, Coord { galaxy: 1, system: 10, position: 5 }, 0.0);
        world.games[colony].planet.levels = cat.objects.iter().map(|o| o.price.len() as u32).collect();
        cat.refresh(&mut world.games[colony].planet);
        let start = world.games[0].planet.res;
        let (mut logins, mut last) = (0, 0.0);
        while let Some(a) = world.next_decision(&cat) {
            assert_eq!(world.account_of(a), 0, "one account");
            for slot in [0, colony] {
                assert!(!world.games[slot].done, "slot {slot} was retired at second {}", world.games[slot].t);
            }
            assert!(world.games[0].t >= last, "the clock went back: {} after {last}", world.games[0].t);
            last = world.games[0].t;
            logins += u32::from(world.decision_kind(a) == Decision::Idle);
            assert!(logins < 10_000, "the world spins on an account with nothing to build");
            world.wait(&cat, a);
        }
        let game = &world.games[0];
        assert!(logins > 20, "the capital was left without idle logins: {logins}");
        assert!(game.t >= 5.0 * 24.0 * 3600.0, "the account's clock stopped at {}", game.t);
        assert!(game.planet.res[0] > start[0], "nothing was produced between the logins");
        assert!(game.planet.res[0] <= game.planet.cap[0] + 1e-6, "production ran past the store cap");
        assert!(game.planet.books.produced > 0.0, "no production was booked");
        let colony = &world.games[1];
        assert!(colony.planet.books.produced > 0.0, "the colony booked no production");
        assert!(colony.t >= game.t - 1.0, "the colony's clock stopped at {} against the capital's {}", colony.t, game.t);
    }

    /// A colony logs in with its account: the same future logins as the capital, never one of its own.
    #[test]
    fn a_colony_logs_in_with_its_account() {
        let Ok(mut cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1.json")) else { return eprintln!("no catalogue; skipped") };
        cat.login_gaps = vec![600.0, 1800.0, 3600.0, 7200.0, 21600.0];
        let mut world = World::new(&cat, &[0], 30.0 * 24.0, 0.0, 9);
        let rules = Arc::new(Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"),
            &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")).expect("the units, fleet and combat exports"));
        world.arm(vec![rules], vec![Coord { galaxy: 1, system: 10, position: 4 }]);
        cat.login(&mut world.games[0], 3.0 * 86_400.0, false);
        let colony = world.add_planet(&cat, 0, 0, Coord { galaxy: 1, system: 10, position: 5 }, 3.0 * 86_400.0);
        let horizon = 25.0 * 86_400.0;
        cat.login(&mut world.games[0], horizon, false);
        cat.login(&mut world.games[colony], horizon, false);
        let upto = |g: &Game| g.login_times.iter().copied().filter(|&t| t <= horizon).collect::<Vec<f64>>();
        assert_eq!(upto(&world.games[colony]), upto(&world.games[0]), "the colony's logins left its account's");
    }

    /// Row 48: a sleeping world planet's next login may fall after the horizon; its next event is the horizon, or the world
    /// plays fleets past the season's end and `catch_up` carries their targets' clocks there (seven day-365 violations).
    #[test]
    fn a_sleeping_world_planet_never_moves_past_the_horizon() {
        let Ok(mut cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1.json")) else { return eprintln!("no catalogue; skipped") };
        cat.login_gaps = vec![21600.0];
        let mut world = World::new(&cat, &[0], 1.0, 0.0, 5);
        let game = &mut world.games[0];
        game.planet.levels = cat.objects.iter().map(|o| o.price.len() as u32).collect();
        cat.refresh(&mut game.planet);
        game.free = [NEVER; 2];
        game.t = game.horizon - 60.0;
        let horizon = game.horizon;
        assert!(cat.peek(game) <= horizon, "the next event {} is past the horizon {horizon}", cat.peek(game));
    }

    /// `busy_seconds` reads exactly the builds the account's own history holds: no more than the window, none when the
    /// window is empty, and the same total when the window covers every build (row 11).
    #[test]
    fn busy_seconds_reads_the_accounts_own_history() {
        let Ok(mut cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1.json")) else { return eprintln!("no catalogue; skipped") };
        cat.login_gaps = vec![600.0, 1800.0, 3600.0, 7200.0, 21600.0];
        let mut world = World::new(&cat, &[0], 240.0, 0.0, 3);
        world.enable_idle_logins(&cat);
        let mut game = cat.new_game(0, 240.0, 0.0, 3);
        while !game.done {
            match cat.baseline(&game.planet, game.queue, Baseline::Player, &mut 3) {
                Some(k) => { cat.step(&mut game, k); }
                None => cat.wait_turn(&mut game),
            }
        }
        world.games[0] = game;
        let &(start, _, finish, _) = world.games[0].history.first().expect("something was built");
        let span = finish - start;
        let covered = world.busy_seconds(0, start, finish);
        assert!((covered - span).abs() < 1e-6, "a window holding one build reads {covered}, the build lasted {span}");
        let half = world.busy_seconds(0, start, start + span / 2.0);
        assert!((half - span / 2.0).abs() < 1e-6, "half the build in half the window: {half} of {span}");
        assert_eq!(world.busy_seconds(0, 10.0, 10.0), 0.0, "an empty window is zero");
        assert!(world.busy_seconds(0, 0.0, 1e9) <= 1e9, "the cap never exceeds the window");
    }

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

    /// Row 23: a day's books close against the stock the world measured, and the plunder is booked where the hold takes
    /// it - at the fight, while the fleet is still out - and not a second time when it lands (row 23s).
    #[test]
    fn a_days_books_satisfy_the_sum() {
        let Some((cat, mut world, _quiet)) = pair(72.0) else { return eprintln!("no u1 catalogues; skipped") };
        give(&mut world, 0, 202, 20);
        world.games[0].planet.res = [50_000.0, 20_000.0, 20_000.0];
        world.games[1].planet.res = [30_000.0, 20_000.0, 10_000.0];
        // The identity a season's line states: the stock the world moved, and every flow the books booked for it.
        let closes = |world: &World, seen: (Books, f64), label: &str| {
            let (now, moved) = (world.books(0), world.stock(0));
            let (booked, delta) = (now.net() - seen.0.net(), moved - seen.1);
            assert!((delta - booked).abs() < 1e-6 * delta.abs().max(1.0), "{label}: the stock moved {delta} and the books {booked}");
            (now, moved)
        };
        let mut rng = 11u64;
        let (mut books, mut stock) = (world.books(0), world.stock(0));
        assert_eq!(world.next_decision(&cat), Some(0), "the raider decides first");
        world.dispatch(&cat, 0, 1, Mission::Attack, &[(202, 20)], [1_000.0, 1_000.0, 0.0], 10.0).expect("the raid flies");
        let mut fought = false;
        while let Some(a) = world.next_decision(&cat) {
            if !fought && world.events.iter().any(|e| e.kind == EventKind::Fight && e.mission == Mission::Attack) {
                fought = true;
                let seen = closes(&world, (books, stock), "the raid");
                assert!(seen.0.loot_in > books.loot_in, "the fight booked no plunder");
                (books, stock) = seen;
            }
            match cat.baseline(&world.games[a].planet, world.games[a].queue, Baseline::Player, &mut rng) {
                Some(k) => world.commit(&cat, a, k),
                None => world.wait(&cat, a),
            }
        }
        assert!(fought, "the raid never fought");
        let home = closes(&world, (books, stock), "the flight home");
        assert_eq!(home.0.loot_in, books.loot_in, "the landing booked the plunder a second time");
        assert_eq!(home.0.cargo_lost, 0.0, "the raider lost cargo without a fight");
        assert!(home.1 > 0.0, "the account ended with nothing");
    }

    /// A recall at an idle login is a flow like any other action there, so the stock and the books still reconcile
    /// (e73b).
    #[test]
    fn a_recall_at_an_idle_login_keeps_the_books_balanced() {
        let Some((cat, mut world, _)) = pair(40.0) else { return eprintln!("no u1 catalogues; skipped") };
        give(&mut world, 0, 202, 10);
        world.games[0].planet.res = [50_000.0, 20_000.0, 20_000.0];
        world.next_decision(&cat).unwrap();
        world.dispatch(&cat, 0, 1, Mission::Transport, &[(202, 10)], [1000.0, 0.0, 0.0], 1.0).unwrap();
        let gap = |w: &World| w.stock(0) - w.books(0).net();
        let before = gap(&world);
        // An idle login of the sender while the fleet is still on its way out.
        world.idle_now[0] = true;
        world.recall(0, 0).unwrap();
        world.wait(&cat, 0);
        assert!((gap(&world) - before).abs() < 1e-6, "stock and books moved apart by {}", gap(&world) - before);
    }

    /// A recall turns the fleet home from where it is (`GameMission::cancel`): it leaves now, takes the time already
    /// flown to come back, carries half the fuel it paid in its hold, and lands its ships; one that is on its way home
    /// or has arrived cannot be recalled.
    #[test]
    fn a_recall_brings_the_fleet_home_in_the_time_it_has_flown() {
        let Some((cat, mut world, mut quiet)) = pair(40.0) else { return eprintln!("no u1 catalogues; skipped") };
        for w in [&mut world, &mut quiet] {
            give(w, 0, 202, 10);
            w.games[0].planet.res = [50_000.0, 20_000.0, 20_000.0];
            w.games.iter_mut().for_each(|g| g.planet.rate = [0.0; 3]);
            // This is about the recall's timing, so nobody sleeps through it.
            w.games.iter_mut().for_each(|g| g.sleep_hours = 0.0);
        }
        world.next_decision(&cat).unwrap();
        world.dispatch(&cat, 0, 1, Mission::Transport, &[(202, 10)], [1000.0, 0.0, 0.0], 1.0).unwrap();
        let sent = world.fleets[0].clone();
        let paid = 20_000.0 - world.games[0].planet.res[2];
        world.wait(&cat, 0);
        // The next login of the sender while the fleet is still on its way out.
        let now = loop {
            let a = world.next_decision(&cat).expect("the sender logs in again");
            let t = world.games[a].t;
            if a == 0 && t > sent.departed { break t }
            world.wait(&cat, a);
        };
        assert!(now < sent.arrive, "the login came after the arrival: {now} vs {}", sent.arrive);
        assert_eq!(world.recall(1, 0), Err("not your fleet"));
        world.recall(0, 0).unwrap();
        let back = world.fleets[0].clone();
        assert!(back.returning && back.arrive == now && back.home == now + (now - sent.departed), "returns {} to {}", back.arrive, back.home);
        assert_eq!(back.cargo, [1000.0, 0.0, (paid / 2.0).floor()], "the cargo and half the fuel come home");
        assert_eq!(world.recall(0, 0), Err("already returning"));
        let kinds: Vec<EventKind> = world.events.iter().map(|e| e.kind).collect();
        assert_eq!(kinds, vec![EventKind::Recall]);
        world.wait(&cat, 0);
        idle(&cat, &mut world);
        idle(&cat, &mut quiet);
        assert_eq!(world.yards[0].counts, quiet.yards[0].counts, "the ships landed");
        assert_eq!(world.games[1].planet.res, quiet.games[1].planet.res, "nothing was delivered");
        let landed = world.events.last().unwrap();
        assert!(landed.kind == EventKind::Return && landed.time == back.home && landed.gained == back.cargo, "{landed:?}");
    }

    /// A raid writes what happened to the log: the fight with the loot the target lost, the arrival, the landing.
    #[test]
    fn a_raid_is_written_to_the_event_log() {
        let Some((cat, mut raided, mut quiet)) = pair(6.0) else { return eprintln!("no u1 catalogues; skipped") };
        for w in [&mut raided, &mut quiet] {
            give(w, 0, 202, 20);
            w.yards[1].counts.iter_mut().for_each(|n| *n = 0);
            w.games[0].planet.res[2] = 20_000.0;
            w.games[1].planet.res = [30_000.0, 20_000.0, 10_000.0];
            w.games.iter_mut().for_each(|g| g.planet.rate = [0.0; 3]);
        }
        raided.next_decision(&cat).unwrap();
        raided.dispatch(&cat, 0, 1, Mission::Attack, &[(202, 20)], [0.0; 3], 10.0).unwrap();
        raided.wait(&cat, 0);
        idle(&cat, &mut raided);
        idle(&cat, &mut quiet);
        let kinds: Vec<(EventKind, Mission)> = raided.events.iter().map(|e| (e.kind, e.mission)).collect();
        assert_eq!(kinds, vec![(EventKind::Fight, Mission::Attack), (EventKind::Arrival, Mission::Attack), (EventKind::Return, Mission::Attack)]);
        let fight = &raided.events[0];
        assert!(fight.outcome == Outcome::Won && fight.ships_lost.is_empty() && fight.present.is_empty());
        for r in 0..3 {
            let lost = quiet.games[1].planet.res[r] - raided.games[1].planet.res[r];
            assert!((fight.gained[r] - lost).abs() < 1e-6, "resource {r}: logged {} lost {lost}", fight.gained[r]);
        }
        assert!(fight.gained.iter().sum::<f64>() > 0.0 && fight.time < raided.events[2].time, "the fight comes before the landing");
    }

    /// With idle logins on, an account that is saving for a build with no queue to decide still logs in, as a decision
    /// that offers nothing to build; without them it never decides.
    #[test]
    fn an_account_with_nothing_to_build_still_logs_in() {
        let Some((cat, mut with, mut without)) = pair(6.0) else { return eprintln!("no u1 catalogues; skipped") };
        let dear = (0..cat.objects.len()).find(|&k| cat.objects[k].queue == 0 && cat.objects[k].price[0][0] > 1e9).or_else(|| (0..cat.objects.len()).filter(|&k| cat.objects[k].queue == 0).max_by(|&x, &y| cat.objects[x].price[0].iter().sum::<f64>().total_cmp(&cat.objects[y].price[0].iter().sum::<f64>()))).unwrap();
        for w in [&mut with, &mut without] {
            // Saving up for the dearest building: the economy has nothing to decide for hours.
            w.games[0].commit[0] = Some(dear);
            w.games[0].planet.rate = [0.0; 3];
            w.next[0] = cat.peek(&mut w.games[0]);
        }
        with.enable_idle_logins(&cat);
        let mut logins = Vec::new();
        while let Some(a) = with.next_decision(&cat) {
            if a == 0 {
                assert_eq!(with.decision_kind(0), Decision::Idle);
                assert!(!(0..cat.objects.len()).any(|k| with.legal(&cat, 0, k)), "an idle login offers a build");
                logins.push(with.games[0].t);
            }
            with.wait(&cat, a);
        }
        assert!(logins.len() >= 3 && logins.windows(2).all(|w| w[0] < w[1]), "idle logins {logins:?}");
        let mut quiet_logins = 0;
        while let Some(a) = without.next_decision(&cat) {
            quiet_logins += usize::from(a == 0);
            without.wait(&cat, a);
        }
        assert_eq!(quiet_logins, 0, "an account saving for a build decided without idle logins");
    }

    /// OGame's 'i' status: no login for seven days.
    #[test]
    fn an_account_that_has_not_logged_in_for_a_week_is_inactive() {
        let Some((mut cat, mut world, _)) = pair(24.0 * 30.0) else { return eprintln!("no u1 catalogues; skipped") };
        cat.login_gaps = vec![8.0 * 86_400.0];
        world.games[0].login_times = vec![0.0];
        let day = 86_400.0;
        assert!(!world.inactive(&cat, 0, 6.0 * day), "six days after a login");
        assert!(world.inactive(&cat, 0, 7.5 * day), "seven and a half days after the last login");
        assert!(!world.inactive(&cat, 0, 8.5 * day), "back a half day after the next login");
        cat.login_gaps = Vec::new();
        assert!(!world.inactive(&cat, 0, 100.0 * day), "an account with no login table is always there");
    }

    /// The host's own answers to missions it flew (`ai:rl-mission-cases`): a world that starts at the case's planets must
    /// reproduce the host's departure, flight, fuel, stock, ships, debris and moon, second for second.
    mod mission_parity {
        use super::super::*;
        use super::DATA;
        use serde::Deserialize;
        use crate::HashMap;

        #[derive(Deserialize)]
        struct Cases { cases: Vec<Case> }

        #[derive(Deserialize)]
        struct Case {
            #[serde(default)] case_seed: u32,
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
            #[serde(default)] missiles: Option<i64>,
            #[serde(default)] priority: Option<usize>,
            #[serde(default)] holding_hours: Option<i64>,
            #[serde(default)] rank1_points: Option<i64>,
            #[serde(default)] target_type: i64,
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
            /// Moon destruction: the fight's seed, the two rolls, the moon's diameter, whether it stands afterwards.
            #[serde(default)] abm_before: Option<[i64; 2]>,
            #[serde(default)] seed: Option<u64>,
            #[serde(default)] rolls: Option<Rolls>,
            #[serde(default)] moon_before: Option<MoonBefore>,
            #[serde(default)] moon_exists_after: Option<bool>,
            /// Expedition: what was found (the host's outcome name), dark matter and deep-space debris.
            #[serde(default)] outcome: Option<String>,
            #[serde(default)] found_ships: HashMap<String, i64>,
            #[serde(default)] user_changes: serde_json::Value,
            #[serde(default)] debris_slot16: Option<[i64; 3]>,
        }

        #[derive(Deserialize)]
        struct Rolls { destroy: i64, loss: i64 }

        #[derive(Deserialize)]
        struct MoonBefore { diameter: i64 }

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
        struct BattleAnswer {
            #[serde(default)] seed: u64,
            #[serde(default)] report: Option<Report>,
            /// A moon destruction's fight is flat: its survivors sit beside the rounds.
            #[serde(default)] rounds: u32,
            #[serde(default)] attacker_left: serde_json::Value,
            #[serde(default)] defender_left: serde_json::Value,
        }

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
                "colonise" | "expedition" => ("more-mission-cases-u1.json", "units-parity/econ-u1-colonies.json"),
                "missile" | "moon_destruction" => ("more-mission-cases-u1.json", "units-parity/econ-u1.json"),
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
            match case.mission.as_str() {
                "colonise" => return play_colonise(cat, case, index, setup),
                "expedition" => return play_expedition(cat, case, index, setup),
                _ => {}
            }
            let (missile, moon_strike) = (case.mission == "missile", case.mission == "moon_destruction");
            // A missile may also go for a moon: its defences are the moon's, and the planet's interceptors help it.
            let moon = moon_strike || (missile && case.input.target_type == 3);
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
                stand_in = PlanetState { id: -1, coordinates: at, resources: [0.0; 3], ships: HashMap::default(), defence: HashMap::default() };
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
                _ if recycling || moon_strike => (0..cat.planets.len()).find(|&c| c != origin_config)?,
                _ if deploying => config_of(cat, target.id).unwrap_or(origin_config),
                // A missile's target the catalogue has no tables for stands in as a deploy's second planet does.
                _ if missile && moon => (0..cat.planets.len()).find(|&c| c != origin_config)?,
                _ if missile => config_of(cat, target.id).or_else(|| (0..cat.planets.len()).find(|&c| c != origin_config))?,
                _ => config_of(cat, target.id)?,
            };
            let mut world = World::new(cat, &[origin_config, target_config], 1e6, 0.0, 1);
            world.arm(vec![attacker_rules, defender_rules], vec![coords(&case.input.origin), coords(target)]);
            if let Some(debris) = case.input.debris_before {
                world.debris[1] = debris.map(|r| r as f64);
                world.fields[1] = true;
            }
            if (deploying || missile) && target_player.id == case.input.origin_player.id {
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
                world.games[a].planet.settle();
                world.games[a].t = case.dispatch.departure.unwrap_or(0.0);
                // The login clock walks second by second from its last entry, so it starts where the case does.
                world.games[a].login_times = vec![world.games[a].t];
                cat.refresh(&mut world.games[a].planet);
            }
            if moon {
                // The target is a moon: what stands on it is the moon's, and the slot's own planet stands empty beside it.
                let hold = &case.input.target.as_ref()?;
                let mut held = hold.ships.clone();
                held.extend(hold.defence.clone());
                let diameter = case.arrival.as_ref().and_then(|a| a.moon_before.as_ref()).map_or(0, |m| m.diameter);
                let mut counts = vec![0u64; world.yards[1].counts.len()];
                for (id, amount) in units_of(&held) {
                    counts[world.rules[1].units.index(id as i64).expect("a unit in the catalogue")] = amount as u64;
                }
                world.yards[1].counts.iter_mut().for_each(|n| *n = 0);
                // The planet beside a moon lends it its interceptors (the host reports them as the parent's).
                let parent = case.arrival.as_ref().and_then(|a| a.abm_before).map_or(0, |abm| abm[1]);
                if let Some(strike) = &world.rules[1].strike {
                    let u = world.rules[1].units.index(strike.interceptor as i64).expect("interceptors in the catalogue");
                    world.yards[1].counts[u] = parent as u64;
                }
                world.moons[1] = true;
                world.moon_holds[1] = MoonHold { diameter, stock: hold.resources, counts };
            }
            let mission = match case.mission.as_str() {
                "moon_destruction" => Mission::MoonDestroy,
                "attack" => Mission::Attack,
                "espionage" => Mission::Espionage,
                "recycle" => Mission::Recycle,
                "deploy" => Mission::Deploy,
                _ => Mission::Transport,
            };
            let stands_in = missile && (moon || config_of(cat, target.id).is_none());
            if deploying || stands_in {
                // The host's second planets did not produce between the dispatch and the arrival.
                for k in (0..cat.objects.len()).filter(|&k| cat.objects[k].queue == 0) {
                    world.games[1].planet.levels[k] = 0;
                }
                world.games[1].planet.rate = [0.0; 3];
            }
            let before = world.games[0].planet.res;
            let flew = match missile {
                true => world.launch_missiles(cat, 0, 1, moon, case.input.missiles.unwrap_or(0), case.input.priority.unwrap_or(0)),
                false => world.dispatch(cat, 0, 1, mission, &units_of(&case.input.units), case.input.cargo, case.input.speed),
            };
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
            if moon {
                world.rng = arrival.seed.unwrap_or(0);
                world.recorded_rolls = arrival.rolls.as_ref().map(|r| [r.destroy, r.loss]);
                if let Some(battle) = arrival.battle.as_ref().filter(|b| b.rounds > 0 || !b.attacker_left.is_null()) {
                    world.recorded = Some(combat::Recorded {
                        engine: combat::EngineResult { rounds: battle.rounds, attacker_left: counted(&battle.attacker_left), defender_left: counted(&battle.defender_left) },
                        repaired: combat::Units::new(),
                        moon_roll: 0,
                    });
                }
            }
            if let (false, Some(battle)) = (moon, &arrival.battle) {
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
            if let (true, Some(before)) = (deploying || stands_in, &arrival.target_before) {
                // The host's second planet had been producing with buildings the catalogue knows nothing about, so the
                // referee hands over its stock at the arrival.
                world.games[1].planet.res = before.resources;
                world.games[1].t = arrival.time;
            }
            // The case drawer loads the target's page at the arrival (`updatedState`), which writes its stock.
            world.catch_up(cat, 1, arrival.time);
            world.games[1].planet.settle();
            run_until(cat, &mut world, arrival.time);
            if let Some(spy) = &arrival.espionage {
                spied(cat, spy, &world, index);
            }
            if let (Some(back), true) = (arrival.return_mission.as_ref().and_then(|b| b.cargo), case.mission != "attack") {
                assert_eq!(world.fleets[0].cargo.map(|r| r as i64), back, "case {index}: the cargo flying home");
            }
            if moon {
                moon_after(&world, arrival, index);
            } else {
                stock(arrival.target_after.as_ref(), &world, 1, index, "at arrival");
            }
            if missile {
                assert!(world.fleets.is_empty(), "case {index}: a missile does not come home");
            }
            if deploying {
                assert!(world.fleets.is_empty(), "case {index}: a deployed fleet stays on the planet");
            }
            if let Some(debris) = arrival.debris_after {
                assert_eq!(world.debris[1].map(|r| r as i64), debris, "case {index}: debris at arrival");
            }
            if let Some(moon) = &arrival.moon {
                assert_eq!(world.moons[1], moon.as_bool().unwrap_or(!moon.is_null()), "case {index}: moon at arrival");
            }
            let Some(back) = case.arrival.as_ref().and_then(|a| a.return_mission.as_ref()) else {
                assert!(world.fleets.is_empty(), "case {index}: no fleet is left to fly home");
                return Some(true);
            };
            let home = world.fleets.iter().find(|f| f.owner == 0).expect("the fleet comes home");
            assert_eq!((home.arrive, home.home), (back.departure, back.arrival), "case {index}: the flight home");
            let Some(done) = &case.back else { return Some(true) };
            world.catch_up(cat, 0, done.time);
            world.games[0].planet.settle();
            run_until(cat, &mut world, done.time);
            stock(done.origin_after.as_ref(), &world, 0, index, "back home");
            Some(true)
        }

        /// What a moon holds after a moon destruction, against the host's: it stands or is gone, and what stood on it.
        fn moon_after(w: &World, arrival: &Arrival, index: usize) {
            let stands = arrival.moon_exists_after.unwrap_or(true);
            assert_eq!(w.moons[1], stands, "case {index}: the moon stands after the strike");
            let Some(after) = arrival.target_after.as_ref().filter(|_| stands) else { return };
            let hold = &w.moon_holds[1];
            for r in 0..3 {
                assert!((hold.stock[r] - after.resources[r]).abs() < 1e-6, "case {index}: moon stock {r} world {} host {}", hold.stock[r], after.resources[r]);
            }
            let held: HashMap<String, i64> = w.rules[1].units.units.iter().zip(&hold.counts).filter(|(_, &n)| n > 0).map(|(u, &n)| (u.id.to_string(), n as i64)).collect();
            let mut host = after.ships.clone();
            host.extend(after.defence.clone());
            assert_eq!(held, host, "case {index}: what stands on the moon");
        }

        /// An expedition case: the dispatch rules, then the arrival at the end of the hold with the host's draws (its seed,
        /// and the engine's result when it fought), against what the host found, lost, gained and flew home with.
        fn play_expedition(cat: &Catalogue, case: &Case, index: usize, setup: &Setup) -> Option<bool> {
            let origin_config = config_of(cat, case.input.origin.id)?;
            let to = case.input.target_coordinates.map(|c| Coord { galaxy: c[0], system: c[1], position: c[2] })?;
            let mut world = World::new(cat, &[origin_config], 1e6, 0.0, 1);
            world.arm(vec![rules_for(cat, case.input.origin_player.class)?], vec![coords(&case.input.origin)]);
            world.colonisation = setup.colonisation.clone();
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
            let before = world.games[0].planet.res;
            let hours = case.input.holding_hours.unwrap_or(0);
            let flew = world.expedition(cat, 0, to, &units_of(&case.input.units), case.input.cargo, case.input.speed, hours);
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
            world.rank1_points = case.input.rank1_points?;
            let engine = arrival.battle.as_ref().and_then(|b| b.report.as_ref()).map(|r| combat::EngineResult {
                rounds: r.rounds.max(1),
                // The report is the expedition fleet's: the enemy is its attacker.
                attacker_left: counted(&r.defender_left),
                defender_left: counted(&r.attacker_left),
            });
            world.recorded_expedition = Some((case.case_seed, engine));
            run_until(cat, &mut world, arrival.time);
            let found = world.events.iter().rev().find(|e| e.kind == EventKind::Arrival && e.mission == Mission::Expedition).unwrap_or_else(|| panic!("case {index}: the expedition did not arrive"));
            let host_name = arrival.outcome.as_deref()?;
            let host_kind = match (host_name, arrival.found_ships.is_empty()) {
                ("ships", true) => expedition::Kind::Nothing,
                (name, _) => expedition::Kind::named(name).unwrap_or_else(|| panic!("case {index}: unknown outcome {name}")),
            };
            assert_eq!(found.outcome, Outcome::Found(host_kind), "case {index}: what the expedition found");
            let dark = arrival.user_changes.get("dark_matter").map_or(0, |pair| pair[1].as_i64().unwrap() - pair[0].as_i64().unwrap());
            assert_eq!(world.dark_matter[0], dark, "case {index}: dark matter");
            let field = world.deep_space.get(&(to.galaxy, to.system)).copied().unwrap_or([0; 3]);
            assert_eq!(field, arrival.debris_slot16.unwrap_or([0; 3]), "case {index}: debris over deep space");
            let Some(back) = arrival.return_mission.as_ref() else {
                assert!(world.fleets.is_empty(), "case {index}: nothing is left to fly home");
                return Some(true);
            };
            // A speedup past the whole trip brings the fleet home the moment it is due: it has landed already.
            if back.arrival <= back.departure {
                assert!(world.fleets.is_empty(), "case {index}: a fleet due home at once has landed");
                // The host stamps the landing with the return's own (earlier) second, before the hold ended, so only
                // the ships are comparable: the world's clock has passed it.
                if let Some(done) = case.back.as_ref().and_then(|d| d.origin_after.as_ref()) {
                    assert_eq!(holds(&world, 0), { let mut host = done.ships.clone(); host.extend(done.defence.clone()); host }, "case {index}: the ships landed at once");
                }
                return Some(true);
            }
            let home = world.fleets.iter().find(|f| f.owner == 0).unwrap_or_else(|| panic!("case {index}: the fleet comes home"));
            assert_eq!(home.arrive, back.departure, "case {index}: the flight home leaves at the end of the hold");
            assert_eq!(home.home - home.arrive, (back.arrival - back.departure).max(0.0), "case {index}: the flight home");
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
