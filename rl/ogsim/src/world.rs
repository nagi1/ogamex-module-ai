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

use crate::{HashMap, HashSet};
use std::sync::Arc;

use crate::combat::{self, Battle, CombatCatalogue};
use crate::economy::{as_stored, next_random, weighted, Books, Catalogue, Game, PlanetConfig, WEIGHTS};
use crate::expedition::{self, Draws};
use crate::fleet::{self, Coord, FleetCatalogue, FleetSettings, Relocation, SystemState, WreckRules};
use crate::strike::{self, StrikeRules};
use crate::units::{Shipyard, Units};

mod strikes;
mod wrecks;
mod relocation;
pub use wrecks::{Wreck, WreckState};

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
    AcsDefend = 5,
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
    /// What a fight's target salvaged into its own stock (the host's collector rule): stock that came with no delivery.
    pub salvaged: [f64; 3],
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

/// What a moon has that a slot does not: its diameter, which the moon destruction rolls read. Its stock, units and
/// buildings are its own slot's (`World::moon_of`).
#[derive(Clone, Debug, Default)]
pub struct MoonHold {
    pub diameter: i64,
}

/// The shipyard's queue for `World::halve` and `World::halving_price`, after the building (0) and research (1) queues.
pub const UNIT_QUEUE: usize = 2;

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
    /// L5: the union (ACS attack) it flies in, named by the first fleet's id; None for a lone fleet.
    pub union: Option<usize>,
}

impl Fleet {
    /// The second the world next acts on this fleet: its arrival and hold, or its landing at home.
    pub fn next(&self) -> f64 {
        if self.returning { self.home } else { self.arrive + self.holding }
    }
}

/// FleetUnion's limits and FleetUnionService::MAX_DELAY_PERCENTAGE.
const UNION_FLEETS: usize = 16;
const UNION_PLAYERS: usize = 5;
const UNION_DELAY: f64 = 0.30;

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
    /// Each planet slot's moon diameter; read only where `moons` is true.
    pub moon_holds: Vec<MoonHold>,
    /// M3: the slot of each planet's moon, and the planet under each moon slot. A moon is a slot of its account as the
    /// host's moon is a planet row: its own stock, units, buildings and queue, at its planet's coordinates.
    pub moon_of: Vec<Option<usize>>,
    pub moon_at: Vec<Option<usize>>,
    /// Every fleet arrival, fight, landing, loss and recall, oldest first.
    pub events: Vec<Event>,
    /// Dark matter per slot; an account's is the capital's.
    pub dark_matter: Vec<i64>,
    /// W15: per slot (the capital's is the account's), each hired officer's last second, and every hire made.
    pub officers: Vec<HashMap<String, f64>>,
    pub officer_log: Vec<(usize, f64, String, i64)>,
    /// W14: every yard order (slot, second, host unit id, amount asked, amount queued), for the host's replay of the world.
    pub unit_log: Vec<(usize, f64, u32, u64, u64)>,
    /// Every fleet as it left, with the speed it was sent at (W14: the trace a host replay dispatches). Kept only with
    /// `keep_launches` (the trace); a season launches hundreds of thousands of fleets and reads none of them back.
    pub launch_log: Vec<(Fleet, f64)>,
    pub keep_launches: bool,
    /// Fleets launched so far: the next fleet's id.
    pub launched: usize,
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
    /// L4: the second each slot's jump gate is ready again (0: never used).
    pub gate_ready: Vec<f64>,
    /// Every phalanx scan and gate jump: (slot, second, whether a jump).
    pub gear_log: Vec<(usize, f64, bool)>,
    /// L6: every halving (slot, second, object, dark matter paid); a build halved once is completed by the next.
    pub halving_log: Vec<(usize, f64, usize, i64)>,
    /// L2: the wreck fields per planet slot, oldest first; a General's losses riding home, by fleet id; and the repaired
    /// ships each collection landed (slot, second, ships).
    pub wrecks: HashMap<usize, Vec<Wreck>>,
    pub carried: HashMap<usize, Vec<(u32, i64)>>,
    pub wreck_log: Vec<(usize, f64, i64)>,
    /// Planet moves: the scheduled ones (slot to target, due second), the second each slot last moved or cancelled,
    /// and the moves carried out.
    pub moves: HashMap<usize, (Coord, f64)>,
    pub moved_at: HashMap<usize, f64>,
    pub move_log: Vec<(usize, f64)>,
    /// Per slot, the second of the last attack event logged at it: the errand state reads it at every login, and a scan
    /// of the whole log there cost a season most of its time.
    pub last_attacked: HashMap<usize, f64>,
    halved: HashSet<(usize, u64)>,
    /// Per capital, the second its account joined the world's alliance, or None (the season draws membership); the
    /// host lets ACS defend fly only between buddies or members of one alliance.
    pub alliance_at: Vec<Option<f64>>,
    /// L5: every fleet that fought in a union beside its first: (slot, second).
    pub union_log: Vec<(usize, f64)>,
    rng: u64,
    /// `keyed` dice: an account's k-th draw of each purpose is fixed by the season's seed, so one account's choices
    /// (which reorder the world's events) do not reshuffle every other account's luck. Off for the parity replays,
    /// which hand the host's seed over through `rng`.
    pub keyed: bool,
    drawn: HashMap<(usize, u64), u64>,
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
        World { games, account: (0..n).collect(), colonies: vec![Vec::new(); n], colonisation: None, taken: Vec::new(), recorded_colony: None, next, rules: Vec::new(), coords: Vec::new(), yards: Vec::new(), fleets: Vec::new(), recorded: None, recorded_roll: None, unplaced: 0, reports: Vec::new(), fields: vec![false; n], debris: vec![[0.0; 3]; n], moons: vec![false; n], moon_holds: vec![MoonHold::default(); n], moon_of: vec![None; n], moon_at: vec![None; n], events: Vec::new(), dark_matter: vec![0; n], officers: vec![HashMap::default(); n], officer_log: Vec::new(), unit_log: Vec::new(), launch_log: Vec::new(), keep_launches: false, launched: 0, deep_space: HashMap::default(), rank1_points: 100_000, recorded_expedition: None, recorded_rolls: None, dice: 0, stream: expedition::Mt19937::new(0), idle_logins: false, idle_next: vec![NEVER; n], idle_now: vec![false; n], gate_ready: vec![0.0; n], gear_log: Vec::new(), halving_log: Vec::new(), wrecks: HashMap::default(), carried: HashMap::default(), wreck_log: Vec::new(), moves: HashMap::default(), moved_at: HashMap::default(), move_log: Vec::new(), last_attacked: HashMap::default(), halved: HashSet::default(), alliance_at: vec![None; n], union_log: Vec::new(), reach: Vec::new(), rng: seed ^ 0x9e37_79b9_7f4a_7c15, keyed: false, drawn: HashMap::default() }
    }

    /// Places every account's planet at its coordinates with the ships and defence its config starts with, so fleets can
    /// fly. One `Rules` per account: that account's character class decides its speeds, capacity, fuel and loot share.
    pub fn arm(&mut self, rules: Vec<Arc<Rules>>, coords: Vec<Coord>) {
        assert_eq!(coords.len(), self.games.len(), "one coordinate per account");
        assert_eq!(rules.len(), self.games.len(), "one rules per account");
        self.yards = rules.iter().zip(&self.games).map(|(r, g)| r.units.shipyard(g.planet.config)).collect();
        self.dark_matter = rules.iter().map(|r| r.settings.dark_matter_initial).collect();
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
            let due = self.moves.iter().map(|(&p, &(_, at))| (at, p)).min_by(|x, y| x.0.total_cmp(&y.0).then(x.1.cmp(&y.1)));
            if let Some((_, p)) = due.filter(|&(at, _)| at <= self.moves_at(a) && fleet.is_none_or(|f| at <= self.fleets[f].next())) {
                self.move_due(cat, p);
                continue;
            }
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
        // What the account holds moved this slot's next event past the second it was scheduled for: it waits in the
        // world's order instead of jumping there, so a fleet landing on it in between finds it at its own second.
        let due = cat.peek(&mut self.games[a]);
        if due > self.next[a] {
            self.next[a] = due;
            return false;
        }
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

    /// Whether slots a and b belong to two accounts of the alliance at second `at`.
    pub fn mates(&self, a: usize, b: usize, at: f64) -> bool {
        let member = |s: usize| self.alliance_at.get(self.account[s]).copied().flatten().is_some_and(|join| at >= join);
        self.account[a] != self.account[b] && member(a) && member(b)
    }

    /// Every planet slot of the account whose capital is `capital`, the capital first.
    pub fn planets_of(&self, capital: usize) -> Vec<usize> {
        std::iter::once(capital).chain(self.colonies[capital].iter().copied()).collect()
    }

    /// Every slot the account ever held: its planets, its moons and the moons it lost (their books stay its own).
    fn slots_of(&self, capital: usize) -> Vec<usize> {
        (0..self.account.len()).filter(|&s| self.account[s] == capital).collect()
    }

    /// Row 23: the account's books, summed over its slots (a colony's and a moon's are their owner's).
    pub fn books(&self, capital: usize) -> Books {
        let mut books = Books::default();
        for slot in self.slots_of(capital) {
            books.add(&self.games[slot].planet.books);
        }
        books
    }

    /// Row 23: the stock those books reconcile - every planet's stores plus the cargo flying, which the fleet took out
    /// of its planet at dispatch and delivers when it lands. A sample is only comparable with another taken at the same
    /// second, which is how the season reads them.
    pub fn stock(&self, capital: usize) -> f64 {
        let planets = self.slots_of(capital);
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
        let slot = self.push_slot(cat, game, capital, coord);
        self.colonies[capital].push(slot);
        slot
    }

    /// M3: a moon comes up over planet slot `planet` at second `at` as a slot of the same account, on the moon config of
    /// its capital's class, with nothing built, nothing stored and the account's research; it is no colony (it takes no
    /// planet slot). Returns its slot.
    pub fn add_moon(&mut self, cat: &Catalogue, planet: usize, diameter: i64, at: f64) -> usize {
        let capital = self.account[planet];
        let home = &self.games[capital];
        let mut game = cat.new_paused_game(cat.moon_config(home.planet.config), home.horizon / 3600.0, home.bonus_hours, home.login_rng);
        game.t = at;
        game.in_world = true;
        game.free = [at, f64::INFINITY];
        game.login_times = home.login_times.clone();
        (game.sleep_start, game.sleep_hours) = (home.sleep_start, home.sleep_hours);
        for k in (0..cat.objects.len()).filter(|&k| cat.objects[k].queue == 1) { game.planet.levels[k] = home.planet.levels[k] }
        cat.refresh(&mut game.planet);
        let slot = self.push_slot(cat, game, capital, self.coords[planet]);
        self.moons[planet] = true;
        self.moon_holds[planet] = MoonHold { diameter: diameter.min(MOON_DIAMETER_CAP) };
        self.moon_of[planet] = Some(slot);
        self.moon_at[slot] = Some(planet);
        slot
    }

    /// A moon destroyed is gone with all it held: its slot is emptied and plays no more, and its planet has no moon.
    pub(crate) fn lose_moon(&mut self, planet: usize) {
        self.moons[planet] = false;
        self.moon_holds[planet] = MoonHold::default();
        let Some(m) = self.moon_of[planet].take() else { return };
        self.yards[m].counts.iter_mut().for_each(|n| *n = 0);
        self.yards[m].orders.clear();
        let store = self.games[m].planet.res;
        let books = &mut self.games[m].planet.books;
        books.cargo_lost += weighted(store);
        self.games[m].planet.res = [0.0; 3];
        self.games[m].planet.settle();
        self.games[m].done = true;
    }

    /// L3: the deciding account reads planet `target` from its moon slot's sensor phalanx (`PhalanxService`): same
    /// galaxy, systems within the level's range around the donut, 5,000 deuterium from the moon. Returns the fleets the
    /// host lists: those flying to the planet and those it sent that will come back to it.
    pub fn phalanx(&mut self, cat: &Catalogue, moon: usize, target: usize) -> Result<Vec<usize>, &'static str> {
        let rules = Arc::clone(&self.rules[moon]);
        let gear = rules.settings.moon_gear.as_ref().ok_or("no moon gear")?;
        if self.moon_at[moon].is_none() || self.moon_at[target].is_some() { return Err("phalanx from a moon onto a planet") }
        let level = self.moon_level(cat, moon, gear.phalanx_object);
        let range = gear.phalanx_range[level.min(gear.phalanx_range.len() - 1)];
        let (from, to) = (self.coords[moon], self.coords[target]);
        let systems = (from.system - to.system).abs();
        if range < 0 || from.galaxy != to.galaxy || systems.min((systems - rules.settings.number_of_systems).abs()) > range {
            return Err("out of phalanx range");
        }
        let now = self.games[self.account[moon]].t;
        self.catch_up(cat, moon, now);
        if self.games[moon].planet.res[2] < gear.phalanx_cost { return Err("not enough deuterium") }
        self.games[moon].planet.res[2] -= gear.phalanx_cost;
        self.games[moon].planet.books.scans += WEIGHTS[2] * gear.phalanx_cost;
        self.gear_log.push((moon, now, false));
        self.games[moon].planet.settle();
        self.next[moon] = cat.peek(&mut self.games[moon]);
        Ok((0..self.fleets.len()).filter(|&f| {
            let fleet = &self.fleets[f];
            (fleet.target == target && !fleet.returning) || (fleet.owner == target && (fleet.returning || fleet.mission != Mission::Deploy))
        }).collect())
    }

    /// L4: the deciding account moves ships through the jump gate from its moon slot `from` to its moon slot `to`
    /// (`JumpGateService`): both gates built and rested, only the ships a gate carries, there at once; both gates then
    /// rest as long as the lower level says.
    pub fn jump(&mut self, cat: &Catalogue, from: usize, to: usize, ships: &[(u32, i64)]) -> Result<(), &'static str> {
        let rules = Arc::clone(&self.rules[from]);
        let gear = rules.settings.moon_gear.as_ref().ok_or("no moon gear")?;
        if from == to || self.moon_at[from].is_none() || self.moon_at[to].is_none() || self.account[from] != self.account[to] {
            return Err("a jump between two moons of one account");
        }
        let level = self.moon_level(cat, from, gear.gate_object).min(self.moon_level(cat, to, gear.gate_object));
        if level == 0 { return Err("no jump gate") }
        let now = self.games[self.account[from]].t;
        if self.gate_ready[from] > now || self.gate_ready[to] > now { return Err("jump gate resting") }
        for slot in [from, to] {
            self.catch_up(cat, slot, now);
            self.settle_yard(cat, slot);
        }
        let mut moved = Vec::new();
        for &(id, n) in ships.iter().filter(|&&(_, n)| n > 0) {
            if !gear.gate_ships.contains(&id) { return Err("a ship the gate does not carry") }
            let u = rules.units.index(id as i64).ok_or("no such ship")?;
            if self.yards[from].counts[u] < n as u64 { return Err("not enough ships") }
            moved.push((u, n as u64));
        }
        if moved.is_empty() { return Err("no ships") }
        for (u, n) in moved {
            self.yards[from].counts[u] -= n;
            self.yards[to].counts[u] += n;
        }
        self.gear_log.push((from, now, true));
        let rest = now + gear.gate_cooldown[level.min(gear.gate_cooldown.len() - 1)];
        for slot in [from, to] {
            self.gate_ready[slot] = rest;
            self.sync_units(cat, slot);
            self.next[slot] = cat.peek(&mut self.games[slot]);
        }
        Ok(())
    }

    /// L6: the deciding account pays dark matter to halve the build running in queue q of slot s (`HalvingService`): the
    /// price is read on the time left, the cut is half the build's own time within the queue's cap, and a build already
    /// halved is completed instead. Returns the dark matter paid.
    pub fn halve(&mut self, cat: &Catalogue, s: usize, q: usize) -> Result<i64, &'static str> {
        let account = self.account[s];
        let halving = self.rules[account].settings.halving.clone().ok_or("no halving tables")?;
        let now = self.games[account].t;
        self.catch_up(cat, s, now);
        self.settle_yard(cat, s);
        let (end, k, cost) = self.halving_price(cat, s, q).ok_or("nothing to halve")?;
        if self.dark_matter[account] < cost { return Err("not enough dark matter") }
        if q == UNIT_QUEUE { return Ok(self.halve_order(cat, s, now, cost)) }
        let start = self.games[s].history.iter().rev().find(|h| h.1 == k && h.2 == end).map_or(now, |h| h.0);
        let queue = if q == 0 { "building" } else { "research" };
        let left = end - now;
        let again = self.halved.contains(&(s, start.to_bits()));
        let cut = if again { left } else { ((end - start) / 2.0).floor().min(halving.max_reduction[queue]).min(left) };
        let new_end = end - cut;
        self.dark_matter[account] -= cost;
        self.halved.insert((s, start.to_bits()));
        self.halving_log.push((s, now, k, cost));
        let game = &mut self.games[s];
        let at = game.pending.iter().position(|&p| p == (end, k)).expect("the build just found");
        game.pending.remove(at);
        let at = game.pending.partition_point(|p| p.0 <= new_end);
        game.pending.insert(at, (new_end, k));
        if game.free[q] == end { game.free[q] = new_end }
        if let Some(h) = game.history.iter_mut().rev().find(|h| h.1 == k && h.2 == end) { h.2 = new_end }
        // A research or a lab that ends sooner frees the account's other planets sooner.
        for p in self.planets_of(account) {
            self.games[p].account_pending = self.account_holds(cat, p);
            self.next[p] = cat.peek(&mut self.games[p]);
        }
        self.next[s] = cat.peek(&mut self.games[s]);
        Ok(cost)
    }

    /// `HalvingService::halveUnit` on slot s's running yard order: the cut is half the order's own time (no cap), the units
    /// that time held are handed over at once, and the order and those behind it move up by the cut; a halved order is
    /// completed instead.
    fn halve_order(&mut self, cat: &Catalogue, s: usize, now: f64, cost: i64) -> i64 {
        let account = self.account[s];
        let now = now as i64;
        let yard = &mut self.yards[s];
        let o = &mut yard.orders[0];
        let left = o.end - now;
        let (cut, award) = match o.halved {
            true => (left, o.amount - o.done),
            false => { let cut = ((o.end - o.start) / 2).min(left); (cut, ((cut / o.each) as u64).min(o.amount - o.done)) }
        };
        o.halved = true;
        o.done += award;
        yard.counts[o.unit] += award;
        let unit = o.unit;
        for o in yard.orders.iter_mut() {
            o.start -= cut;
            o.end -= cut;
        }
        yard.orders.retain(|o| o.done < o.amount);
        self.dark_matter[account] -= cost;
        self.halving_log.push((s, now as f64, unit, cost));
        self.sync_units(cat, s);
        self.next[s] = cat.peek(&mut self.games[s]);
        cost
    }

    /// The build running in queue q of slot s (its end and object, or for the yard `UNIT_QUEUE` its order's end and
    /// unit) and the dark matter a halving of it costs now.
    pub fn halving_price(&self, cat: &Catalogue, s: usize, q: usize) -> Option<(f64, usize, i64)> {
        let account = self.account[s];
        let halving = self.rules[account].settings.halving.as_ref()?;
        let now = self.games[account].t;
        let running = match q {
            UNIT_QUEUE => self.yards[s].orders.first().filter(|o| o.start as f64 <= now && o.end as f64 > now).map(|o| (o.end as f64, o.unit)),
            _ => self.games[s].pending.iter().find(|&&(end, k)| cat.objects[k].queue == q && end > now).copied(),
        };
        let (end, k) = running?;
        let queue = ["building", "research", "unit"][q];
        let cost = (((end - now) / 60.0 / 30.0) * halving.per_half_hour as f64).ceil() as i64;
        Some((end, k, cost.max(halving.min_cost).min(halving.max_cost[queue])))
    }

    /// The level of host object `id` on slot s (0 where the catalogue has no such object).
    pub fn moon_level(&self, cat: &Catalogue, s: usize, id: i64) -> usize {
        cat.objects.iter().position(|o| o.id == id).map_or(0, |k| self.games[s].planet.levels[k] as usize)
    }

    /// The planet a slot stands over: itself, or a moon's planet. Debris, a field and a moon belong to the position.
    pub fn ground(&self, slot: usize) -> usize {
        self.moon_at[slot].unwrap_or(slot)
    }

    /// Every per-slot table grown by one slot of account `capital` holding no unit, whose yard builds what the capital's
    /// class may (the capital's yard says which).
    fn push_slot(&mut self, cat: &Catalogue, game: Game, capital: usize, coord: Coord) -> usize {
        let yard = &self.yards[capital];
        self.yards.push(Shipyard { counts: vec![0; yard.counts.len()], allowed: yard.allowed.clone(), orders: Vec::new() });
        self.rules.push(Arc::clone(&self.rules[capital]));
        self.coords.push(coord);
        self.fields.push(false);
        self.debris.push([0.0; 3]);
        self.moons.push(false);
        self.moon_holds.push(MoonHold::default());
        self.moon_of.push(None);
        self.moon_at.push(None);
        self.dark_matter.push(0);
        self.officers.push(HashMap::default());
        self.idle_next.push(NEVER);
        self.idle_now.push(false);
        self.gate_ready.push(0.0);
        self.account.push(capital);
        self.colonies.push(Vec::new());
        self.next.push(0.0);
        self.games.push(game);
        let slot = self.games.len() - 1;
        self.sync_units(cat, slot);
        self.next[slot] = cat.peek(&mut self.games[slot]);
        slot
    }

    /// Account a commits its deciding queue to object k (`Catalogue::step` without running its clock ahead of the others).
    pub fn commit(&mut self, cat: &Catalogue, a: usize, k: usize) {
        if self.idle_now[a] { return self.wait(cat, a) }
        debug_assert!(self.legal(cat, a, k), "account {a} cannot start object {k} now");
        self.sync_peers(cat, a);
        self.games[a].planet.research_multiplier = self.hired(a).map(|o| o.research_multiplier).fold(1.0, f64::min);
        cat.decide(&mut self.games[a], Some(k));
        self.next[a] = cat.peek(&mut self.games[a]);
    }

    /// Account a lets this login pass and decides again at its next one.
    /// Spends one of account 0's draws (the world's, unkeyed): the `jolt` probe's change that changes nothing a player chose.
    pub(crate) fn log_launch(&mut self, speed: f64) {
        self.launched += 1;
        if self.keep_launches { self.launch_log.push((self.fleets.last().expect("just sent").clone(), speed)) }
    }

    pub fn jolt(&mut self) { self.draw(0, 1); }

    /// One draw for slot a's account and `purpose`: the shared stream, or with `keyed` the account's own.
    fn draw(&mut self, a: usize, purpose: u64) -> u64 {
        if !self.keyed { return next_random(&mut self.rng) }
        let account = self.account_of(a);
        let k = self.drawn.entry((account, purpose)).or_insert(0);
        *k += 1;
        let mut key = self.rng ^ (account as u64 + 1).wrapping_mul(0x9e37_79b9_7f4a_7c15) ^ purpose.wrapping_mul(0xd1b5_4a32_d192_ed03) ^ k.wrapping_mul(0x94d0_49bb_1331_11eb);
        next_random(&mut key)
    }

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
        Some(table.max_expeditions(level)? + self.rules[a].expedition.as_ref()?.class_slots + self.hired(a).map(|o| o.expedition_slots).sum::<usize>())
    }

    /// W15: the officers account a has working at its clock; the bundle stands for all of them (`Officer::isOfficerActive`).
    pub fn hired(&self, a: usize) -> impl Iterator<Item = &crate::fleet::Officer> {
        let (account, now) = (self.account[a], self.games[a].t);
        // An economy-only world has no fleet settings, so no officers.
        let officers = self.rules.get(account).map(|r| &r.settings.officers);
        let active = move |key: &str| self.officers[account].get(key).is_some_and(|&until| until > now);
        let bundle = officers.and_then(|o| o.iter().find(|(key, o)| o.bundle && active(key)).map(|(key, _)| key));
        officers.into_iter().flatten().filter(move |(key, _)| match bundle { Some(b) => *key == b, None => active(key) }).map(|(_, o)| o)
    }

    /// W15: account a buys `key` for `days` with its dark matter (`OfficerService::purchase`); a running officer is
    /// extended from its end.
    pub fn hire(&mut self, a: usize, key: &str, days: i64) -> Result<(), &'static str> {
        let account = self.account[a];
        let now = self.games[a].t;
        let cost = *self.rules[account].settings.officers.get(key).ok_or("no such officer")?.costs.get(&days.to_string()).ok_or("no such duration")?;
        if self.dark_matter[account] < cost { return Err("not enough dark matter") }
        self.dark_matter[account] -= cost;
        let until = self.officers[account].entry(key.to_string()).or_insert(now);
        *until = until.max(now) + days as f64 * 86_400.0;
        self.officer_log.push((a, now, key.to_string(), days));
        Ok(())
    }

    /// Account a's fleet slots as `PlayerService::getFleetSlotsMax` counts them (no officers): the table's at its research
    /// level plus its class's own. `None` without the table.
    pub fn fleet_slots(&self, cat: &Catalogue, a: usize) -> Option<usize> {
        let table = self.colonisation.as_ref()?;
        let level = self.research(cat, a).get(&table.fleet_research?).copied().unwrap_or(0) as usize;
        Some(*table.max_fleets.get(level.min(table.max_fleets.len().checked_sub(1)?))? + self.rules[a].combat.rules.class_fleet_slots + self.hired(a).map(|o| o.fleet_slots).sum::<usize>())
    }

    /// Brings planet p's stock to second t before a fleet touches it; nothing of p's own is due before t. The yard's
    /// finished units wait for the planet's next load (`settle_yard`): the host hands them over only there.
    pub(crate) fn catch_up(&mut self, cat: &Catalogue, p: usize, t: f64) {
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

    /// L5: account a, while it decides, sends ships to hold at a mate's planet `target` for `hold_hours` (AcsDefendMission):
    /// they fight beside the planet's own in every battle there while they hold, then fly home with what survived.
    #[allow(clippy::too_many_arguments)]
    pub fn defend(&mut self, cat: &Catalogue, a: usize, target: usize, ships: &[(u32, i64)], cargo: [f64; 3], speed: f64, hold_hours: i64) -> Result<(), &'static str> {
        self.launch(cat, a, Some(target), self.coords[target], Mission::AcsDefend, ships, cargo, speed, hold_hours)
    }

    /// L5: account a, while it decides, sends ships to join the attack `f` is flying (FleetController's union dispatch,
    /// FleetUnionService::joinUnion): at most 16 fleets of 5 players, landing no later than 30% of the time the union
    /// still has to fly past its arrival, or refused before it leaves. A fleet of someone neither the union's owner nor a
    /// mate leaves and attacks alone, as the host's does. The union lands together at its latest member's second.
    pub fn join(&mut self, cat: &Catalogue, a: usize, f: usize, ships: &[(u32, i64)], cargo: [f64; 3], speed: f64) -> Result<(), &'static str> {
        let now = self.games[a].t;
        let lead = self.fleets.get(f).ok_or("no such fleet")?;
        if lead.mission != Mission::Attack || lead.returning || lead.moon || now >= lead.arrive { return Err("no attack to join") }
        let (key, target, lands) = (lead.union.unwrap_or(lead.id), lead.target, lead.arrive);
        let members: Vec<usize> = (0..self.fleets.len()).filter(|&g| g == f || (self.fleets[g].union == Some(key) && !self.fleets[g].returning)).collect();
        let players: HashSet<usize> = members.iter().map(|&g| self.account[self.fleets[g].owner]).collect();
        if members.len() >= UNION_FLEETS { return Err("the union is full") }
        if !players.contains(&self.account[a]) && players.len() >= UNION_PLAYERS { return Err("the union has its players") }
        let rules = Arc::clone(&self.rules[a]);
        let research = self.research(cat, a);
        let distance = fleet::distance(&rules.settings, self.coords[a], self.coords[target], &|_, _| SystemState::Active);
        let flight = fleet::flight_seconds(&rules.settings, Mission::Attack as u32, distance, rules.fleet.slowest_speed(ships, &research), speed) as f64;
        if now + flight > lands + ((lands - now).max(0.0) * UNION_DELAY).floor() { return Err("too slow for the union") }
        self.launch(cat, a, Some(target), self.coords[target], Mission::Attack, ships, cargo, speed, 0)?;
        let first = *members.iter().min_by_key(|&&g| self.fleets[g].id).expect("the lead");
        let owner = self.fleets[first].owner;
        if self.account[owner] != self.account[a] && !self.mates(owner, a, now) { return Err("not a buddy or alliance member") }
        let joined = self.fleets.len() - 1;
        let arrive = self.fleets[joined].arrive.max(lands);
        for g in members.into_iter().chain([joined]) {
            self.fleets[g].union = Some(key);
            self.fleets[g].arrive = arrive;
        }
        Ok(())
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
        if own && matches!(mission, Mission::Attack | Mission::Espionage | Mission::MoonDestroy | Mission::AcsDefend) { return Err("own planet") }
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
            Mission::AcsDefend if !rules.settings.alliance_combat_system => return Err("ACS is disabled"),
            Mission::AcsDefend if !self.mates(a, target.unwrap(), now) => return Err("not a buddy or alliance member"),
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
        self.fleets.push(Fleet { owner: a, target: target.unwrap_or(a), to, mission, ships: sent, cargo, fuel, arrive: now + flight, home: now + 2.0 * flight + holding, returning: false, departed: now, holding, priority: 0, moon: mission == Mission::MoonDestroy, id: self.launched, union: None });
        self.log_launch(speed);
        self.next[a] = cat.peek(&mut self.games[a]);
        Ok(())
    }

    /// A fleet reaches its target (the mission acts and the fleet turns home) or comes home (ships and cargo land). An
    /// ally wiped out holding at the target leaves the list afterwards, so the indices the event works on stay put.
    fn fleet_event(&mut self, cat: &Catalogue, f: usize) {
        self.fleet_acts(cat, f);
        self.fleets.retain(|fleet| !fleet.ships.is_empty());
    }

    fn fleet_acts(&mut self, cat: &Catalogue, f: usize) {
        // A union fights once, when its first fleet lands (AttackMission: only slot 1 processes the battle).
        let f = self.union_members(f).into_iter().chain([f]).min_by_key(|&g| self.fleets[g].id).filter(|_| !self.fleets[f].returning).unwrap_or(f);
        let at = self.fleets[f].next();
        self.dice = (self.draw(self.fleets[f].owner, 1) >> 32) as u32;
        self.stream = expedition::Mt19937::new(self.dice);
        if self.fleets[f].returning {
            let fleet = self.fleets.swap_remove(f);
            self.land(cat, fleet.owner, at, &fleet.ships, fleet.cargo);
            if let Some(ships) = self.carried.remove(&fleet.id) { self.add_wreck(self.ground(fleet.owner), ships, at) }
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
            Mission::AcsDefend => {
                // AcsDefendMission::processArrival: the hold is over and what survived flies home with its cargo and half
                // the fuel (`getResources`, an integer column on the return).
                let refund = (self.fleets[f].fuel / 2.0).floor();
                self.games[owner].planet.books.fuel -= weighted([0.0, 0.0, refund]);
                self.fleets[f].cargo[2] += refund;
            }
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
        let mut event = Event { kind, time, mission: fleet.mission, owner: fleet.owner, target: fleet.target, departed: fleet.departed, ships_lost: Vec::new(), target_lost: Vec::new(), present: Vec::new(), gained: [0.0; 3], salvaged: [0.0; 3], debris: [0; 3], moon_chance: 0, moon_created: false, outcome, seed: self.dice, fleet: fleet.id };
        fill(&mut event);
        if event.mission == Mission::Attack { self.last_attacked.insert(event.target, event.time); }
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
        fleet.union = None;
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
            let drawn = low + (self.draw(owner, 2) % (high - low + 1) as u64) as u32;
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
            None => Some(sites[(self.draw(capital, 3) % sites.len() as u64) as usize]),
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
        let holding = self.holding_at(f);
        let allies: Vec<combat::Units> = holding.iter().map(|&g| self.fleets[g].ships.iter().copied().collect()).collect();
        let members = if plunder { self.union_members(f) } else { Vec::new() };
        let joined: Vec<(Arc<Rules>, HashMap<u32, u32>)> = members.iter().map(|&g| (Arc::clone(&self.rules[self.fleets[g].owner]), self.research(cat, self.fleets[g].owner))).collect();
        let partners: Vec<combat::Partner> = members.iter().zip(&joined).map(|(&g, (rules, research))| {
            let fleet = &self.fleets[g];
            combat::Partner { combat: &rules.combat, ships: &fleet.ships, research, cargo: [fleet.cargo[0], fleet.cargo[1], fleet.cargo[2] + fleet.fuel / 2.0] }
        }).collect();
        let battle = Battle {
            partners: &partners,
            attacker: &self.fleets[f].ships,
            attacker_research: &attacker_research,
            attacker_cargo: cargo,
            defender,
            defender_research: &defender_research,
            allies: &allies,
            defender_stock: self.games[target].planet.res,
            defender_engineer: false,
            moon_exists: self.moons[self.ground(target)],
            seed,
        };
        let fought = combat::defender_units(&defender_rules.combat, &battle);
        let outcome = match self.recorded.take() {
            Some(fight) => combat::settle_in(&attacker_rules.combat, &defender_rules.combat, &battle, fight.engine, fight.repaired, fight.moon_roll, plunder),
            None => combat::fight_in(&attacker_rules.combat, &defender_rules.combat, &battle, plunder),
        };
        let allied = |id: u32| outcome.allies_left.iter().map(|left| left.get(&id).copied().unwrap_or(0)).sum::<i64>();
        for (u, unit) in defender_rules.units.units.iter().enumerate() {
            let id = unit.id as u32;
            if defender.iter().any(|&(own, n)| own == id && n > 0) && fought.contains_key(&id) {
                self.yards[target].counts[u] = (outcome.defender_left.get(&id).copied().unwrap_or(0) - allied(id) + outcome.repaired.get(&id).copied().unwrap_or(0)) as u64;
            }
        }
        for (&g, left) in holding.iter().zip(&outcome.allies_left) {
            self.ally_fought(cat, g, left);
        }
        let ground = self.ground(target);
        for r in 0..3 { self.debris[ground][r] += outcome.debris[r] as f64 }
        self.fields[ground] = true;
        let at = self.fleets[f].next();
        if plunder {
            // AttackMission: the defender's lost ships leave a wreck at its planet; a General's own ride home with it.
            if let Some(ships) = self.wreckage(cat, ground, &defender_rules, &fought, &outcome.defender_lost) { self.add_wreck(ground, ships, at) }
            let start: combat::Units = self.fleets[f].ships.iter().copied().collect();
            let lost: combat::Units = start.iter().map(|(&id, &n)| (id, n - outcome.attacker_left.get(&id).copied().unwrap_or(0))).filter(|s| s.1 > 0).collect();
            let home = self.ground(owner);
            if attacker_rules.settings.wreck.as_ref().is_some_and(|w| w.carries_wreck_home) && !outcome.attacker_left.is_empty() {
                if let Some(ships) = self.wreckage(cat, home, &attacker_rules, &start, &lost).filter(|s| !s.is_empty()) { self.carried.insert(self.fleets[f].id, ships); }
            }
        }
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
            e.salvaged = outcome.salvage;
            e.debris = outcome.debris;
            e.moon_chance = outcome.moon_chance;
            e.moon_created = outcome.moon_created;
        });
        outcome
    }

    /// L5: the other fleets of fleet f's union still flying at its target, in the order they joined.
    fn union_members(&self, f: usize) -> Vec<usize> {
        let Some(key) = self.fleets[f].union else { return Vec::new() };
        let mut members: Vec<usize> = (0..self.fleets.len()).filter(|&g| g != f && self.fleets[g].union == Some(key) && !self.fleets[g].returning && self.fleets[g].mission == Mission::Attack).collect();
        members.sort_by_key(|&g| self.fleets[g].id);
        members
    }

    /// The flight home a union's fleet takes after the fight: its own survivors' speed at 100% from its own planet
    /// (AttackMission's multi-fleet path), not the speed the union was flown at.
    fn natural_return(&self, cat: &Catalogue, g: usize) -> f64 {
        let fleet = &self.fleets[g];
        let rules = &self.rules[fleet.owner];
        let distance = fleet::distance(&rules.settings, self.coords[fleet.owner], self.coords[fleet.target], &|_, _| SystemState::Active);
        fleet::flight_seconds(&rules.settings, Mission::Attack as u32, distance, rules.fleet.slowest_speed(&fleet.ships, &self.research(cat, fleet.owner)), 10.0) as f64
    }

    /// L5: the ACS defend fleets holding at fleet f's target when it lands there (GameMission::collectDefendingFleets:
    /// arrived, and the hold not yet over).
    fn holding_at(&self, f: usize) -> Vec<usize> {
        let (target, moon, at) = (self.fleets[f].target, self.fleets[f].moon, self.fleets[f].arrive);
        (0..self.fleets.len()).filter(|&g| {
            let h = &self.fleets[g];
            h.mission == Mission::AcsDefend && h.target == target && h.moon == moon && !h.returning && h.arrive <= at && at < h.arrive + h.holding
        }).collect()
    }

    /// AttackMission: a holding ally keeps what survived and the share of its cargo its surviving capacity still holds;
    /// wiped out, it is gone with its cargo and nothing flies home.
    fn ally_fought(&mut self, cat: &Catalogue, g: usize, left: &combat::Units) {
        let owner = self.fleets[g].owner;
        let rules = Arc::clone(&self.rules[owner]);
        let research = self.research(cat, owner);
        let start: combat::Units = self.fleets[g].ships.iter().copied().collect();
        let before = rules.combat.capacity(&start, &research);
        let rate = if before > 0 { rules.combat.capacity(left, &research) as f64 / before as f64 } else { 0.0 };
        let held = weighted(self.fleets[g].cargo);
        self.fleets[g].cargo = self.fleets[g].cargo.map(|r| (r * rate).trunc().max(0.0));
        self.games[owner].planet.books.cargo_lost += held - weighted(self.fleets[g].cargo);
        self.fleets[g].ships = left.iter().map(|(&id, &n)| (id, n)).collect();
        if left.is_empty() {
            let fleet = self.fleets[g].clone();
            self.log(EventKind::Lost, fleet.arrive.max(self.games[fleet.target].t), &fleet, Outcome::Defeated, |_| {});
        }
    }

    /// A union's partner after the fight: its survivors and its cargo (the half fuel it carried in, the cargo that
    /// survived and its share of the plunder), home at its own speed; wiped out, it is gone with all it carried.
    fn partner_fought(&mut self, cat: &Catalogue, g: usize, left: &combat::Units, cargo: [i64; 3]) {
        let owner = self.fleets[g].owner;
        let refund = self.fleets[g].fuel / 2.0;
        let held = weighted(self.fleets[g].cargo) + weighted([0.0, 0.0, refund]);
        self.fleets[g].ships = left.iter().map(|(&id, &n)| (id, n)).collect();
        self.fleets[g].cargo = cargo.map(|r| r as f64);
        let held_now = weighted(self.fleets[g].cargo);
        let books = &mut self.games[owner].planet.books;
        books.fuel -= weighted([0.0, 0.0, refund]);
        if held_now >= held { books.loot_in += held_now - held } else { books.cargo_lost += held - held_now }
        self.fleets[g].returning = true;
        let fleet = self.fleets[g].clone();
        self.union_log.push((owner, fleet.arrive));
        if left.is_empty() {
            self.log(EventKind::Lost, fleet.arrive, &fleet, Outcome::Defeated, |_| {});
            return;
        }
        self.fleets[g].home = fleet.arrive + self.natural_return(cat, g);
        self.log(EventKind::Arrival, fleet.arrive, &fleet, Outcome::Done, |e| e.gained = cargo.map(|r| r as f64));
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
        let members = self.union_members(f);
        let outcome = self.fight(cat, f, &defender, cargo, true);
        for (i, &g) in members.iter().enumerate() {
            self.partner_fought(cat, g, &outcome.partners_left[i], outcome.partner_cargo[i]);
        }
        if !members.is_empty() {
            self.fleets[f].home = self.fleets[f].arrive + self.natural_return(cat, f);
        }
        for r in 0..3 {
            self.games[target].planet.res[r] = as_stored(self.games[target].planet.res[r] - outcome.loot[r] as f64);
            self.games[target].planet.res[r] = as_stored(self.games[target].planet.res[r] + outcome.salvage[r] as f64);
        }
        self.games[target].planet.settle();
        let ground = self.ground(target);
        if outcome.moon_created && !self.moons[ground] { self.raise_moon(cat, ground, outcome.debris.iter().sum(), self.fleets[f].arrive) }
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
    fn raise_moon(&mut self, cat: &Catalogue, target: usize, debris: i64, at: f64) {
        let x = self.stream.int(10, 20);
        let diameter = (((x as f64) + 3.0 * debris as f64 / 100_000.0).sqrt() * 1000.0).floor() as i64;
        self.add_moon(cat, target, diameter, at);
    }

    /// EspionageMission: the counter-espionage chance and roll, a fight against the target's ships alone (its defence
    /// stands aside, no loot, no moon), then a report as deep as the probes sent and the research gap allow. The probes
    /// that survive fly home with the mission's resources, half the fuel included.
    fn espionage(&mut self, cat: &Catalogue, f: usize) {
        let (owner, target) = (self.fleets[f].owner, self.fleets[f].target);
        let missions = self.rules[owner].combat.missions.clone();
        // The officers' extra levels count on both sides (EspionageMission::processArrival).
        let level = |w: &World, a: usize| missions.espionage_research.map_or(0, |id| w.research(cat, a).get(&id).copied().unwrap_or(0)) as i64 + w.hired(a).map(|o| o.espionage).sum::<i64>();
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
        let mut cat = Catalogue::load(&format!("{DATA}/units-parity/econ-u1-merged.json")).ok()?;
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

    /// M3: a moon raised over a planet is a slot of its account at the planet's coordinates and no colony: ships deploy
    /// to it and stay there, it takes no planet's object, and a fight over it leaves its debris on the planet's field.
    #[test]
    fn a_moon_is_a_slot_of_its_account_that_fleets_fly_to() {
        let Some((cat, mut w, _)) = pair(240.0) else { return eprintln!("no u1 catalogues; skipped") };
        let moon = w.add_moon(&cat, 0, 8000, 0.0);
        assert_eq!((w.account_of(moon), w.moon_of[0], w.ground(moon), w.coords[moon]), (0, Some(moon), 0, w.coords[0]));
        assert!(!w.planets_of(0).contains(&moon), "a moon takes no planet slot");
        let mine = (0..cat.objects.len()).find(|&k| cat.objects[k].queue == 0 && !cat.objects[k].on_moon).expect("a planet's own object");
        w.games[moon].planet.res = [1e9; 3];
        assert!(!w.legal(&cat, moon, mine), "a mine stood on a moon");
        let rules = Arc::clone(&w.rules[0]);
        let ship = (0..rules.units.units.len()).find(|&u| rules.units.kind.get(u) == Some(&crate::units::UnitKind::MilitaryShip))
            .map(|u| rules.units.units[u].id as u32).expect("a warship");
        give(&mut w, 0, ship, 5);
        w.games[0].planet.res = [1e6; 3];
        w.dispatch(&cat, 0, moon, Mission::Deploy, &[(ship, 5)], [0.0; 3], 10.0).expect("a deploy to the account's own moon");
        idle(&cat, &mut w);
        let u = w.rules[moon].units.index(ship as i64).unwrap();
        assert_eq!((w.yards[moon].counts[u], w.yards[0].counts[u]), (5, 0), "the ships stand on the moon");
    }

    /// L3/L4: a phalanx on a moon reads the fleets flying to a planet in range for its deuterium, and a jump gate
    /// moves ships between an account's two moons at once and then rests both gates by the lower level.
    #[test]
    fn a_moon_reads_fleets_with_its_phalanx_and_jumps_ships_through_its_gate() {
        let Some((cat, mut w, _)) = pair(240.0) else { return eprintln!("no u1 catalogues; skipped") };
        let gear = w.rules[0].settings.moon_gear.clone().expect("fleet settings v3");
        let at = |id: i64| cat.objects.iter().position(|o| o.id == id).unwrap();
        let moon = w.add_moon(&cat, 0, 8000, 0.0);
        let colony = w.add_planet(&cat, 0, 0, Coord { galaxy: 1, system: 11, position: 6 }, 0.0);
        let other = w.add_moon(&cat, colony, 8000, 0.0);
        assert_eq!(w.phalanx(&cat, moon, 1), Err("out of phalanx range"), "no phalanx built");
        w.games[moon].planet.levels[at(gear.phalanx_object)] = 3;
        w.games[moon].planet.res = [0.0, 0.0, 6000.0];
        let ship = 202;
        give(&mut w, 1, ship, 3);
        w.games[1].planet.res = [1e6; 3];
        w.dispatch(&cat, 1, 0, Mission::Transport, &[(ship, 3)], [0.0; 3], 10.0).unwrap();
        assert_eq!(w.phalanx(&cat, moon, 0), Ok(vec![0]), "the fleet flying to the planet");
        assert_eq!(w.games[moon].planet.res[2] as i64, 6000 - gear.phalanx_cost as i64);
        assert_eq!(w.phalanx(&cat, moon, 0), Err("not enough deuterium"));

        let warship = *gear.gate_ships.iter().find(|&&id| id != ship).expect("a ship the gate carries");
        give(&mut w, moon, warship, 7);
        assert_eq!(w.jump(&cat, moon, other, &[(warship, 7)]), Err("no jump gate"));
        w.games[moon].planet.levels[at(gear.gate_object)] = 2;
        w.games[other].planet.levels[at(gear.gate_object)] = 1;
        w.jump(&cat, moon, other, &[(warship, 7)]).expect("a jump");
        let u = w.rules[moon].units.index(warship as i64).unwrap();
        assert_eq!((w.yards[moon].counts[u], w.yards[other].counts[u]), (0, 7));
        let now = w.games[0].t;
        assert_eq!((w.gate_ready[moon], w.gate_ready[other]), (now + gear.gate_cooldown[1], now + gear.gate_cooldown[1]), "the lower level rests both");
        assert_eq!(w.jump(&cat, other, moon, &[(warship, 7)]), Err("jump gate resting"));
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

    /// L6: a halved yard order hands over the units half its time held and moves up by that half; the second halving
    /// hands over the rest.
    #[test]
    fn dark_matter_halves_a_yard_order_and_then_completes_it() {
        let Some((cat, mut w, _)) = pair(240.0) else { return eprintln!("no u1 catalogues; skipped") };
        let rules = Arc::clone(&w.rules[0]);
        let u = rules.units.index(202).expect("a small cargo");
        for &(k, level) in &rules.units.units[u].requires {
            if let Some(k) = k { w.games[0].planet.levels[k] = w.games[0].planet.levels[k].max(level) }
        }
        w.games[0].planet.res = [1e8; 3];
        cat.refresh(&mut w.games[0].planet);
        w.dark_matter[0] = 1_000_000;
        let a = w.next_decision(&cat).expect("a decision");
        assert_eq!(a, 0);
        assert_eq!(w.order_units(&cat, 0, u, 400), 400);
        let o = w.yards[0].orders[0].clone();
        let now = w.games[0].t as i64;
        let cost = w.halve(&cat, 0, UNIT_QUEUE).expect("a halving");
        let cut = ((o.end - o.start) / 2).min(o.end - now);
        assert_eq!(w.yards[0].counts[u] as i64, cut / o.each, "the units the cut held are there at once");
        assert_eq!((w.yards[0].orders[0].end, w.dark_matter[0]), (o.end - cut, 1_000_000 - cost));
        w.halve(&cat, 0, UNIT_QUEUE).expect("the completion");
        assert!(w.yards[0].orders.is_empty() && w.yards[0].counts[u] == 400, "the second halving hands over the rest");
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

    /// L6: dark matter halves a running research by half its own time, priced on the time left, and a second use
    /// completes it; the level lands at the new end.
    #[test]
    fn dark_matter_halves_a_research_and_then_completes_it() {
        let Some((cat, mut w, _)) = pair(240.0) else { return eprintln!("no u1 catalogues; skipped") };
        let halving = w.rules[0].settings.halving.clone().expect("fleet settings v4");
        let lab = (0..cat.objects.len()).find(|&b| cat.objects[b].queue == 0 && cat.objects.iter().any(|r| r.queue == 1 && r.requires.contains(&(Some(b), 1)))).expect("a lab");
        w.games[0].planet.levels[lab] = 3;
        w.games[0].planet.res = [1e7; 3];
        cat.refresh(&mut w.games[0].planet);
        w.dark_matter[0] = 1_000_000;
        assert_eq!(w.halve(&cat, 0, 1), Err("nothing to halve"));
        let k = loop {
            let a = w.next_decision(&cat).expect("the capital researches");
            if a == 0 && w.games[0].queue == 1 {
                let k = (0..cat.objects.len()).filter(|&k| cat.objects[k].queue == 1 && w.legal(&cat, 0, k))
                    .max_by(|&x, &y| cat.build_seconds(&w.games[0].planet, x).total_cmp(&cat.build_seconds(&w.games[0].planet, y))).expect("a research");
                w.commit(&cat, 0, k);
                break k;
            }
            w.wait(&cat, a);
        };
        while !w.games[0].history.iter().any(|h| h.1 == k) {
            let a = w.next_decision(&cat).expect("the research starts");
            w.wait(&cat, a);
        }
        let (start, _, end, _) = *w.games[0].history.iter().rev().find(|h| h.1 == k).expect("the research started");
        let now = w.games[0].t;
        let level = w.games[0].planet.levels[k];
        let cost = w.halve(&cat, 0, 1).expect("a halving");
        let expected = (((end - now) / 1800.0 * halving.per_half_hour as f64).ceil() as i64).clamp(halving.min_cost, halving.max_cost["research"]);
        assert_eq!((cost, w.dark_matter[0]), (expected, 1_000_000 - expected));
        let halved = end - ((end - start) / 2.0).floor().min(halving.max_reduction["research"]);
        assert!(w.games[0].pending.contains(&(halved, k)), "{:?} vs {halved}", w.games[0].pending);
        w.halve(&cat, 0, 1).expect("the completion");
        assert!(w.games[0].pending.contains(&(now, k)), "the second use completes the research");
        let a = w.next_decision(&cat).expect("the capital decides again");
        assert_eq!((a, w.games[0].planet.levels[k]), (0, level + 1), "the level landed before anything else");
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

    /// With idle logins on, an account with no queue to decide still logs in, as a decision
    /// that offers nothing to build; without them it never decides.
    #[test]
    fn an_account_with_nothing_to_build_still_logs_in() {
        let Some((cat, mut with, mut without)) = pair(6.0) else { return eprintln!("no u1 catalogues; skipped") };
        for w in [&mut with, &mut without] {
            // Both queues busy past the horizon: the economy has nothing to decide for hours.
            w.games[0].free = [w.games[0].t + 1e6; 2];
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
        assert_eq!(quiet_logins, 0, "an account with both queues busy decided without idle logins");
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
            #[serde(default)] perturbation: Option<String>,
            /// ACS defend: the holding fleet's flight home.
            #[serde(default)] defend_return: Option<Again>,
            /// ACS attack: each fleet's flight home and landing, the first attack's then the partner's.
            #[serde(default)] returns: Vec<Option<Homeward>>,
            /// Wreck: the owner's moves on the field after the raid.
            #[serde(default)] steps: Vec<WreckStep>,
            /// Planet move: what is asked and what stands there.
            #[serde(default)] relocation: Option<RelocationInput>,
        }

        #[derive(Deserialize)]
        struct WreckStep { at: f64, action: String, error: Option<String>, planet_after: Option<State>, wreck: Option<HostWreck> }

        #[derive(Deserialize)]
        struct HostWreck {
            status: String,
            #[serde(default)] ships: Vec<WreckShip>,
            expires_at: Option<f64>,
            repair_started_at: Option<f64>,
            repair_completed_at: Option<f64>,
        }

        #[derive(Deserialize)]
        struct WreckShip { machine_name: String, quantity: i64 }

        #[derive(Deserialize)]
        struct Homeward { flight: Again, home: Landing }

        #[derive(Deserialize)]
        struct Landing { time: f64, origin_after: Option<State> }

        #[derive(Deserialize)]
        struct Join {
            at: f64,
            origin: PlanetState,
            player: PlayerState,
            units: HashMap<String, i64>,
            cargo: [f64; 3],
            speed: f64,
            accepted: bool,
            joined: bool,
            error: Option<String>,
            arrival: Option<f64>,
            fuel: Option<f64>,
            #[serde(default)] origin_after: Option<State>,
        }

        #[derive(Deserialize)]
        struct Landed { time: f64, battle: Option<BattleAnswer> }

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
            /// ACS defend: the third account's attack on the held planet.
            #[serde(default)] raid: Option<RaidInput>,
            /// ACS attack: the fleet sent to join the union.
            #[serde(default)] join: Option<Join>,
            /// Wreck: the space dock levels of the target and the raider.
            #[serde(default)] dock: Option<usize>,
            #[serde(default)] raider_dock: Option<usize>,
        }

        #[derive(Deserialize)]
        struct RelocationInput {
            planet: PlanetState,
            moon: Option<PlanetState>,
            #[serde(default)] moon_gate: u32,
            player: PlayerState,
            dark_matter: i64,
            to: [i64; 3],
            #[serde(default)] raid: Option<RelocationRaid>,
        }

        #[derive(Deserialize)]
        struct RelocationRaid { origin: PlanetState, player: PlayerState, units: HashMap<String, i64>, departure: f64, arrival: f64 }

        #[derive(Deserialize)]
        struct Transfer { arrival: f64, #[serde(default)] units: Option<HashMap<String, i64>> }

        #[derive(Deserialize)]
        struct RaidInput { origin: PlanetState, player: PlayerState, units: HashMap<String, i64>, speed: f64 }

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
        struct Dispatch { accepted: bool, departure: Option<f64>, arrival: Option<f64>, fuel: Option<f64>, origin_after: Option<State>, #[serde(default)] holding_seconds: f64 }

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
            #[serde(default)] wreck_after: Option<HostWreck>,
            /// Planet move: the slot, dark matter, moon and ships flying over afterwards, and a raid's way home.
            #[serde(default)] coordinates: Option<[i64; 3]>,
            #[serde(default)] dark_matter: Option<i64>,
            #[serde(default)] moon_after: Option<State>,
            #[serde(default)] transfers: Vec<Transfer>,
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
            /// ACS defend: the holding fleet after the fight (None once wiped out or gone home) and the raid's flight home.
            #[serde(default)] defend_after: Option<Again>,
            #[serde(default)] raid_return: Option<Again>,
            /// ACS attack: each landing second and its fight.
            #[serde(default)] arrivals: Vec<Landed>,
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
            /// An ACS defend case's attack is flat as well, with the repairs and the moon roll beside it.
            #[serde(default)] repaired: serde_json::Value,
            #[serde(default)] moon_chance: i64,
            #[serde(default)] moon_created: bool,
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
        struct Back { time: f64, origin_after: Option<State>, #[serde(default)] wreck_field: Option<HostWreck> }

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
                "acs_defend" => ("acs-cases-u1.json", "units-parity/econ-u1.json"),
                "acs_attack" => ("acs-attack-cases-u1.json", "units-parity/econ-u1.json"),
                "wreck" => ("wreck-cases-u1.json", "units-parity/econ-u1.json"),
                "moon_attack" => ("moon-attack-cases-u1.json", "units-parity/econ-u1.json"),
                "relocate" => ("relocation-cases-u1.json", "units-parity/econ-u1.json"),
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
                    allies_left: Vec::new(), partners_left: Vec::new(),
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
                "acs_defend" => return play_acs_defend(cat, case, index),
                "acs_attack" => return play_acs_attack(cat, case, index),
                "wreck" => return play_wreck(cat, case, index),
                "relocate" => return play_relocate(cat, case, index),
                "expedition" => return play_expedition(cat, case, index, setup),
                _ => {}
            }
            let (missile, moon_strike) = (case.mission == "missile", case.mission == "moon_destruction");
            // A missile may also go for a moon: its defences are the moon's, and the planet's interceptors help it.
            let moon_raid = case.mission == "moon_attack";
            let moon = moon_strike || moon_raid || (missile && case.input.target_type == 3);
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
                _ if recycling || moon_strike || moon_raid => (0..cat.planets.len()).find(|&c| c != origin_config)?,
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
                let moon = world.add_moon(cat, 1, diameter, world.games[1].t);
                world.yards[moon].counts = counts;
                world.games[moon].planet.res = hold.resources;
                world.games[moon].planet.settle();
            }
            let mission = match case.mission.as_str() {
                "moon_destruction" => Mission::MoonDestroy,
                "attack" | "moon_attack" => Mission::Attack,
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
                false => world.dispatch(cat, 0, if moon_raid { world.moon_of[1]? } else { 1 }, mission, &units_of(&case.input.units), case.input.cargo, case.input.speed),
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
                        engine: combat::EngineResult { rounds: battle.rounds, attacker_left: counted(&battle.attacker_left), defender_left: counted(&battle.defender_left), allies_left: Vec::new(), partners_left: Vec::new() },
                        repaired: if moon_raid { counted(&battle.repaired) } else { combat::Units::new() },
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
                // A fight on a moon leaves its wreck at the planet's coordinates (AttackMission).
                if moon_raid { wreck(&world, 1, arrival.wreck_after.as_ref(), index, "after the moon raid") }
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

        /// An ACS attack case: account 0's attack is made a union and account 1 sends a fleet to join it at `join.at`;
        /// account 2's planet is the target. The join's refusals, each landing with the host's survivors handed over,
        /// the planet and the field afterwards, and each fleet's flight home and landing.
        fn play_acs_attack(cat: &Catalogue, case: &Case, index: usize) -> Option<bool> {
            let (join, target, defender) = (case.input.join.as_ref()?, case.input.target.as_ref()?, case.input.target_player.as_ref()?);
            let configs = [config_of(cat, case.input.origin.id)?, config_of(cat, join.origin.id)?, config_of(cat, target.id)?];
            let players = [&case.input.origin_player, &join.player, defender];
            let rules = players.iter().map(|p| rules_for(cat, p.class)).collect::<Option<Vec<_>>>()?;
            let mut world = World::new(cat, &configs, 1e6, 0.0, 1);
            world.arm(rules, vec![coords(&case.input.origin), coords(&join.origin), coords(target)]);
            if case.perturbation.as_deref() != Some("not_mates") {
                world.alliance_at[0] = Some(0.0);
                world.alliance_at[1] = Some(0.0);
            }
            let departure = case.dispatch.departure?;
            for (a, ((player, planet), t)) in players.iter().zip([&case.input.origin, &join.origin, target]).zip([departure, join.at, departure]).enumerate() {
                for (id, &level) in &player.research {
                    let k = cat.objects.iter().position(|o| o.id == id.parse::<i64>().unwrap() && o.queue == 1).expect("a research in the catalogue");
                    world.games[a].planet.levels[k] = level;
                }
                let mut held = planet.ships.clone();
                held.extend(planet.defence.clone());
                for (id, amount) in units_of(&held) {
                    let u = world.rules[a].units.index(id as i64).expect("a unit in the catalogue");
                    world.yards[a].counts[u] = amount as u64;
                }
                world.games[a].planet.res = planet.resources;
                world.games[a].planet.settle();
                world.games[a].t = t;
                world.games[a].login_times = vec![t];
                cat.refresh(&mut world.games[a].planet);
            }
            world.dispatch(cat, 0, 2, Mission::Attack, &units_of(&case.input.units), case.input.cargo, case.input.speed).unwrap_or_else(|e| panic!("case {index}: the world refused the first attack: {e}"));
            assert_eq!(world.fleets[0].arrive, case.dispatch.arrival?, "case {index}: the first attack's arrival");
            stock(case.dispatch.origin_after.as_ref(), &world, 0, index, "after dispatch");
            let before = world.games[1].planet.res;
            let joined = world.join(cat, 1, 0, &units_of(&join.units), join.cargo, join.speed);
            match (join.accepted, join.joined, &joined) {
                (true, true, Ok(())) => {}
                (true, false, Err(_)) => assert!(world.fleets.len() == 2 && world.fleets[1].union.is_none(), "case {index}: a non-mate's fleet attacks alone"),
                (false, _, Err(_)) => assert_eq!(world.fleets.len(), 1, "case {index}: a refused join sends nothing"),
                _ => panic!("case {index}: host accepted {} joined {} ({:?}), world {joined:?}", join.accepted, join.joined, join.error),
            }
            if join.accepted {
                let fuel = before[2] - join.cargo[2] - world.games[1].planet.res[2];
                assert!((fuel - join.fuel.unwrap_or(0.0)).abs() < 1e-6, "case {index}: the partner's fuel {fuel} host {:?}", join.fuel);
                stock(join.origin_after.as_ref(), &world, 1, index, "after the partner left");
                assert_eq!(Some(world.fleets[1].arrive), join.arrival, "case {index}: the partner's arrival");
            }
            let arrival = case.arrival.as_ref()?;
            // Two lone fleets landing in one second leave two reports the case keeps only one of.
            if join.accepted && !join.joined && arrival.arrivals.len() < 2 { return None }
            // Landings and homecomings in time order: a lone partner can land and be home before the attack lands.
            let mut events: Vec<(f64, usize, usize)> = arrival.arrivals.iter().enumerate().map(|(i, l)| (l.time, 0, i)).collect();
            events.extend(case.returns.iter().enumerate().filter_map(|(a, r)| r.as_ref().map(|r| (r.home.time, 1, a))));
            events.sort_by(|x, y| x.0.total_cmp(&y.0).then(x.1.cmp(&y.1)));
            for (time, kind, i) in events {
                if kind == 1 {
                    world.catch_up(cat, i, time);
                    world.games[i].planet.settle();
                    run_until(cat, &mut world, time);
                    stock(case.returns[i].as_ref().and_then(|r| r.home.origin_after.as_ref()), &world, i, index, "back home");
                    continue;
                }
                if let Some(battle) = &arrival.arrivals[i].battle {
                    let partner = case.returns.get(1).and_then(|r| r.as_ref()).and_then(|r| r.flight.units.clone()).unwrap_or_default();
                    world.recorded = Some(combat::Recorded {
                        engine: combat::EngineResult {
                            rounds: battle.rounds,
                            attacker_left: counted(&battle.attacker_left),
                            defender_left: counted(&battle.defender_left),
                            allies_left: Vec::new(),
                            partners_left: if join.joined { vec![units_of(&partner).into_iter().collect()] } else { Vec::new() },
                        },
                        repaired: counted(&battle.repaired),
                        moon_roll: if battle.moon_created { battle.moon_chance } else { battle.moon_chance + 1 },
                    });
                }
                world.catch_up(cat, 2, time);
                world.games[2].planet.settle();
                run_until(cat, &mut world, time);
                for (a, back) in case.returns.iter().enumerate() {
                    let fleet = world.fleets.iter().find(|f| f.owner == a);
                    match back {
                        None => assert!(fleet.is_none(), "case {index}: fleet {a} was lost at the host and flies home here"),
                        Some(back) if back.flight.departure == time => {
                            let f = fleet.unwrap_or_else(|| panic!("case {index}: fleet {a} flies home at the host and is lost here"));
                            assert_eq!(f.home, back.flight.arrival, "case {index}: fleet {a}'s landing");
                            assert_eq!(Some(f.cargo.map(|r| r as i64)), back.flight.cargo, "case {index}: fleet {a}'s cargo home");
                        }
                        Some(_) => {}
                    }
                }
                if i + 1 == arrival.arrivals.len() {
                    stock(arrival.target_after.as_ref(), &world, 2, index, "after the attack");
                    if let Some(debris) = arrival.debris_after {
                        assert_eq!(world.debris[2].map(|r| r as i64), debris, "case {index}: debris after the attack");
                    }
                }
            }
            Some(true)
        }

        /// A wreck case: account 0 raids account 1's planet (both with a drawn space dock); the wreck the fight leaves, a
        /// General's wreck landing at home, then the owner's repair start, collection and the hourly cleanup at the
        /// host's seconds, each followed by the planet and its first wreck field.
        fn play_wreck(cat: &Catalogue, case: &Case, index: usize) -> Option<bool> {
            // A second raid during repairs needs the host's second fight, which the case does not keep.
            if case.steps.iter().any(|s| s.action == "raid") { return None }
            let (target, defender) = (case.input.target.as_ref()?, case.input.target_player.as_ref()?);
            let configs = [config_of(cat, case.input.origin.id)?, config_of(cat, target.id)?];
            let players = [&case.input.origin_player, defender];
            let rules = players.iter().map(|p| rules_for(cat, p.class)).collect::<Option<Vec<_>>>()?;
            let dock = rules[1].settings.wreck.as_ref()?.dock_object;
            let mut world = World::new(cat, &configs, 1e6, 0.0, 1);
            world.arm(rules, vec![coords(&case.input.origin), coords(target)]);
            let departure = case.dispatch.departure?;
            let k = cat.objects.iter().position(|o| o.id == dock).expect("a space dock in the catalogue");
            for (a, ((player, planet), level)) in players.iter().zip([&case.input.origin, target]).zip([case.input.raider_dock, case.input.dock]).enumerate() {
                for (id, &level) in &player.research {
                    let r = cat.objects.iter().position(|o| o.id == id.parse::<i64>().unwrap() && o.queue == 1).expect("a research in the catalogue");
                    world.games[a].planet.levels[r] = level;
                }
                world.games[a].planet.levels[k] = level? as u32;
                let mut held = planet.ships.clone();
                held.extend(planet.defence.clone());
                for (id, amount) in units_of(&held) {
                    let u = world.rules[a].units.index(id as i64).expect("a unit in the catalogue");
                    world.yards[a].counts[u] = amount as u64;
                }
                world.games[a].planet.res = planet.resources;
                world.games[a].planet.settle();
                world.games[a].t = departure;
                world.games[a].login_times = vec![departure];
                cat.refresh(&mut world.games[a].planet);
            }
            world.dispatch(cat, 0, 1, Mission::Attack, &units_of(&case.input.units), case.input.cargo, case.input.speed).unwrap_or_else(|e| panic!("case {index}: the world refused the raid: {e}"));
            let arrival = case.arrival.as_ref()?;
            if let Some(battle) = &arrival.battle {
                world.recorded = Some(combat::Recorded {
                    engine: combat::EngineResult { rounds: battle.rounds, attacker_left: counted(&battle.attacker_left), defender_left: counted(&battle.defender_left), allies_left: Vec::new(), partners_left: Vec::new() },
                    repaired: counted(&battle.repaired),
                    moon_roll: if battle.moon_created { battle.moon_chance } else { battle.moon_chance + 1 },
                });
            }
            world.catch_up(cat, 1, arrival.time);
            world.games[1].planet.settle();
            run_until(cat, &mut world, arrival.time);
            stock(arrival.target_after.as_ref(), &world, 1, index, "after the raid");
            wreck(&world, 1, arrival.wreck_after.as_ref(), index, "after the raid");
            // The raider's landing and the owner's moves in time order: each moves the clocks on.
            let mut events: Vec<(f64, Option<&WreckStep>)> = case.steps.iter().map(|s| (s.at, Some(s))).collect();
            if let Some(back) = &case.back { events.push((back.time, None)) }
            events.sort_by(|x, y| x.0.total_cmp(&y.0));
            for (at, step) in events {
                let Some(step) = step else {
                    let back = case.back.as_ref()?;
                    world.catch_up(cat, 0, at);
                    world.games[0].planet.settle();
                    run_until(cat, &mut world, at);
                    stock(back.origin_after.as_ref(), &world, 0, index, "back home");
                    wreck(&world, 0, back.wreck_field.as_ref(), index, "at the raider's planet");
                    continue;
                };
                world.catch_up(cat, 1, at);
                world.games[1].planet.settle();
                run_until(cat, &mut world, at);
                let done = match step.action.as_str() {
                    "start" => world.start_repairs(cat, 1, at).map(|_| 0),
                    "collect" => world.collect_wreck(cat, 1, at),
                    _ => { world.sweep_wrecks(cat, 1, at); Ok(0) }
                };
                let when = format!("after {} at {at}", step.action);
                assert_eq!(done.is_err(), step.error.is_some(), "case {index} {when}: world {done:?} host {:?}", step.error);
                stock(step.planet_after.as_ref(), &world, 1, index, &when);
                wreck(&world, 1, step.wreck.as_ref(), index, &when);
            }
            Some(true)
        }

        /// The host's first wreck field at a planet against the world's.
        fn wreck(w: &World, p: usize, host: Option<&HostWreck>, case: usize, when: &str) {
            let ours = w.wrecks.get(&p).and_then(|f| f.first());
            let (Some(host), Some(ours)) = (host, ours) else {
                assert_eq!(host.is_some(), ours.is_some(), "case {case} {when}: wreck field host {} world {ours:?}", host.map_or("none".into(), |h| h.status.clone()));
                return;
            };
            let names = unit_names();
            let mut theirs: Vec<(u32, i64)> = host.ships.iter().map(|s| (names[&s.machine_name], s.quantity)).filter(|s| s.1 > 0).collect();
            theirs.sort();
            let mut mine: Vec<(u32, i64)> = ours.ships.iter().copied().filter(|s| s.1 > 0).collect();
            mine.sort();
            let state = match ours.state { WreckState::Active => "active", WreckState::Repairing => "repairing", WreckState::Blocked => "blocked" };
            assert_eq!((state, mine), (host.status.as_str(), theirs), "case {case} {when}: wreck field");
            assert_eq!(Some(ours.expires), host.expires_at, "case {case} {when}: wreck expiry");
            if ours.state == WreckState::Repairing {
                assert_eq!((Some(ours.started), Some(ours.completes)), (host.repair_started_at, host.repair_completed_at), "case {case} {when}: repair times");
            }
        }

        fn unit_names() -> HashMap<String, u32> {
            let units: serde_json::Value = serde_json::from_str(&std::fs::read_to_string(format!("{DATA}/units-parity/units-u1.json")).expect("the units export")).expect("json");
            units["units"].as_array().expect("units").iter().map(|u| (u["machine_name"].as_str().unwrap().to_string(), u["id"].as_u64().unwrap() as u32)).collect()
        }

        /// A planet move: account 0 asks to move its planet (with its moon, its ships, a raid of account 1 on the way);
        /// the refusal, then at the due second the slot, the dark matter, what stands on the planet and moon, the ships
        /// flying over and the raid's way home.
        fn play_relocate(cat: &Catalogue, case: &Case, index: usize) -> Option<bool> {
            let r = case.relocation.as_ref()?;
            let raid = r.raid.as_ref();
            let mut configs = vec![config_of(cat, r.planet.id)?];
            let mut players = vec![&r.player];
            let mut places = vec![coords(&r.planet)];
            if let Some(raid) = raid {
                configs.push(config_of(cat, raid.origin.id)?);
                players.push(&raid.player);
                places.push(coords(&raid.origin));
            }
            let to = Coord { galaxy: r.to[0], system: r.to[1], position: r.to[2] };
            // A taken target: some planet stands there.
            if case.perturbation.as_deref() == Some("occupied") {
                configs.push(configs[0]);
                players.push(&r.player);
                places.push(to);
            }
            let rules = players.iter().map(|p| rules_for(cat, p.class)).collect::<Option<Vec<_>>>()?;
            let mut world = World::new(cat, &configs, 1e6, 0.0, 1);
            world.arm(rules, places);
            let start = case.dispatch.departure.unwrap_or(0.0);
            let states = [Some(&r.planet), raid.map(|r| &r.origin)];
            for (a, player) in players.iter().enumerate() {
                for (id, &level) in &player.research {
                    let k = cat.objects.iter().position(|o| o.id == id.parse::<i64>().unwrap() && o.queue == 1).expect("a research in the catalogue");
                    world.games[a].planet.levels[k] = level;
                }
                if let Some(Some(planet)) = states.get(a) {
                    let mut held = planet.ships.clone();
                    held.extend(planet.defence.clone());
                    for (id, amount) in units_of(&held) {
                        assert!(world.set_units(cat, a, id, amount as u64), "a unit in the catalogue");
                    }
                    world.games[a].planet.res = planet.resources;
                }
                world.games[a].planet.settle();
                world.games[a].t = start;
                world.games[a].login_times = vec![start];
                cat.refresh(&mut world.games[a].planet);
            }
            world.dark_matter[0] = r.dark_matter;
            // The host's planet only waits for its move: it neither decides nor builds (its stock is handed over at the due second).
            world.games[0].free = [NEVER; 2];
            world.next[0] = NEVER;
            let moon = r.moon.as_ref().map(|state| {
                let m = world.add_moon(cat, 0, 8000, start);
                for (id, amount) in units_of(&state.ships.iter().chain(&state.defence).map(|(k, v)| (k.clone(), *v)).collect()) {
                    assert!(world.set_units(cat, m, id, amount as u64), "a unit in the catalogue");
                }
                world.games[m].planet.res = state.resources;
                world.games[m].planet.settle();
                let gate = world.rules[0].settings.moon_gear.as_ref().map(|g| g.gate_object);
                if let Some(k) = gate.and_then(|g| cat.objects.iter().position(|o| o.id == g)).filter(|_| r.moon_gate > 0) {
                    world.games[m].planet.levels[k] = r.moon_gate;
                }
                m
            });
            let early = case.perturbation.as_deref() == Some("fleet_active");
            let send = |world: &mut World, raid: &RelocationRaid| {
                world.games[1].t = raid.departure;
                world.dispatch(cat, 1, 0, Mission::Attack, &units_of(&raid.units), [0.0; 3], 1.0).unwrap_or_else(|e| panic!("case {index}: the raid: {e}"));
                assert_eq!(world.fleets.last().map(|f| f.arrive), Some(raid.arrival), "case {index}: the raid's arrival");
            };
            if let (Some(raid), true) = (raid, early) { send(&mut world, raid) }
            let asked = world.relocate(0, to);
            if !case.dispatch.accepted {
                assert!(asked.is_err(), "case {index}: the host refused the move and the world scheduled it");
                return Some(false);
            }
            asked.unwrap_or_else(|e| panic!("case {index}: the host scheduled the move and the world refused it: {e}"));
            if let (Some(raid), false) = (raid, early) { send(&mut world, raid) }
            if case.perturbation.as_deref() == Some("dm_spent") { world.dark_matter[0] = 0 }
            let arrival = case.arrival.as_ref()?;
            let due = arrival.time;
            assert_eq!(world.moves.get(&0).map(|m| m.1), Some(due), "case {index}: the move's due second");
            run_until(cat, &mut world, due);
            if let Some(before) = &arrival.target_before {
                world.games[0].planet.res = before.resources;
                world.games[0].t = due;
            }
            world.move_due(cat, 0);
            let at = arrival.coordinates?;
            assert_eq!(world.coords[0], Coord { galaxy: at[0], system: at[1], position: at[2] }, "case {index}: the planet's slot");
            assert_eq!(Some(world.dark_matter[0]), arrival.dark_matter, "case {index}: dark matter after the move");
            stock(arrival.target_after.as_ref(), &world, 0, index, "after the move");
            if let (Some(m), Some(after)) = (moon, arrival.moon_after.as_ref()) {
                assert_eq!(world.coords[m], world.coords[0], "case {index}: the moon moves with its planet");
                let mut host = after.ships.clone();
                host.extend(after.defence.clone());
                assert_eq!(holds(&world, m), host, "case {index}: what stands on the moon after the move");
            }
            let mut ours: Vec<(f64, HashMap<String, i64>)> = world.fleets.iter().filter(|f| f.mission == Mission::Deploy && world.account[f.owner] == 0)
                .map(|f| (f.arrive, f.ships.iter().map(|&(id, n)| (id.to_string(), n)).collect())).collect();
            let mut host: Vec<(f64, HashMap<String, i64>)> = arrival.transfers.iter().map(|t| (t.arrival, t.units.clone().unwrap_or_default())).collect();
            let key = |x: &(f64, HashMap<String, i64>)| { let mut v: Vec<_> = x.1.iter().map(|(k, n)| (k.clone(), *n)).collect(); v.sort(); v };
            ours.sort_by(|x, y| x.0.total_cmp(&y.0).then_with(|| key(x).cmp(&key(y))));
            host.sort_by(|x, y| x.0.total_cmp(&y.0).then_with(|| key(x).cmp(&key(y))));
            assert_eq!(ours, host, "case {index}: the ships flying over");
            if let Some(back) = &arrival.raid_return {
                let raid = world.fleets.iter().find(|f| f.mission == Mission::Attack).expect("the raid flies home");
                assert_eq!((raid.arrive, raid.home), (back.departure, back.arrival), "case {index}: the raid's way home");
            }
            Some(true)
        }

        /// An ACS defend case: account 0 holds at its mate's planet (account 1) while account 2's attack lands there. The
        /// dispatch rules, the fight with the ally beside the planet (the host's survivors handed over), what the planet,
        /// the field, the ally and the raid hold afterwards, and the ally's flight home.
        fn play_acs_defend(cat: &Catalogue, case: &Case, index: usize) -> Option<bool> {
            let (raid, target, mate) = (case.input.raid.as_ref()?, case.input.target.as_ref()?, case.input.target_player.as_ref()?);
            let configs = [config_of(cat, case.input.origin.id)?, config_of(cat, target.id)?, config_of(cat, raid.origin.id)?];
            let players = [&case.input.origin_player, mate, &raid.player];
            let rules = players.iter().map(|p| rules_for(cat, p.class)).collect::<Option<Vec<_>>>()?;
            let mut world = World::new(cat, &configs, 1e6, 0.0, 1);
            world.arm(rules, vec![coords(&case.input.origin), coords(target), coords(&raid.origin)]);
            if case.perturbation.as_deref() != Some("not_mates") {
                world.alliance_at[0] = Some(0.0);
                world.alliance_at[1] = Some(0.0);
            }
            for (a, (player, planet)) in players.iter().zip([&case.input.origin, target, &raid.origin]).enumerate() {
                for (id, &level) in &player.research {
                    let k = cat.objects.iter().position(|o| o.id == id.parse::<i64>().unwrap() && o.queue == 1).expect("a research in the catalogue");
                    world.games[a].planet.levels[k] = level;
                }
                let mut held = planet.ships.clone();
                held.extend(planet.defence.clone());
                for (id, amount) in units_of(&held) {
                    let u = world.rules[a].units.index(id as i64).expect("a unit in the catalogue");
                    world.yards[a].counts[u] = amount as u64;
                }
                world.games[a].planet.res = planet.resources;
                world.games[a].planet.settle();
                world.games[a].t = case.dispatch.departure.unwrap_or(0.0);
                world.games[a].login_times = vec![world.games[a].t];
                cat.refresh(&mut world.games[a].planet);
            }
            let before = world.games[0].planet.res;
            let flew = world.defend(cat, 0, 1, &units_of(&case.input.units), case.input.cargo, case.input.speed, case.input.holding_hours.unwrap_or(0));
            if !case.dispatch.accepted {
                assert!(flew.is_err(), "case {index}: the host refused this mission and the world flew it");
                return Some(false);
            }
            flew.unwrap_or_else(|e| panic!("case {index}: the host flew this mission and the world refused it: {e}"));
            let (ends, hold) = (case.dispatch.arrival?, case.dispatch.holding_seconds);
            assert_eq!(world.fleets[0].arrive, ends - hold, "case {index}: arrival");
            assert_eq!(world.fleets[0].next(), ends, "case {index}: the hold's end");
            let fuel = before[2] - case.input.cargo[2] - world.games[0].planet.res[2];
            assert!((fuel - case.dispatch.fuel?).abs() < 1e-6, "case {index}: fuel {fuel} host {:?}", case.dispatch.fuel);
            stock(case.dispatch.origin_after.as_ref(), &world, 0, index, "after dispatch");
            world.dispatch(cat, 2, 1, Mission::Attack, &units_of(&raid.units), [0.0; 3], raid.speed).unwrap_or_else(|e| panic!("case {index}: the raid was refused: {e}"));

            let arrival = case.arrival.as_ref()?;
            assert_eq!(world.fleets[1].arrive, arrival.time, "case {index}: the raid's arrival");
            let inside = ends - hold <= arrival.time && arrival.time < ends;
            if let Some(battle) = &arrival.battle {
                let ally = arrival.defend_after.as_ref().and_then(|d| d.units.clone()).unwrap_or_default();
                world.recorded = Some(combat::Recorded {
                    engine: combat::EngineResult {
                        rounds: battle.rounds,
                        attacker_left: counted(&battle.attacker_left),
                        defender_left: counted(&battle.defender_left),
                        allies_left: if inside { vec![units_of(&ally).into_iter().collect()] } else { Vec::new() },
                        partners_left: Vec::new(),
                    },
                    repaired: counted(&battle.repaired),
                    moon_roll: if battle.moon_created { battle.moon_chance } else { battle.moon_chance + 1 },
                });
            }
            world.catch_up(cat, 1, arrival.time);
            world.games[1].planet.settle();
            run_until(cat, &mut world, arrival.time);
            stock(arrival.target_after.as_ref(), &world, 1, index, "after the raid");
            if let Some(debris) = arrival.debris_after {
                assert_eq!(world.debris[1].map(|r| r as i64), debris, "case {index}: debris after the raid");
            }
            let ally = world.fleets.iter().find(|f| f.owner == 0);
            match (&arrival.defend_after, ally) {
                (Some(held), Some(f)) => {
                    assert!(!f.returning, "case {index}: the ally still holds");
                    let ships: HashMap<String, i64> = f.ships.iter().map(|&(id, n)| (id.to_string(), n)).collect();
                    assert_eq!(Some(ships), held.units.clone(), "case {index}: the ally's survivors");
                    assert_eq!(Some(f.cargo.map(|r| r as i64)), held.cargo, "case {index}: the ally's cargo");
                }
                (Some(_), None) => panic!("case {index}: the host's ally still holds and the world's is gone"),
                (None, Some(f)) if !f.returning && case.defend_return.is_none() => panic!("case {index}: the host's ally was wiped out and the world's holds"),
                _ => {}
            }
            if let (Some(back), Some(f)) = (&arrival.raid_return, world.fleets.iter().find(|f| f.owner == 2)) {
                assert_eq!(Some(f.cargo.map(|r| r as i64)), back.cargo, "case {index}: the raid's loot");
            }
            let Some(home) = &case.defend_return else {
                assert!(world.fleets.iter().all(|f| f.owner != 0), "case {index}: nothing of the ally flies home");
                return Some(true);
            };
            // A hold that ended long before the raid has its fleet home already.
            if home.arrival <= arrival.time { return Some(true) }
            run_until(cat, &mut world, ends.max(arrival.time));
            let f = world.fleets.iter().find(|f| f.owner == 0).unwrap_or_else(|| panic!("case {index}: the ally flies home"));
            assert_eq!((f.arrive + f.holding, f.home), (home.departure, home.arrival), "case {index}: the flight home");
            assert_eq!(Some(f.cargo.map(|r| r as i64)), home.cargo, "case {index}: the cargo flying home");
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
            let moon = w.moon_of[1].expect("a standing moon has a slot");
            let stock = w.games[moon].planet.res;
            for r in 0..3 {
                assert!((stock[r] - after.resources[r]).abs() < 1e-6, "case {index}: moon stock {r} world {} host {}", stock[r], after.resources[r]);
            }
            let held: HashMap<String, i64> = w.rules[1].units.units.iter().zip(&w.yards[moon].counts).filter(|(_, &n)| n > 0).map(|(u, &n)| (u.id.to_string(), n as i64)).collect();
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
                allies_left: Vec::new(), partners_left: Vec::new(),
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
            // The host loads the planet as the fleet lands, so the delivery adds to the stock written then.
            world.catch_up(cat, 0, done.time);
            world.games[0].planet.settle();
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
            // The host loads the planet as the fleet lands, so the delivery adds to the stock written then.
            world.catch_up(cat, 0, done.time);
            world.games[0].planet.settle();
            run_until(cat, &mut world, done.time);
            stock(done.origin_after.as_ref(), &world, 0, index, "back home");
            Some(true)
        }
    }
}
