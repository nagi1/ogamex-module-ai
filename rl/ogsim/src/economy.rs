//! One planet's economy: production, stores, the building and research queues and the logins that refill them.
use serde::Deserialize;
use crate::HashMap;


/// Metal-equivalent weights of the closed-loop value (plan/rl/reward-design.md §2), so the simulator and the PHP
/// evaluation score the same thing.
pub const WEIGHTS: [f64; 3] = [1.0, 1.5, 2.0];
/// The model's inputs are a named subset of what EconomyChoiceEncoder emits, computed here with the encoder's own
/// formulas, so the model trained in this crate is served from the PHP vectors with a column pick (plan/rl).
pub const STATE_NAMES: [&str; 17] = [
    "speed_economy", "pl_metal", "pl_crystal", "pl_deuterium", "pl_metal_fill", "pl_crystal_fill", "pl_deuterium_fill",
    "pl_metal_ph", "pl_crystal_ph", "pl_deuterium_ph", "pl_energy_max", "pl_energy_used", "pl_factor", "pl_temperature",
    "pl_fields_used", "pl_fields_max", "pl_position",
];
pub const CAND_NAMES: [&str; 13] = [
    "cost_metal", "cost_crystal", "cost_deuterium", "cost_hours", "cost_vs_stock", "build_hours", "level", "gain_ph",
    "payback_hours", "energy_delta", "storage_gain", "unlocks", "eta_hours",
];
pub const STATE_DIM: usize = STATE_NAMES.len();
pub const CAND_DIM: usize = CAND_NAMES.len();
/// The encoder's caps: a payback and a wait never exceed this many hours.
const FAR_HOURS: f64 = 10_000.0;
/// A time no event has or will have. It must be beyond every real instant, because a world can start at any second
/// (the mission cases start at unix seconds, far past any finite "never" small enough to look like a sentinel).
/// The shortest gap between two actions the host does not flag (`HostDetector`: under ten seconds reads as a script).
const MIN_GAP: f64 = 10.0;
const DAY: f64 = 86_400.0;

fn awake(game: &Game, t: f64) -> f64 {
    let hour = (t % DAY) / 3600.0;
    let end = game.sleep_start + game.sleep_hours;
    let inside = if end <= 24.0 { hour >= game.sleep_start && hour < end } else { hour >= game.sleep_start || hour < end - 24.0 };
    if !inside { return t }
    let resume = if end <= 24.0 { end } else { end - 24.0 };
    let day = (t / DAY).floor();
    if resume > hour { day * DAY + resume * 3600.0 } else { (day + 1.0) * DAY + resume * 3600.0 }
}
pub(crate) const NEVER: f64 = f64::INFINITY;

#[derive(Deserialize)]
struct RawCatalogue {
    economy_speed: f64,
    time_grid_level: usize,
    /// Catalogue v3: researches play beside the buildings, in their own queue.
    #[serde(default)]
    research_grid_level: usize,
    objects: Vec<RawObject>,
    #[serde(default)]
    research: Vec<RawObject>,
    time_modifiers: Vec<RawModifier>,
    #[serde(default)]
    time_modifiers_research: Vec<RawModifier>,
    /// Per class (the host enum value as a key), the factor a research time is multiplied by and truncated; absent in older exports.
    #[serde(default)]
    research_time_multipliers: HashMap<String, f64>,
    /// The research that pools the labs of the account's other planets (`getResearchNetworkLabLevel`); absent in older exports.
    #[serde(default)]
    research_network: Option<i64>,
    planets: Vec<RawPlanet>,
    /// Catalogue v4: the units whose count moves production; every planet's unit lists follow this order.
    #[serde(default)]
    units: Vec<i64>,
    #[serde(default)]
    unit_probes: Vec<RawProbe>,
}

#[derive(Deserialize)]
struct RawProbe {
    planet: usize,
    levels: HashMap<String, u32>,
    units: HashMap<String, u64>,
    /// [metal, crystal, deuterium] per hour, energy made, energy used, and the host's factor in whole percent.
    answer: [f64; 6],
}

#[derive(Deserialize)]
struct RawObject {
    id: i64,
    consumes_field: bool,
    unlocks: f64,
    requirements: Vec<RawRequirement>,
    price: Vec<[f64; 3]>,
    build_seconds: Vec<f64>,
    build_seconds_grid: Vec<Vec<f64>>,
    /// Row 36: planet fields this object adds per level, probed from the host; a catalogue without the column adds none.
    #[serde(default)]
    fields_added: Vec<u32>,
    /// The energy the host asks for at each level; it is held, not spent. A catalogue without the column asks none.
    #[serde(default)]
    energy_price: Vec<f64>,
    /// M2: the planet types the host lets it stand on ("planet", "moon"); a catalogue without the column is planets only.
    #[serde(default)]
    valid_types: Vec<String>,
}

#[derive(Deserialize)]
struct RawRequirement {
    id: i64,
    level: u32,
}

#[derive(Deserialize)]
struct RawModifier {
    id: i64,
    divisor: Vec<f64>,
}

#[derive(Deserialize)]
struct RawPlanet {
    #[serde(default)]
    planet_id: i64,
    position: f64,
    temperature: f64,
    #[serde(default)]
    character_class: Option<u32>,
    field_max: u32,
    /// M2: a moon's config (`merge_moons.py`): no production, its own buildings.
    #[serde(default)]
    moon: bool,
    basic_income: [f64; 4],
    resources: [f64; 3],
    levels: HashMap<String, u32>,
    production: HashMap<String, Vec<[f64; 4]>>,
    storage: HashMap<String, Vec<[f64; 3]>>,
    /// Production rows that depend on research levels: rows[level][combo], combo row-major over `by`.
    #[serde(default)]
    production_research: HashMap<String, RawProductionResearch>,
    #[serde(default)]
    unit_counts: Vec<u64>,
    #[serde(default)]
    unit_production: Vec<RawUnitProduction>,
}

#[derive(Deserialize)]
struct RawUnitProduction {
    usable: Option<Vec<RawUsable>>,
    own: Option<Vec<[f64; 4]>>,
    energy: Vec<[f64; 2]>,
    producers: Vec<RawUnitProducer>,
}

#[derive(Deserialize)]
struct RawUsable {
    id: i64,
    per_level: u64,
}

#[derive(Deserialize)]
struct RawUnitProducer {
    id: i64,
    rows: Vec<Vec<Vec<f64>>>,
}

#[derive(Deserialize)]
struct RawProductionResearch {
    by: Vec<i64>,
    rows: Vec<Vec<[f64; 4]>>,
}

pub struct Object {
    pub id: i64,
    /// 0 builds on the planet, 1 researches in the lab; each queue holds one item at a time.
    pub queue: usize,
    pub consumes_field: bool,
    pub unlocks: f64,
    /// (object index, level); no index means a requirement outside the table (research), met only at level 0.
    pub requires: Vec<(Option<usize>, u32)>,
    /// Price and host build time (every station at zero) of the level after index `level`.
    pub price: Vec<[f64; 3]>,
    pub build_seconds: Vec<f64>,
    /// The host's own build time per level for every combination of modifier levels up to `time_grid_level`.
    pub build_seconds_grid: Vec<Vec<f64>>,
    /// Row 36: the planet fields this object adds at each level, per the host's `getPlanetFieldMax`; empty when the
    /// catalogue predates the column, which adds nothing.
    pub fields_added: Vec<u32>,
    /// The energy the planet must hold for the level after index `level`.
    pub energy_price: Vec<f64>,
    /// M2: whether it may stand on a planet, and on a moon (`ObjectService::objectValidPlanetType`).
    pub on_planet: bool,
    pub on_moon: bool,
}

#[derive(Clone)]
pub struct PlanetConfig {
    /// The host's planet id, which the mission cases address planets by.
    pub planet_id: i64,
    pub position: f64,
    pub temperature: f64,
    /// The owner's character class (the host enum value) the production rows were made with; None for none.
    pub character_class: Option<u32>,
    pub field_max: u32,
    /// M2: a moon (no production; it takes the objects marked `on_moon`).
    pub moon: bool,
    pub basic: [f64; 4],
    pub resources: [f64; 3],
    pub levels: Vec<u32>,
    pub production: Vec<Option<Vec<[f64; 4]>>>,
    pub storage: Vec<Option<Vec<[f64; 3]>>>,
    /// Per object, the researches its production rows depend on and the rows [level][combo].
    pub production_research: Vec<Option<(Vec<usize>, Vec<Vec<[f64; 4]>>)>>,
    /// Units held at the start, per `Catalogue::units`.
    pub units: Vec<u64>,
    /// Per `Catalogue::units`, the host's answers for what that unit does to this planet's production.
    pub unit_production: Vec<UnitProduction>,
    /// Which objects and units of the tables above produce anything, read once on first use (the tables never change
    /// after load): `production` runs for every candidate of every decision and used to scan every object and unit.
    pub prod_index: std::sync::OnceLock<ProdIndex>,
}

/// The producers of a planet config's tables, in the order `production` adds them up.
#[derive(Clone, Default)]
pub struct ProdIndex {
    /// Objects with a production row (plain or by research), ascending.
    producers: Vec<usize>,
    /// Units the host can put to use (an energy or own table), ascending.
    units: Vec<usize>,
    /// Per object, the (unit, entry of its `producers`) that change its row, by unit then entry.
    changes: Vec<Vec<(usize, usize)>>,
    /// Per object k, the rows (positions in `producers`) whose value reads k's level: k's own and those whose research
    /// combo names k (`raised`).
    dependents: Vec<Vec<usize>>,
    /// Per object k, the units in use whose bound counts k's level.
    bounded_by: Vec<Vec<usize>>,
}

/// What `refresh` makes of a planet with one object a level up (`Catalogue::raised`).
pub(crate) struct Raised {
    pub(crate) cap: [f64; 3],
    pub(crate) energy_max: f64,
    pub(crate) energy_used: f64,
}

/// One unit's production tables on one planet, every row indexed by the count the host puts to use: the held count,
/// capped by `usable` (the sum of an object's level times its number) when the host bounds it. A count past a table
/// reads its edge, which is outside what the host answered (the export's unit grid).
#[derive(Clone)]
pub struct UnitProduction {
    pub usable: Option<Vec<(usize, u64)>>,
    /// The unit's own row as a producer, [count].
    pub own: Option<Vec<[f64; 4]>>,
    /// [produced, used] energy the host counts outside every producer row, [count].
    pub energy: Vec<[f64; 2]>,
    /// (object index, the change it makes to that object's row `[level][component][count]`; a component it never
    /// moves is an empty list).
    pub producers: Vec<(usize, Vec<Vec<Vec<f64>>>)>,
}

pub struct Catalogue {
    pub speed: f64,
    pub time_grid_level: usize,
    pub research_grid_level: usize,
    pub objects: Vec<Object>,
    /// Per queue, the objects whose level divides every build time, with the divisor per level.
    pub modifiers: [Vec<(usize, Vec<f64>)>; 2],
    /// Per class, the host's research time factor (a Discoverer's 0.75); the tables are made at factor 1.
    pub research_time_multipliers: HashMap<u32, f64>,
    /// Object index of the research that adds the best labs of the account's other planets to the researching one.
    pub research_network: Option<usize>,
    pub planets: Vec<PlanetConfig>,
    /// M3: where the moon configs start in `planets` (one per home, or one per config when made at load).
    pub first_moon: usize,
    /// Host ids of the units that move production; `Planet::units` and the planet tables follow this order.
    pub units: Vec<i64>,
    /// The host's own answers at levels and unit counts its tables are not tabulated at (`ai:rl-catalogue`).
    pub probes: Vec<Probe>,
    /// Seconds between one login of an account and the next, measured on the planner's own accounts; empty means the account
    /// is always there and refills its queue the instant it frees.
    pub login_gaps: Vec<f64>,
    /// Per object, the highest level worth having when it is priced in energy alone (no metal, crystal or deuterium):
    /// the highest level any object or unit requires of it (WIK-175, WIK-139: graviton's one level unlocks the
    /// deathstar; further levels only cost the energy of thousands of satellites). None for every other object.
    /// PHP twin: Modules/AI/app/Domain/Research/ResearchCeiling.php. Set by `set_ceilings` once the units are known.
    pub ceiling: Vec<Option<u32>>,
}

/// One host answer to replay: a planet config at these levels holding these units, and what the host made of it.
pub struct Probe {
    pub config: usize,
    pub levels: Vec<u32>,
    /// Per `Catalogue::units`.
    pub units: Vec<u64>,
    /// [metal, crystal, deuterium] per hour, energy made, energy used, factor in whole percent.
    pub answer: [f64; 6],
}

/// Row 23: where a planet's stock went, cumulatively and in metal-equivalent at the score weights. Every field is
/// booked at the second the host moves the resources, so a day's line can reconcile the measured stock against them
/// (the season writes the difference of two samples, so a line with no event reads zero on both sides).
#[derive(Clone, Copy, Default)]
pub struct Books {
    pub produced: f64,
    /// Production the store could not hold.
    pub wasted: f64,
    pub spent_build: f64,
    pub spent_research: f64,
    pub spent_units: f64,
    /// Deuterium paid at dispatch, less the half the host hands back inside the hold.
    pub fuel: f64,
    pub loot_in: f64,
    pub loot_out: f64,
    pub expedition_in: f64,
    /// Resources destroyed: cargo with a fleet in flight (row 23s), a moon's store with the moon.
    pub cargo_lost: f64,
    /// Deuterium a phalanx burnt on its scans.
    pub scans: f64,
    /// What the merchant paid over what it took, at the score weights: its rates are not those weights.
    pub merchant_net: f64,
}

impl Books {
    /// The stock this books says the account should have gained.
    pub fn net(&self) -> f64 {
        self.produced - self.wasted - self.spent_build - self.spent_research - self.spent_units - self.fuel
            + self.loot_in - self.loot_out + self.expedition_in - self.cargo_lost + self.merchant_net - self.scans
    }

    pub fn add(&mut self, other: &Books) {
        self.produced += other.produced;
        self.wasted += other.wasted;
        self.spent_build += other.spent_build;
        self.spent_research += other.spent_research;
        self.spent_units += other.spent_units;
        self.fuel += other.fuel;
        self.loot_in += other.loot_in;
        self.loot_out += other.loot_out;
        self.expedition_in += other.expedition_in;
        self.cargo_lost += other.cargo_lost;
        self.merchant_net += other.merchant_net;
        self.scans += other.scans;
    }

    /// Books resources at the host's score weights.
    pub fn book(&mut self, field: &mut f64, resources: [f64; 3]) {
        *field += weighted(resources);
    }
}

#[derive(Clone)]
pub struct Planet {
    pub config: usize,
    pub levels: Vec<u32>,
    pub res: [f64; 3],
    pub rate: [f64; 3],
    pub cap: [f64; 3],
    pub energy_max: f64,
    pub energy_used: f64,
    pub factor: f64,
    /// Units held, per `Catalogue::units`; a missing entry is none held. Call `refresh` after changing it.
    pub units: Vec<u64>,
    /// Row 23: the planet's own books, carried by the planet so a colony's are its owner's once summed.
    pub books: Books,
    /// The planet's own size: its config's, or the host's draw for a colony (`World::found`).
    pub field_max: u32,
    /// The levels of the account's other planets, which the research network reads; the world keeps them, empty alone.
    pub peers: std::sync::Arc<Vec<Vec<u32>>>,
    /// W14: the stock the host last wrote, at the planet's last page load or spend, plus what fleets delivered since.
    pub settled: [f64; 3],
    /// W15: what the account's officers multiply a research time by (1 without), set when the account decides.
    pub research_multiplier: f64,
}

impl Planet {
    /// A page load or a spend: the host writes the stock as it stands.
    pub fn settle(&mut self) {
        self.settled = self.res;
    }

    /// A fleet's delivery. The host adds it to the stock it last wrote and, at the next page load, lets production in
    /// only up to the store (`PlanetService::updateResources`): a delivery near the cap takes the room production had.
    pub fn deliver(&mut self, add: [f64; 3]) {
        for r in 0..3 {
            let written = as_stored(self.settled[r] + add[r]);
            let held = if written >= self.cap[r] { written } else { as_stored((self.res[r] + add[r]).min(self.cap[r])) };
            // Row 23: the production the delivery took the room of was booked as made; it spilled.
            self.books.wasted += WEIGHTS[r] * (self.res[r] + add[r] - held);
            self.res[r] = held;
            self.settled[r] = written;
        }
    }
}

#[derive(Clone)]
pub struct Game {
    pub planet: Planet,
    pub t: f64,
    pub horizon: f64,
    pub bonus_hours: f64,
    pub invested: f64,
    pub done: bool,
    pub invalid: u32,
    /// The queue whose decision is due now.
    pub queue: usize,
    /// The second each queue is free again; NEVER once it has nothing left to do.
    pub free: [f64; 2],
    /// Row 47: this game is played by a `World`, which never retires an account that has nothing to build - its queues
    /// sleep to the account's next login and ask again there. The economy-only `VecEnv` leaves it false, so
    /// `Catalogue::finish` still ends an episode at the horizon.
    pub in_world: bool,
    /// The object each queue has decided on and is saving up for; it starts the moment the stock covers its price.
    pub commit: [Option<usize>; 2],
    /// The account's logins so far (ascending) and the generator that draws the next gap.
    pub login_times: Vec<f64>,
    pub login_rng: u64,
    /// The second the current login's session ends, and the second of the account's last action: inside a session the
    /// account comes back as soon as a queue frees, but never closer than the host's floor to its own last action.
    pub session_end: f64,
    pub last_action: f64,
    /// When the player sleeps: the hour of day the dark window starts and how many hours it lasts (drawn per account, so
    /// two accounts do not share a night). A player logs in outside it or not at all.
    pub sleep_start: f64,
    /// How many times the shared login gap this player waits: 1 for an active player, more for a casual one (the
    /// `pace=` world arm draws it per account; real universes hold both).
    pub pace: f64,
    pub sleep_hours: f64,
    /// (end second, object index) of the builds under way, earliest first; the level lands when the clock reaches it.
    pub pending: Vec<(f64, usize)>,
    /// The builds under way on the account's other planets that can hold this one's (`held_until`); the world keeps it.
    pub account_pending: Vec<(f64, usize)>,
    /// (start second, object index, end second, stock before paying) of every build, for traces and the host replay.
    pub history: Vec<(f64, usize, f64, [f64; 3])>,
    /// With tracing on: (decision second, state features, candidate features of every object, legal flags, the object chosen) per build, taken the
    /// moment the free queue asks, so the host encoder can be compared with this one on the same planet state.
    pub trace: Option<Vec<(f64, Vec<f32>, Vec<f32>, Vec<u8>, usize)>>,
}

#[derive(Clone, Copy)]
pub enum Baseline {
    Random,
    Soonest,
    Payback,
    /// Payback plus what every experienced player does with stores: upgrade the one that would overflow before
    /// the queue is free again.
    Player,
}

fn per_object<T: Clone>(map: &HashMap<String, T>, index: &HashMap<i64, usize>, count: usize) -> Vec<Option<T>> {
    let mut rows = vec![None; count];
    for (id, row) in map {
        if let Some(&i) = id.parse::<i64>().ok().and_then(|id| index.get(&id)) {
            rows[i] = Some(row.clone());
        }
    }
    rows
}

fn ln1p(value: f64) -> f32 {
    value.max(0.0).ln_1p() as f32
}

/// EconomyChoiceEncoder::signedLog.
fn signed_log(value: f64) -> f32 {
    (if value < 0.0 { -(-value).ln_1p() } else { value.ln_1p() }) as f32
}

pub(crate) fn weighted(values: [f64; 3]) -> f64 {
    (0..3).map(|r| WEIGHTS[r] * values[r]).sum()
}

/// A resource amount as the database keeps it: Laravel binds a float as a string, and PHP writes that string at
/// `precision = 14`, so the value is rounded to 14 significant digits on every save and re-read from the rounded
/// text. A stock a hair under a loot threshold lands on the other side of it otherwise (mission case 563: the host
/// looted 234 metal where the full double looted 235).
pub(crate) fn as_stored(value: f64) -> f64 {
    format!("{value:.13e}").parse().unwrap_or(value)
}

pub fn next_random(state: &mut u64) -> u64 {
    *state = state.wrapping_add(0x9E37_79B9_7F4A_7C15);
    let mut z = *state;
    z = (z ^ (z >> 30)).wrapping_mul(0xBF58_476D_1CE4_E5B9);
    z = (z ^ (z >> 27)).wrapping_mul(0x94D0_49BB_1331_11EB);
    z ^ (z >> 31)
}

/// A producing unit's row at n held: the host's output is linear in the count, so past the export's last probe the row
/// scales with n. The export stops at a thousand; a grown planet holds far more.
pub(crate) fn own_row(rows: &[[f64; 4]], n: usize) -> [f64; 4] {
    let last = rows.len() - 1;
    if n <= last { return rows[n] }
    rows[last].map(|x| x * n as f64 / last as f64)
}

impl Catalogue {
    pub fn load(path: &str) -> Result<Catalogue, String> {
        let text = std::fs::read_to_string(path).map_err(|e| format!("{path}: {e}"))?;
        let raw: RawCatalogue = serde_json::from_str(&text).map_err(|e| format!("{path}: {e}"))?;
        let everything: Vec<(usize, &RawObject)> = raw.objects.iter().map(|o| (0, o)).chain(raw.research.iter().map(|o| (1, o))).collect();
        let index: HashMap<i64, usize> = everything.iter().enumerate().map(|(i, (_, o))| (o.id, i)).collect();
        let count = everything.len();
        let objects = everything.iter().map(|&(queue, o)| Object {
            id: o.id,
            queue,
            consumes_field: o.consumes_field,
            unlocks: o.unlocks,
            requires: o.requirements.iter().map(|r| (index.get(&r.id).copied(), r.level)).collect(),
            price: o.price.clone(),
            build_seconds: o.build_seconds.clone(),
            build_seconds_grid: o.build_seconds_grid.clone(),
            fields_added: o.fields_added.clone(),
            energy_price: o.energy_price.clone(),
            on_planet: o.valid_types.is_empty() || o.valid_types.iter().any(|t| t == "planet"),
            on_moon: o.valid_types.iter().any(|t| t == "moon"),
        }).collect();
        let modifiers_of = |rows: &Vec<RawModifier>| -> Vec<(usize, Vec<f64>)> { rows.iter().filter_map(|m| index.get(&m.id).map(|&i| (i, m.divisor.clone()))).collect() };
        let modifiers = [modifiers_of(&raw.time_modifiers), modifiers_of(&raw.time_modifiers_research)];
        let planets = raw.planets.iter().map(|p| PlanetConfig {
            planet_id: p.planet_id,
            position: p.position,
            temperature: p.temperature,
            character_class: p.character_class,
            field_max: p.field_max,
            moon: p.moon,
            basic: p.basic_income,
            resources: p.resources,
            levels: per_object(&p.levels, &index, count).into_iter().map(|l| l.unwrap_or(0)).collect(),
            production: per_object(&p.production, &index, count),
            storage: per_object(&p.storage, &index, count),
            production_research: {
                let mut rows = vec![None; count];
                for (id, row) in &p.production_research {
                    let by = row.by.iter().filter_map(|r| index.get(r).copied()).collect::<Vec<_>>();
                    if let (Some(&k), true) = (id.parse::<i64>().ok().and_then(|id| index.get(&id)).as_ref().copied(), by.len() == row.by.len()) {
                        rows[k] = Some((by, row.rows.clone()));
                    }
                }
                rows
            },
            units: p.unit_counts.clone(),
            unit_production: p.unit_production.iter().map(|u| UnitProduction {
                usable: u.usable.as_ref().map(|by| by.iter().filter_map(|b| index.get(&b.id).map(|&k| (k, b.per_level))).collect()),
                own: u.own.clone(),
                energy: u.energy.clone(),
                producers: u.producers.iter().filter_map(|p| index.get(&p.id).map(|&k| (k, p.rows.clone()))).collect(),
            }).collect(),
            prod_index: Default::default(),
        }).collect();

        let unit_ids = raw.units.clone();
        let mut planets: Vec<PlanetConfig> = planets;
        let first_moon = planets.iter().position(|c| c.moon).unwrap_or(planets.len());
        // A catalogue made before moons were exported gets one per config: the host's moon is its planet with no
        // production, nothing built, an empty store and one field; it takes only the objects marked for moons.
        if first_moon == planets.len() {
            let moons: Vec<PlanetConfig> = planets.iter().map(|c| PlanetConfig {
                moon: true, field_max: 1, basic: [0.0; 4], resources: [0.0; 3], levels: vec![0; count],
                production: vec![None; count], production_research: vec![None; count], units: vec![0; raw.units.len()], unit_production: Vec::new(), prod_index: Default::default(),
                ..c.clone()
            }).collect();
            planets.extend(moons);
        }

        Ok(Catalogue { speed: raw.economy_speed, time_grid_level: raw.time_grid_level, research_grid_level: raw.research_grid_level, objects, modifiers, research_network: raw.research_network.and_then(|id| index.get(&id).copied()), research_time_multipliers: raw.research_time_multipliers.iter().filter_map(|(c, m)| Some((c.parse().ok()?, *m))).collect(), planets, first_moon, units: raw.units, probes: raw.unit_probes.iter().map(|p| Probe {
            config: p.planet,
            levels: per_object(&p.levels, &index, count).into_iter().map(|l| l.unwrap_or(0)).collect(),
            units: unit_ids.iter().map(|id| p.units.get(&id.to_string()).copied().unwrap_or(0)).collect(),
            answer: p.answer,
        }).collect(), login_gaps: Vec::new(), ceiling: Vec::new() }.with_ceilings(&[]))
    }

    /// `ceiling` from the objects' own requirements plus `unit_needs` ((object index, level) a unit requires).
    pub fn with_ceilings(mut self, unit_needs: &[(usize, u32)]) -> Self {
        self.set_ceilings(unit_needs);
        self
    }

    pub fn set_ceilings(&mut self, unit_needs: &[(usize, u32)]) {
        let mut need = vec![0u32; self.objects.len()];
        let own = self.objects.iter().flat_map(|o| o.requires.iter()).filter_map(|&(k, l)| Some((k?, l)));
        for (k, l) in own.chain(unit_needs.iter().copied()) {
            need[k] = need[k].max(l);
        }
        self.ceiling = self.objects.iter().enumerate().map(|(k, o)| {
            let energy_only = o.price.first().is_some_and(|p| p.iter().all(|&r| r <= 0.0)) && o.energy_price.first().is_some_and(|&e| e > 0.0);
            energy_only.then_some(need[k])
        }).collect();
    }

    /// M3: the config a moon raised over a planet of the account whose capital has config `home` plays on.
    pub fn moon_config(&self, home: usize) -> usize {
        self.first_moon + home % (self.planets.len() - self.first_moon)
    }

    pub fn new_game(&self, config: usize, horizon_hours: f64, bonus_hours: f64, login_seed: u64) -> Game {
        let mut game = self.new_paused_game(config, horizon_hours, bonus_hours, login_seed);
        self.next_decision(&mut game);
        game
    }

    /// A game at second zero whose clock has not moved yet, for a world that moves it with `tick`.
    pub fn new_paused_game(&self, config: usize, horizon_hours: f64, bonus_hours: f64, login_seed: u64) -> Game {
        let c = &self.planets[config];
        let mut planet = Planet { config, levels: c.levels.clone(), res: c.resources, rate: [0.0; 3], cap: [0.0; 3], energy_max: 0.0, energy_used: 0.0, factor: 1.0, units: c.units.clone(), books: Books::default(), field_max: c.field_max, peers: Default::default(), settled: c.resources, research_multiplier: 1.0 };
        self.refresh(&mut planet);
        // A night between six and eight hours (the host flags a dark period under six), starting at a drawn hour.
        let mut seed = login_seed | 1;
        let sleep_start = (next_random(&mut seed) % 24) as f64;
        let sleep_hours = 6.0 + (next_random(&mut seed) % 3) as f64;
        Game { planet, t: 0.0, horizon: horizon_hours * 3600.0, bonus_hours, invested: 0.0, done: false, in_world: false, invalid: 0, queue: 0, free: [0.0; 2], commit: [None; 2], login_times: Vec::new(), login_rng: login_seed, session_end: 0.0, last_action: f64::NEG_INFINITY, sleep_start, pace: 1.0, sleep_hours, pending: Vec::new(), account_pending: Vec::new(), history: Vec::new(), trace: None }
    }

    /// The host's production row of object k at `level` under the planet's research levels and units, if k produces
    /// or burns anything (getObjectProduction: the units held count at the planet's current levels).
    pub(crate) fn production_row(&self, planet: &Planet, k: usize, level: usize) -> Option<[f64; 4]> {
        let c = &self.planets[planet.config];
        let mut row = match (&c.production[k], &c.production_research[k]) {
            (_, Some((by, grid))) => {
                let side = self.research_grid_level + 1;
                let combo = by.iter().fold(0usize, |at, &r| at * side + (planet.levels[r] as usize).min(side - 1));
                let row = &grid[level.min(grid.len() - 1)];
                row[combo.min(row.len() - 1)]
            }
            (Some(rows), None) => rows[level.min(rows.len() - 1)],
            _ => return None,
        };
        if planet.units.iter().any(|&n| n > 0) { self.add_unit_change(planet, k, level, &mut row) }
        Some(row)
    }

    /// Adds what the units held change in object k's row at `level`.
    fn add_unit_change(&self, planet: &Planet, k: usize, level: usize, row: &mut [f64; 4]) {
        for (u, unit) in self.planets[planet.config].unit_production.iter().enumerate() {
            let n = self.units_in_use(planet, u);
            if n == 0 { continue }
            for (_, rows) in unit.producers.iter().filter(|(p, _)| *p == k) {
                for (component, change) in rows[level.min(rows.len() - 1)].iter().enumerate() {
                    if change.is_empty() { continue }
                    row[component] += change[n.min(change.len() - 1)];
                }
            }
        }
    }

    /// How many of unit u the host puts to use on the planet: the count held, capped when the host bounds it by levels.
    /// A planet with no table for the unit (one that can neither build nor hold it) has none in use.
    fn units_in_use(&self, planet: &Planet, u: usize) -> usize {
        let held = planet.units.get(u).copied().unwrap_or(0);
        let Some(table) = self.planets[planet.config].unit_production.get(u) else { return 0 };
        if held == 0 || (table.energy.is_empty() && table.own.is_none()) { return 0 }
        let bound = table.usable.as_ref()
            .map_or(u64::MAX, |by| by.iter().map(|&(k, per)| per * planet.levels[k] as u64).sum());
        held.min(bound) as usize
    }

    fn prod_index<'a>(&self, c: &'a PlanetConfig) -> &'a ProdIndex {
        c.prod_index.get_or_init(|| {
            let producers: Vec<usize> = (0..c.production.len()).filter(|&k| c.production[k].is_some() || c.production_research[k].is_some()).collect();
            let units: Vec<usize> = (0..c.unit_production.len()).filter(|&u| !(c.unit_production[u].energy.is_empty() && c.unit_production[u].own.is_none())).collect();
            let count = c.production.len();
            let mut changes = vec![Vec::new(); count];
            for (u, unit) in c.unit_production.iter().enumerate() {
                for (e, (p, _)) in unit.producers.iter().enumerate() {
                    if let Some(list) = changes.get_mut(*p) { list.push((u, e)) }
                }
            }
            let mut dependents = vec![Vec::new(); count];
            for (at, &i) in producers.iter().enumerate() {
                dependents[i].push(at);
                if let Some((by, _)) = &c.production_research[i] {
                    for &r in by {
                        if r != i && r < count && !dependents[r].contains(&at) { dependents[r].push(at) }
                    }
                }
            }
            let mut bounded_by = vec![Vec::new(); count];
            for &u in &units {
                for &(k, _) in c.unit_production[u].usable.iter().flatten() {
                    if k < count && !bounded_by[k].contains(&u) { bounded_by[k].push(u) }
                }
            }
            ProdIndex { producers, units, changes, dependents, bounded_by }
        })
    }

    /// Producer k's row at the planet's levels with the units in use (`in_use`) changing it (`add_unit_change`).
    #[inline]
    fn producer_row(&self, c: &PlanetConfig, index: &ProdIndex, planet: &Planet, in_use: &[usize], k: usize) -> [f64; 4] {
        let side = self.research_grid_level + 1;
        let level = planet.levels[k] as usize;
        let mut row = match (&c.production[k], &c.production_research[k]) {
            (_, Some((by, grid))) => {
                let combo = by.iter().fold(0usize, |at, &r| at * side + (planet.levels[r] as usize).min(side - 1));
                let level = level.min(grid.len() - 1);
                grid[level][combo.min(grid[level].len() - 1)]
            }
            (Some(rows), None) => rows[level.min(rows.len() - 1)],
            (None, None) => unreachable!(),
        };
        for &(u, e) in &index.changes[k] {
            let n = in_use[u];
            if n == 0 { continue }
            let changes = &c.unit_production[u].producers[e].1;
            for (component, change) in changes[level.min(changes.len() - 1)].iter().enumerate() {
                if change.is_empty() { continue }
                row[component] += change[n.min(change.len() - 1)];
            }
        }
        row
    }

    /// The units in use and every producer row, in the order `finish_rows` adds them up: buildings, then producing units.
    fn fill_rows(&self, c: &PlanetConfig, index: &ProdIndex, planet: &Planet, rows: &mut Vec<[f64; 4]>, in_use: &mut Vec<usize>) {
        rows.clear();
        in_use.clear();
        in_use.resize(c.unit_production.len(), 0);
        for &u in &index.units {
            in_use[u] = self.units_in_use(planet, u);
        }
        for &k in &index.producers {
            rows.push(self.producer_row(c, index, planet, in_use, k));
        }
        // Units that produce are producers like any building, counted at the number the host puts to use.
        for &u in &index.units {
            let n = in_use[u];
            if n == 0 { continue }
            if let Some(own) = c.unit_production[u].own.as_ref() { rows.push(own_row(own, n)) }
        }
    }

    /// Rates, energy and factor from the rows `fill_rows` made.
    fn finish_rows(&self, c: &PlanetConfig, index: &ProdIndex, planet: &Planet, rows: &[[f64; 4]], in_use: &[usize], full_factor: bool) -> ([f64; 3], f64, f64, f64) {
        let mut made = [0.0; 3];
        let (mut energy_max, mut energy_used) = (c.basic[3].max(0.0), (-c.basic[3]).max(0.0));
        for &row in rows {
            if row[3] > 0.0 { energy_max += row[3] } else { energy_used -= row[3] }
            for r in 0..3 { made[r] += row[r] }
        }
        for &u in &index.units {
            let n = in_use[u];
            if n == 0 { continue }
            let energy = &c.unit_production[u].energy;
            if energy.is_empty() { continue }
            let [produced, used] = energy[n.min(energy.len() - 1)];
            energy_max += produced;
            energy_used += used;
        }
        for &row in rows {
            if row[3] > 0.0 && row[2] < 0.0 && -row[2] > c.basic[2] + made[2] && planet.res[2] <= 0.0 {
                energy_max -= row[3];
            }
        }
        let (energy_max, energy_used) = (energy_max.trunc(), energy_used.trunc());
        let factor = match () {
            _ if full_factor || energy_used == 0.0 => 1.0,
            _ if energy_max <= 0.0 => 0.0,
            _ => (energy_max / energy_used * 100.0).floor().clamp(0.0, 100.0) / 100.0,
        };
        let rate = [0, 1, 2].map(|r| (c.basic[r] + made[r] * factor).ceil());
        (rate, energy_max, energy_used, factor)
    }

    /// Rates, energy and factor from the levels (PlanetService::updateResourceProductionStatsInner); with
    /// `full_factor` the factor is forced to 100%, the nominal output a player reads off the resource page.
    pub(crate) fn production(&self, planet: &Planet, full_factor: bool) -> ([f64; 3], f64, f64, f64) {
        let c = &self.planets[planet.config];
        let index = self.prod_index(c);
        // The rows and the units in use go into buffers the thread keeps: production runs for every candidate of
        // every decision.
        thread_local!(static ROWS: std::cell::RefCell<(Vec<[f64; 4]>, Vec<usize>)> = const { std::cell::RefCell::new((Vec::new(), Vec::new())) });
        let (mut rows, mut in_use) = ROWS.with(|cell| std::mem::take(&mut *cell.borrow_mut()));
        self.fill_rows(c, index, planet, &mut rows, &mut in_use);
        let out = self.finish_rows(c, index, planet, &rows, &in_use, full_factor);
        ROWS.with(|cell| *cell.borrow_mut() = (rows, in_use));
        out
    }

    /// The store caps the storage rows give at the planet's levels.
    fn caps(&self, planet: &Planet) -> [f64; 3] {
        let c = &self.planets[planet.config];
        let mut cap = [0.0; 3];
        for (k, rows) in c.storage.iter().enumerate() {
            let Some(rows) = rows else { continue };
            let row = rows[(planet.levels[k] as usize).min(rows.len() - 1)];
            for r in 0..3 { cap[r] += row[r] }
        }
        cap
    }

    /// For each object of `ks`, what `refresh` makes of the planet with that object one level up. The planet's rows are
    /// made once; each raise remakes only the rows that read the object's level (all of them when it moves a unit's
    /// bound), and the sums run over the rows in `production`'s order, so every result is `refresh`'s to the bit.
    pub(crate) fn raised(&self, p: &Planet, ks: &[usize]) -> Vec<Raised> {
        let c = &self.planets[p.config];
        let index = self.prod_index(c);
        let (mut rows, mut in_use) = (Vec::new(), Vec::new());
        self.fill_rows(c, index, p, &mut rows, &mut in_use);
        let (mut rows_k, mut in_use_k) = (Vec::with_capacity(rows.len()), Vec::new());
        let mut n = p.clone();
        ks.iter().map(|&k| {
            n.levels[k] += 1;
            let bound_moves = index.bounded_by[k].iter().any(|&u| self.units_in_use(&n, u) != in_use[u]);
            let (_, energy_max, energy_used, _) = if bound_moves {
                self.fill_rows(c, index, &n, &mut rows_k, &mut in_use_k);
                self.finish_rows(c, index, &n, &rows_k, &in_use_k, false)
            } else {
                rows_k.clear();
                rows_k.extend_from_slice(&rows);
                for &at in &index.dependents[k] {
                    rows_k[at] = self.producer_row(c, index, &n, &in_use, index.producers[at]);
                }
                self.finish_rows(c, index, &n, &rows_k, &in_use, false)
            };
            let cap = self.caps(&n);
            n.levels[k] -= 1;
            Raised { cap, energy_max, energy_used }
        }).collect()
    }

    pub fn refresh(&self, planet: &mut Planet) {
        let (rate, energy_max, energy_used, factor) = self.production(planet, false);
        let cap = self.caps(planet);
        (planet.rate, planet.cap, planet.energy_max, planet.energy_used, planet.factor) = (rate, cap, energy_max, energy_used, factor);
    }

    /// Accrual at the stored rates, each resource stopping at its cap (PlanetService::updateResourcesUntil). A stock
    /// never goes below zero here; the host has no floor, but no economy this table reaches burns more than it makes.
    pub(crate) fn accrue(&self, planet: &mut Planet, seconds: f64) {
        let hours = seconds / 3600.0;
        for r in 0..3 {
            let before = planet.res[r];
            if before < planet.cap[r] {
                planet.res[r] = as_stored(before + (planet.rate[r] * hours).min(planet.cap[r] - before).max(-before));
            }
            // Row 23: the rate's output is booked whether or not the store took it, and what it spilled is `wasted`.
            let ideal = planet.rate[r] * hours;
            let gained = planet.res[r] - before;
            planet.books.produced += WEIGHTS[r] * ideal;
            planet.books.wasted += WEIGHTS[r] * (ideal - gained);
        }
    }

    /// Row 42: the first unmet prerequisite of object k, as its host id - the census names what blocks a `needs`.
    pub fn needs_first(&self, planet: &Planet, k: usize) -> Option<i64> {
        self.objects[k].requires.iter().filter(|(target, level)| target.map_or(*level != 0, |i| planet.levels[i] < *level))
            .find_map(|(target, _)| target.map(|i| self.objects[i].id))
    }

    /// Row 42: the first resource whose price for object k is above this planet's store cap - the reason a price can
    /// never be paid, which the census names by the resource's index.
    pub fn unaffordable_first(&self, planet: &Planet, k: usize) -> Option<usize> {
        let price = self.objects[k].price[planet.levels[k] as usize];
        (0..3).find(|&r| price[r] > planet.cap[r])
    }

    /// Row 36: the fields this planet has - its own plus what its buildings add, which is what the host counts
    /// (`PlanetService::getPlanetFieldMax`). A catalogue without `fields_added` adds nothing, so old tables read as before.
    pub fn field_cap(&self, planet: &Planet) -> u32 {
        let base = planet.field_max;
        self.objects.iter().zip(&planet.levels).fold(base, |cap, (object, &level)| {
            cap + object.fields_added.get(level as usize).copied().unwrap_or(0)
        })
    }

    pub fn fields_used(&self, planet: &Planet) -> u32 {
        self.objects.iter().zip(&planet.levels).filter(|(o, _)| o.consumes_field).map(|(_, l)| *l).sum()
    }

    fn requirements_met(&self, levels: &[u32], k: usize) -> bool {
        self.objects[k].requires.iter().all(|(target, level)| match target {
            Some(i) => levels[*i] >= *level,
            None => *level == 0,
        })
    }

    /// What BuildingQueueService::add accepts, plus a price the planet can ever pay at today's stores and rates.
    pub fn legal(&self, planet: &Planet, k: usize) -> bool {
        self.stall_of(planet, k) == "ok"
    }

    /// Whether the host lets object k stand on this planet's type (a moon or a planet).
    pub fn stands_on(&self, planet: &Planet, k: usize) -> bool {
        if self.planets[planet.config].moon { self.objects[k].on_moon } else { self.objects[k].on_planet }
    }

    /// Row 29: why object k cannot start on this planet, in `legal`'s own terms - the diagnosis reads this instead of
    /// asking the same four questions twice.
    pub fn stall_of(&self, planet: &Planet, k: usize) -> &'static str {
        let o = &self.objects[k];
        if !self.stands_on(planet, k) { return "wrong type" }
        if planet.levels[k] as usize >= o.price.len() { return "max level" }
        if !self.requirements_met(&planet.levels, k) { return "needs" }
        if self.ceiling.get(k).copied().flatten().is_some_and(|c| planet.levels[k] >= c) { return "past need" }
        if o.consumes_field && self.fields_used(planet) >= self.field_cap(planet) { return "no fields" }
        // ObjectService: a building reads the energy the planet makes, research what it has free. Neither is spent.
        let energy = if o.queue == 0 { planet.energy_max } else { planet.energy_max - planet.energy_used };
        if o.energy_price.get(planet.levels[k] as usize).is_some_and(|&need| energy < need) { return "no energy" }
        if self.wait_seconds(planet, k) >= NEVER { return "never affordable" }
        "ok"
    }

    /// Plays the player rule for every queue but `controlled` until the controlled queue decides again (or the game ends);
    /// returns the value those steps made.
    pub fn play_others(&self, game: &mut Game, controlled: usize, rng: &mut u64) -> f64 {
        let mut made = 0.0;
        while !game.done && controlled < 2 && game.queue != controlled {
            let Some(k) = self.baseline(&game.planet, game.queue, Baseline::Player, rng) else { break };
            made += self.step(game, k);
        }
        made
    }

    /// Whole seconds until the stock covers the next level's price; NEVER when a store is too small or a rate is zero.
    pub fn wait_seconds(&self, planet: &Planet, k: usize) -> f64 {
        let price = self.objects[k].price[planet.levels[k] as usize];
        let mut wait: f64 = 0.0;
        for r in 0..3 {
            if price[r] <= planet.res[r] { continue }
            if price[r] > planet.cap[r] || planet.rate[r] <= 0.0 { return NEVER }
            wait = wait.max((price[r] - planet.res[r]) / planet.rate[r] * 3600.0);
        }
        wait.ceil()
    }

    /// Host build time (PlanetService::getBuildingConstructionTime), read from the host's grid. The host rounds
    /// after dividing, so dividing its whole seconds drifts by a second; the divisor is only the fallback beyond
    /// the grid, applied to the time with every station at zero except the object's own level.
    pub fn build_seconds(&self, planet: &Planet, k: usize) -> f64 {
        let seconds = self.table_seconds(planet, k);
        if self.objects[k].queue == 0 { return seconds }
        let factor = self.planets[planet.config].character_class.and_then(|c| self.research_time_multipliers.get(&c)).copied().unwrap_or(1.0);
        // The host truncates after the class's factor and again after the officer's (PlanetService::getTechnologyResearchTime).
        let seconds = (seconds * factor).floor();
        let seconds = if planet.research_multiplier != 1.0 { (seconds * planet.research_multiplier).floor() } else { seconds };
        seconds.max(1.0)
    }

    fn table_seconds(&self, planet: &Planet, k: usize) -> f64 {
        let level = planet.levels[k] as usize;
        let side = self.time_grid_level + 1;
        let queue = self.objects[k].queue;
        let pooled = if queue == 1 { self.network_labs(planet, k) } else { 0 };
        // The research network adds to the first research modifier, the lab the host pools.
        let level_of = |i: usize, m: usize| (planet.levels[m] + if i == 0 { pooled } else { 0 }) as usize;
        let cell = self.modifiers[queue].iter().enumerate().try_fold(0usize, |at, (i, (m, _))| {
            let l = level_of(i, *m);
            (l < side).then(|| at * side + l)
        });
        if let Some(cell) = cell {
            return self.objects[k].build_seconds_grid[level][cell];
        }
        let mut seconds = self.objects[k].build_seconds[planet.levels[k] as usize];
        for (i, (m, divisor)) in self.modifiers[queue].iter().enumerate() {
            if *m != k {
                // A pooled lab outgrows the exported table; the divisor keeps its last step beyond it (1 + level for a lab).
                let (l, n) = (level_of(i, *m), divisor.len());
                seconds /= if l < n || n < 2 { divisor[l.min(n - 1)] } else { divisor[n - 1] + (l - (n - 1)) as f64 * (divisor[n - 1] - divisor[n - 2]) };
            }
        }
        seconds.floor().max(1.0)
    }

    /// PlanetService::getResearchNetworkLabLevel: at network level n, the n best labs of the account's other planets
    /// that meet research k's requirements.
    fn network_labs(&self, planet: &Planet, k: usize) -> u32 {
        let (Some(network), Some(&(lab, _))) = (self.research_network, self.modifiers[1].first()) else { return 0 };
        let n = planet.levels[network] as usize;
        if n == 0 { return 0 }
        let mut labs: Vec<u32> = planet.peers.iter().filter(|p| self.requirements_met(p, k)).map(|p| p[lab]).collect();
        labs.sort_unstable_by(|x, y| y.cmp(x));
        labs.into_iter().take(n).sum()
    }

    /// Lands every build that ends before `until` (at it too when `landing`; the horizon is a cliff for a build that ends on it), in order, accruing up to each end, then accrues up to `until`.
    pub(crate) fn advance_to(&self, game: &mut Game, until: f64, landing: bool) {
        while let Some(&(end, k)) = game.pending.first().filter(|(end, _)| if landing { *end <= until } else { *end < until }) {
            game.pending.remove(0);
            self.accrue(&mut game.planet, end - game.t);
            game.t = end;
            game.planet.levels[k] += 1;
            self.refresh(&mut game.planet);
        }
        self.accrue(&mut game.planet, until - game.t);
        game.t = until;
    }

/// The first login at or after `x` (strictly after with `later`); with no gap table the account is always there.
/// The shortest gap between two actions the host does not flag (`HostDetector`: under ten seconds reads as a script).

/// The first second at or after `t` outside the account's night: a player sleeps, and the host flags a dark period under
/// six hours as much as it flags twenty-four hours of activity.

    pub fn login(&self, game: &mut Game, x: f64, later: bool) -> f64 {
        if self.login_gaps.is_empty() {
            return x;
        }
        if game.login_times.is_empty() {
            game.login_times.push(0.0);
        }
        while game.login_times.last().is_some_and(|&last| if later { last <= x } else { last < x }) {
            let gap = self.login_gaps[(next_random(&mut game.login_rng) % self.login_gaps.len() as u64) as usize];
            let last = *game.login_times.last().unwrap();
            game.login_times.push(awake(game, last + (gap * game.pace).max(MIN_GAP)));
        }
        let at = game.login_times.partition_point(|&t| if later { t <= x } else { t < x });
        game.login_times[at]
    }

    /// The session a login opens: uniform 5 to 20 minutes. An assumption, not a measurement - the host's own activity
    /// logs are not in the bands this project holds - but the only way a player places several builds in one sitting,
    /// since the host's building queue holds one item (row 12).
    fn open_session(&self, game: &mut Game) {
        let mut seed = game.login_rng ^ game.t.to_bits();
        let minutes = 5.0 + (next_random(&mut seed) % 16) as f64;
        game.session_end = game.t + minutes * 60.0;
    }

    fn finish(&self, game: &mut Game) {
        // A world event (a fleet landing, a colony founded) may have carried a retired account's planet past its horizon.
        self.advance_to(game, game.horizon.max(game.t), false);
        game.done = true;
    }

    pub fn queue_legal(&self, game: &Game, k: usize) -> bool {
        self.objects[k].queue == game.queue && self.legal(&game.planet, k) && self.held_until(game, k).is_none()
    }

    /// W14: when the host would let object k start again, if it may not start now. The host refuses to upgrade the object
    /// that sets research time while a research runs, and to start a research while that object upgrades
    /// (`BuildingQueueService::start`, `ResearchQueueService::start`); which object that is, the research time modifiers
    /// say (gate 1), as the yard's own block reads the unit time modifiers. Both checks are the player's, so the account's
    /// other planets count (`PlayerService::isResearching`, `isBuildingObject`).
    pub fn held_until(&self, game: &Game, k: usize) -> Option<f64> {
        let blocks = |j: usize| match self.objects[k].queue {
            0 => self.sets_research_time(k) && self.objects[j].queue == 1,
            _ => self.sets_research_time(j),
        };
        game.pending.iter().chain(game.account_pending.iter().filter(|p| p.0 > game.t))
            .filter(|&&(_, j)| blocks(j)).map(|&(end, _)| end).reduce(f64::max)
    }

    /// Whether object k is one the research time modifiers read: a build of it or a research holds the other.
    pub fn sets_research_time(&self, k: usize) -> bool {
        self.modifiers[1].iter().any(|&(m, _)| m == k)
    }

    /// Runs the clock until a queue without a plan has something it can build and must decide (it becomes `game.queue`),
    /// or the game ends.
    fn next_decision(&self, game: &mut Game) {
        while !self.tick(game) {}
    }

    /// When the account's clock moves next and which queue it moves for (None: a build lands first, or the horizon).
    fn next_event(&self, game: &mut Game) -> (f64, Option<usize>) {
        let mut best = (NEVER, None);
        for q in 0..2 {
            if !self.objects.iter().any(|o| o.queue == q) { continue }
            let when = match game.commit[q] {
                // A commit the host holds (W14) starts no sooner than the build that blocks it lands.
                Some(k) => (game.t + self.wait_seconds(&game.planet, k)).max(self.held_until(game, k).unwrap_or(0.0)),
                // `NEVER` is a queue the host has nothing left to decide on (`tick` set it: no legal object and
                // nothing building), so it has no next event; reading a login time for it would spin the clock.
                None if game.free[q] == NEVER => continue,
                None => {
                    // A login is a session, not an instant (row 12): inside it the account returns the moment a queue
                    // frees, and never sooner than the host's floor after its own last action. A login that is already
                    // due decides now - that is what opens the session - and one beyond the session waits for the next.
                    let free = game.free[q];
                    let back = free.max(game.last_action + MIN_GAP);
                    if free <= game.t || back < game.session_end { back.max(game.t) } else { self.login(game, back, false) }
                }
            };
            if when < best.0 { best = (when, Some(q)) }
        }
        // Row 47: in a world an account is not retired for having nothing to build. With both queues asleep, nothing
        // landing and nothing committed, the next event is the account's own next login: `tick` moves the clock there
        // and wakes the queues to ask again (stock, research and fields may have moved since), so production and waste
        // are booked on the day they happen instead of at once at the horizon. The horizon still ends the game; the
        // economy-only `VecEnv` keeps `finish`, where a finished game is the end of an episode.
        if game.in_world && best.1.is_none() && game.commit.iter().all(|k| k.is_none())
            && game.pending.is_empty() && !self.login_gaps.is_empty() {
            return (self.login(game, game.t + MIN_GAP, false).min(game.horizon.max(game.t)), None);
        }
        // A landing build changes rates and stores, so a plan made before it is re-timed after it. A clock a world event
        // carried past the horizon ends where it is: the horizon behind it would run it back (and un-produce its stock).
        let horizon = game.horizon.max(game.t);
        match game.pending.first() {
            Some(&(end, _)) if end < best.0.min(horizon) => (end, None),
            _ if best.0 >= horizon => (horizon, None),
            _ => best,
        }
    }

    /// The second this account's clock moves next, for a world that interleaves many accounts on one clock.
    pub fn peek(&self, game: &mut Game) -> f64 {
        if game.done { return NEVER }
        self.next_event(game).0
    }

    /// Moves the clock by one event: a build lands, a committed build starts the moment its price is covered, or a
    /// free queue's login comes. Returns true when a queue must decide or the game is over. A queue with nothing to do
    /// sleeps until the next build lands, which may unlock something; with nothing pending it sleeps for good, and the
    /// game ends when every queue does.
    pub fn tick(&self, game: &mut Game) -> bool {
        // A commit the same login spent away reads as `decide` does: the account logs off. Waiting on it could outlast the
        // season, and the clock went with it.
        // The clock stays put: the next login is later than the world scheduled this slot for, and a fleet landing on it in
        // between must find it at its own second (a slot that jumped ahead met raids with future stock).
        if !self.login_gaps.is_empty() {
            let mut dropped = false;
            for q in 0..2 {
                if game.commit[q].is_some_and(|k| self.wait_seconds(&game.planet, k) > 0.0) {
                    game.commit[q] = None;
                    game.free[q] = self.login(game, game.t, true);
                    dropped = true;
                }
            }
            if dropped { return false }
        }
        let (at, next) = self.next_event(game);
        let Some(q) = next else {
            if at >= game.horizon {
                self.finish(game);
                return true;
            }
            self.advance_to(game, at, true);
            // A build landing changes what is legal, so a queue that found nothing to do wakes for another look: the host
            // asks again at the account's next login, and without this the research queue of an account whose lab arrives
            // later is asleep for good (measured: 10 of 12 scripted accounts never researched in ten days).
            for q in 0..2 {
                if game.free[q] == NEVER && self.objects.iter().any(|o| o.queue == q) { game.free[q] = game.t }
            }
            return false;
        };
        self.advance_to(game, at, true);
        if let Some(k) = game.commit[q] {
            if self.wait_seconds(&game.planet, k) > 0.0 {
                self.advance_to(game, game.t + 1.0, true);
                return false;
            }
            self.start(game, q, k);
            return false;
        }
        // Landing on a login opens a session; landing inside one keeps it, so a build that ends brings the account back.
        if game.t >= game.session_end {
            self.open_session(game);
        }
        game.queue = q;
        if (0..self.objects.len()).any(|k| self.queue_legal(game, k)) {
            return true;
        }
        // A hold from a build on another planet ends without landing here: read it, or the queue sleeps while the others run.
        let held = (0..self.objects.len()).filter(|&k| self.objects[k].queue == q).filter_map(|k| self.held_until(game, k));
        game.free[q] = game.pending.first().map(|p| p.0).into_iter().chain(held).fold(NEVER, f64::min);
        if game.free[q] >= game.horizon {
            game.free[q] = NEVER;
        }
        false
    }

    /// Pays for object k and puts it in queue q's slot.
    fn start(&self, game: &mut Game, q: usize, k: usize) {
        let stock = game.planet.res;
        let price = self.objects[k].price[game.planet.levels[k] as usize];
        for r in 0..3 { game.planet.res[r] -= price[r] }
        game.planet.settle();
        // Row 23: a building and a research are paid out of the stock, each in its own field.
        let paid = weighted(price);
        match q { 0 => game.planet.books.spent_build += paid, _ => game.planet.books.spent_research += paid }
        game.invested += weighted(price);
        let start = game.t;
        game.last_action = start;
        let end = start + self.build_seconds(&game.planet, k);
        game.history.push((start, k, end, stock));
        let at = game.pending.partition_point(|p| p.0 <= end);
        game.pending.insert(at, (end, k));
        game.free[q] = end;
        game.commit[q] = None;
    }

    /// The deciding queue lets this login pass without starting anything and decides again at its next login.
    pub fn wait_turn(&self, game: &mut Game) {
        self.decide(game, None);
        self.next_decision(game);
    }

    /// Commits the free queue to object k and runs the clock to the next decision. The reward is the change in value
    /// (invested plus held, metal-equivalent), which is what the planet produced; the last step adds `bonus_hours` of
    /// the production left running, so the end of the horizon is not a cliff.
    pub fn step(&self, game: &mut Game, k: usize) -> f64 {
        let before = game.value();
        if game.trace.is_some() {
            let (mut state, mut cands) = (vec![0f32; STATE_DIM], vec![0f32; self.objects.len() * CAND_DIM]);
            self.state_features(&game.planet, &mut state);
            for j in 0..self.objects.len() {
                self.candidate_features(&game.planet, j, &mut cands[j * CAND_DIM..(j + 1) * CAND_DIM]);
            }
            let legal = (0..self.objects.len()).map(|j| u8::from(self.queue_legal(game, j))).collect();
            let at = game.t;
            game.trace.as_mut().unwrap().push((at, state, cands, legal, k));
        }
        self.decide(game, Some(k));
        self.next_decision(game);
        let bonus = if game.done { game.bonus_hours * game.effective_rate() } else { 0.0 };
        game.value() - before + bonus
    }

    /// The deciding queue's answer without moving the clock: commit to k, or (None) let this login pass. An account
    /// with logins that cannot pay for k now logs off instead, and the queue stays empty until its next login.
    pub fn decide(&self, game: &mut Game, pick: Option<usize>) {
        let queue = game.queue;
        match pick {
            Some(k) if self.login_gaps.is_empty() || self.wait_seconds(&game.planet, k) <= 0.0 => {
                game.commit[queue] = Some(k);
                game.last_action = game.t;
            }
            _ => game.free[queue] = self.login(game, game.t, true),
        }
    }

    /// EconomyChoiceEncoder::account (speed) and ::planetState, field for field, in STATE_NAMES order.
    pub fn state_features(&self, p: &Planet, out: &mut [f32]) {
        let c = &self.planets[p.config];
        let fill = |r: usize| if p.cap[r] > 0.0 { (p.res[r] / p.cap[r]).min(1.0) as f32 } else { 0.0 };
        let fields_max = c.field_max.max(1) as f64;
        let values = [
            self.speed.max(1.0).ln() as f32,
            ln1p(p.res[0]), ln1p(p.res[1]), ln1p(p.res[2]), fill(0), fill(1), fill(2),
            signed_log(p.rate[0]), signed_log(p.rate[1]), signed_log(p.rate[2]),
            ln1p(p.energy_max), ln1p(p.energy_used), p.factor as f32, (c.temperature / 100.0) as f32,
            (self.fields_used(p) as f64 / fields_max).min(1.0) as f32, (fields_max / 200.0) as f32, (c.position / 15.0) as f32,
        ];
        out.copy_from_slice(&values);
    }

    /// Hours until the planet's own production covers the price (EconomyChoiceEncoder::etaHours); 0 when it can.
    fn eta_hours(&self, planet: &Planet, price: [f64; 3]) -> f64 {
        (0..3).filter(|&r| price[r] > planet.res[r])
            .map(|r| if planet.rate[r] > 0.0 { (price[r] - planet.res[r]) / planet.rate[r] } else { FAR_HOURS })
            .fold(0.0, f64::max).min(FAR_HOURS)
    }

    /// EconomyChoiceEncoder::candidateFeatures for the next level of object k, in CAND_NAMES order. The gain, energy
    /// and storage columns read the host's own per-level tables, as the encoder reads the host's per-level answers.
    pub fn candidate_features(&self, p: &Planet, k: usize, out: &mut [f32]) {
        let c = &self.planets[p.config];
        let o = &self.objects[k];
        let level = p.levels[k] as usize;
        if level >= o.price.len() {
            out.fill(0.0);
            return;
        }
        let price = o.price[level];
        let cost: f64 = price.iter().sum();
        let production_per_hour = (p.rate[0] + p.rate[1] + p.rate[2].max(0.0)).max(1.0);
        let stock = (p.res.iter().sum::<f64>()).max(1.0);
        let (gain, energy) = self.production_row(p, k, level).zip(self.production_row(p, k, level + 1)).map_or((0.0, 0.0), |(now, next)| {
            ((next[0] + next[1] + next[2]) - (now[0] + now[1] + now[2]), next[3] - now[3])
        });
        let storage = c.storage[k].as_ref().map_or(0.0, |rows| {
            let sum = |l: usize| rows[l.min(rows.len() - 1)].iter().sum::<f64>();
            (sum(level + 1) - sum(level)).max(0.0)
        });
        let values = [
            ln1p(price[0]), ln1p(price[1]), ln1p(price[2]),
            ln1p(cost / production_per_hour), (cost / stock).min(10.0) as f32 / 10.0,
            ln1p(self.build_seconds(p, k) / 3600.0), level as f32 / 40.0,
            signed_log(gain), ln1p(if gain > 0.0 { (cost / gain).min(FAR_HOURS) } else { FAR_HOURS }),
            signed_log(energy), ln1p(storage), (o.unlocks / 10.0) as f32, ln1p(self.eta_hours(p, price)),
        ];
        out.copy_from_slice(&values);
    }

    /// The deterministic players the model must beat. Payback is the classic rule: power first when the factor is
    /// short, else the next level that repays its price fastest, a store when no producer fits, else the cheapest.
    pub fn baseline(&self, p: &Planet, queue: usize, kind: Baseline, rng: &mut u64) -> Option<usize> {
        let mut legal: Vec<usize> = (0..self.objects.len()).filter(|&k| self.objects[k].queue == queue && self.legal(p, k)).collect();
        // An account that logs in only now and then builds what it can pay at the visit; it never spends a visit on saving up.
        if !self.login_gaps.is_empty() && legal.iter().any(|&k| self.wait_seconds(p, k) == 0.0) {
            legal.retain(|&k| self.wait_seconds(p, k) == 0.0);
        }
        if legal.is_empty() {
            return None;
        }
        let cost = |k: usize| weighted(self.objects[k].price[p.levels[k] as usize]);
        let min_by = |keys: Vec<(usize, f64)>| keys.into_iter().min_by(|a, b| a.1.total_cmp(&b.1)).map(|(k, _)| k);
        match kind {
            Baseline::Random => Some(legal[(next_random(rng) % legal.len() as u64) as usize]),
            Baseline::Soonest => min_by(legal.iter().map(|&k| (k, self.wait_seconds(p, k) * 1e6 + cost(k))).collect()),
            Baseline::Player => {
                // Each legal object one level up, refreshed once on a scratch copy: the payback pick and the stores read it.
                let afters = self.raised(p, &legal);
                let pick = self.payback(p, &legal, &afters)?;
                let busy_hours = (self.wait_seconds(p, pick) + self.build_seconds(p, pick)) / 3600.0;
                let overflow = (0..3).filter(|&r| p.res[r] + p.rate[r].max(0.0) * busy_hours >= p.cap[r]).collect::<Vec<_>>();
                let store = legal.iter().zip(&afters).filter_map(|(&k, n)| {
                    let relief: f64 = overflow.iter().map(|&r| n.cap[r] - p.cap[r]).sum();
                    (relief > 0.0).then(|| (k, -relief / cost(k)))
                }).collect::<Vec<_>>();
                min_by(store).or(Some(pick))
            }
            Baseline::Payback => {
                let afters = self.raised(p, &legal);
                self.payback(p, &legal, &afters)
            }
        }
    }

    /// For each object of `legal`, what `refresh` makes of the planet with that object one level up.
    /// `Baseline::Payback` over `legal`, with `afters` from `raised`.
    fn payback(&self, p: &Planet, legal: &[usize], afters: &[Raised]) -> Option<usize> {
        let cost = |k: usize| weighted(self.objects[k].price[p.levels[k] as usize]);
        let min_by = |keys: Vec<(usize, f64)>| keys.into_iter().min_by(|a, b| a.1.total_cmp(&b.1)).map(|(k, _)| k);
        let nominal = |q: &Planet| weighted(self.production(q, true).0);
        let power: Vec<(usize, f64)> = legal.iter().zip(afters).filter_map(|(&k, n)| {
            let delta = (n.energy_max - n.energy_used) - (p.energy_max - p.energy_used);
            (delta > 0.0).then(|| (k, cost(k) / delta))
        }).collect();
        if p.factor < 1.0 && !power.is_empty() {
            return min_by(power);
        }
        let now = nominal(p);
        let mut n = p.clone();
        let producers: Vec<(usize, f64)> = legal.iter().filter_map(|&k| {
            n.levels[k] += 1;
            let gain = nominal(&n) - now;
            n.levels[k] -= 1;
            (gain > 0.0).then(|| (k, cost(k) / gain))
        }).collect();
        if !producers.is_empty() {
            return min_by(producers);
        }
        let stores: Vec<(usize, f64)> = legal.iter().zip(afters).filter_map(|(&k, n)| {
            let gain = weighted(n.cap) - weighted(p.cap);
            (gain > 0.0).then(|| (k, -gain / cost(k)))
        }).collect();
        if !stores.is_empty() {
            return min_by(stores);
        }
        min_by(legal.iter().map(|&k| (k, cost(k))).collect())
    }
}

/// What `refresh` makes of a planet raised one level (`Catalogue::raised`).

impl Game {
    pub fn value(&self) -> f64 {
        self.invested + weighted(self.planet.res)
    }

    /// Metal-equivalent output per hour that still lands somewhere (a full store makes nothing).
    pub fn effective_rate(&self) -> f64 {
        (0..3).map(|r| if self.planet.res[r] < self.planet.cap[r] { WEIGHTS[r] * self.planet.rate[r] } else { 0.0 }).sum()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// M2: an object stands only where the host lets it: a planet's own object is refused on a moon, a moon's own on a
    /// planet, research on a moon (no lab stands there), and a moon's own object starts on a moon that can pay for it.
    #[test]
    fn an_object_stands_only_on_the_planet_types_the_host_allows() {
        let path = format!("{}/../../../../storage/rl/ogsim/units-parity/econ-u1-merged.json", env!("CARGO_MANIFEST_DIR"));
        let Ok(cat) = Catalogue::load(&path) else { return eprintln!("no merged moon catalogue; skipped") };
        let moon = (0..cat.planets.len()).find(|&c| cat.planets[c].moon).expect("a moon config");
        let planet_only = (0..cat.objects.len()).find(|&k| cat.objects[k].queue == 0 && !cat.objects[k].on_moon).expect("a planet's own object");
        let moon_only = (0..cat.objects.len()).find(|&k| cat.objects[k].requires.is_empty() && !cat.objects[k].on_planet).expect("a moon's own object");
        let research = (0..cat.objects.len()).find(|&k| cat.objects[k].queue == 1).expect("a research");
        let mut on_moon = cat.new_paused_game(moon, 1.0, 0.0, 0).planet;
        on_moon.res = [1e9; 3];
        cat.refresh(&mut on_moon);
        let mut on_planet = cat.new_paused_game(0, 1.0, 0.0, 0).planet;
        on_planet.res = [1e9; 3];
        cat.refresh(&mut on_planet);
        assert_eq!(cat.stall_of(&on_moon, planet_only), "wrong type");
        assert_eq!(cat.stall_of(&on_moon, research), "wrong type");
        assert_eq!(cat.stall_of(&on_planet, moon_only), "wrong type");
        assert_eq!(cat.stall_of(&on_moon, moon_only), "ok");
    }

    /// The host's own answer at level patterns and unit counts its tables are not tabulated at, so the module is judged
    /// on how it combines those tables, which is the part of production no table can hold.
    #[test]
    fn composes_the_unit_tables_as_the_host_does() {
        let path = format!("{}/../../../../storage/rl/ogsim/units-parity/econ-u1.json", env!("CARGO_MANIFEST_DIR"));
        let Ok(cat) = Catalogue::load(&path) else { return eprintln!("no u1 catalogue; skipped") };
        assert!(!cat.probes.is_empty(), "the catalogue carries no unit probes");
        for probe in &cat.probes {
            let mut planet = cat.new_paused_game(probe.config, 1.0, 0.0, 0).planet;
            planet.levels = probe.levels.clone();
            planet.units = probe.units.clone();
            cat.refresh(&mut planet);
            let where_ = format!("config {} holding {:?}", probe.config, probe.units);
            let got = [planet.rate[0], planet.rate[1], planet.rate[2], planet.energy_max, planet.energy_used, planet.factor];
            let want = [probe.answer[0], probe.answer[1], probe.answer[2], probe.answer[3], probe.answer[4], probe.answer[5] / 100.0];
            assert_eq!(got, want, "rates, energy and factor at {where_}");
        }
        eprintln!("{} probe(s) of levels and unit counts match the host", cat.probes.len());
        // Every probe above would also pass on code that ignored the unit tables if no probe held a unit, so at least one
        // config must answer differently once its planet holds some.
        let differs = cat.probes.iter().any(|held| {
            let zero = cat.probes.iter().find(|p| p.config == held.config && p.units.iter().all(|&n| n == 0));
            held.units.iter().any(|&n| n > 0) && zero.is_some_and(|zero| zero.answer != held.answer)
        });
        assert!(differs, "no probe showed a unit changing its planet's production");
    }

    /// Row 36: a planet's field cap is the host's own count - its fields plus what its buildings add, read from the table
    /// the export probed rather than from a number the simulator keeps. The bare planet's cap is its own fields, and one
    /// and two levels of the object that adds the most raise it by exactly the table's values.
    /// PlanetService::getResearchNetworkLabLevel: at network level n a research is timed as if the lab held its own
    /// level plus the n best labs of the other planets that meet the research's requirements; a peer that does not
    /// meet them adds nothing, and without the network the peers add nothing.
    #[test]
    fn the_research_network_pools_the_best_labs_of_the_other_planets() {
        let path = format!("{}/../../../../storage/rl/ogsim/units-parity/econ-u1-colonies.json", env!("CARGO_MANIFEST_DIR"));
        let Ok(cat) = Catalogue::load(&path) else { return eprintln!("no colonies catalogue; skipped") };
        let Some(network) = cat.research_network else { return eprintln!("no research network in the export; skipped") };
        let lab = cat.modifiers[1][0].0;
        let mut planet = cat.new_paused_game(0, 1.0, 0.0, 0).planet;
        planet.levels.iter_mut().for_each(|l| *l = 10);
        let k = (0..cat.objects.len()).find(|&k| cat.objects[k].queue == 1 && k != network).expect("a research");
        let peer = |lab_level: u32| { let mut levels = planet.levels.clone(); levels[lab] = lab_level; levels };
        let mut unmet = peer(30);
        unmet.iter_mut().enumerate().filter(|(i, _)| cat.objects[k].requires.iter().any(|r| r.0 == Some(*i))).for_each(|(_, l)| *l = 0);
        let any_requirement = cat.objects[k].requires.iter().any(|r| r.0.is_some() && r.1 > 0);
        planet.peers = std::sync::Arc::new(vec![peer(5), peer(3), peer(8), unmet]);
        planet.levels[network] = 2;
        let mut alone = planet.clone();
        alone.peers = Default::default();
        // A research with no requirement lets every peer qualify, the unmet one's 30 among them.
        alone.levels[lab] = 10 + if any_requirement { 8 + 5 } else { 30 + 8 };
        assert_eq!(cat.build_seconds(&planet, k), cat.build_seconds(&alone, k), "the two best qualifying labs are pooled");
        planet.levels[network] = 0;
        let mut own = planet.clone();
        own.peers = Default::default();
        assert_eq!(cat.build_seconds(&planet, k), cat.build_seconds(&own, k), "without the network the peers add nothing");
    }

    /// W14: the host refuses to upgrade the object that sets research time while a research runs, and to start a
    /// research while that object upgrades; both wait for the other to land.
    #[test]
    fn the_lab_and_research_wait_for_each_other() {
        let path = format!("{}/../../../../storage/rl/ogsim/units-parity/econ-u1-colonies.json", env!("CARGO_MANIFEST_DIR"));
        let Ok(cat) = Catalogue::load(&path) else { return eprintln!("no colonies catalogue; skipped") };
        let lab = cat.modifiers[1][0].0;
        let research = (0..cat.objects.len()).find(|&k| cat.objects[k].queue == 1).expect("a research");
        let other = (0..cat.objects.len()).find(|&k| cat.objects[k].queue == 0 && k != lab).expect("another building");
        let mut game = cat.new_paused_game(0, 1.0, 0.0, 0);
        assert_eq!(cat.held_until(&game, lab), None, "nothing runs, nothing waits");
        game.pending = vec![(500.0, research)];
        assert_eq!(cat.held_until(&game, lab), Some(500.0), "the lab waits for the research");
        assert_eq!(cat.held_until(&game, other), None, "another building does not");
        game.pending = vec![(700.0, lab)];
        assert_eq!(cat.held_until(&game, research), Some(700.0), "a research waits for the lab");
        game.pending.clear();
        game.account_pending = vec![(900.0, research)];
        assert_eq!(cat.held_until(&game, lab), Some(900.0), "a colony's lab waits for the account's research");
        game.t = 900.0;
        assert_eq!(cat.held_until(&game, lab), None, "and not once it has landed");
    }

    #[test]
    fn a_field_adding_object_raises_the_planets_field_cap() {
        let path = format!("{}/../../../../storage/rl/ogsim/units-parity/econ-u1-colonies.json", env!("CARGO_MANIFEST_DIR"));
        let Ok(cat) = Catalogue::load(&path) else { return eprintln!("no colonies catalogue; skipped") };
        let base = cat.planets[0].field_max;
        let mut planet = cat.new_paused_game(0, 1.0, 0.0, 0).planet;
        assert_eq!(cat.field_cap(&planet), base, "a bare planet's cap is not its own fields");
        let (k, added) = cat.objects.iter().enumerate()
            .map(|(k, o)| (k, o.fields_added.get(2).copied().unwrap_or(0)))
            .max_by_key(|&(_, added)| added).expect("a building object");
        assert!(added > 0, "the catalogue carries no fields_added column");
        planet.levels[k] = 1;
        assert_eq!(cat.field_cap(&planet), base + cat.objects[k].fields_added[1], "one level of the field-adding object");
        planet.levels[k] = 2;
        assert_eq!(cat.field_cap(&planet), base + added, "two levels of the field-adding object");
    }

    /// A login is a session, not an instant (row 12). On the first day a build takes seconds, so one login must place
    /// several of them; no queue may start two builds closer together than the host's floor after the account's own
    /// last action, and the session itself must open at a login.
    #[test]
    fn a_login_is_a_session_not_an_instant() {
        let path = format!("{}/../../../../storage/rl/ogsim/units-parity/econ-u1.json", env!("CARGO_MANIFEST_DIR"));
        let Ok(mut cat) = Catalogue::load(&path) else { return eprintln!("no u1 catalogue; skipped") };
        cat.login_gaps = vec![7_200.0]; // logins two hours apart: everything inside the first 20 minutes is one session
        let mut game = cat.new_game(0, 6.0, 0.0, 7);
        let mut pick = 7u64;
        while !game.done {
            match cat.baseline(&game.planet, game.queue, Baseline::Player, &mut pick) {
                Some(k) => { cat.step(&mut game, k); }
                None => cat.wait_turn(&mut game),
            }
        }
        let starts: Vec<f64> = game.history.iter().map(|h| h.0).collect();
        // The session is opened by the first login, which need not be at t=0, so the window starts at the first build.
        let first = *starts.first().expect("the account built something");
        let inside = starts.iter().filter(|&&s| s < first + 1_200.0).count();
        assert!(inside >= 2, "one login should place several seconds-long builds, not {inside}: {starts:?}");
        assert!(game.login_times.iter().any(|&t| (t - first).abs() < 1e-9), "the first build starts at a login: {first} in {:?}", game.login_times);
        for pair in starts.windows(2) {
            assert!(pair[1] - pair[0] >= MIN_GAP - 1e-6, "builds {} s apart, under the host's floor: {pair:?}", pair[1] - pair[0]);
        }
    }
}
