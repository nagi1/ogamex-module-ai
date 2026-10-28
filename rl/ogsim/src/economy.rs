//! One planet's economy: production, stores, the building and research queues and the logins that refill them.
use serde::Deserialize;
use std::collections::HashMap;


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
    field_max: u32,
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
}

#[derive(Clone)]
pub struct PlanetConfig {
    /// The host's planet id, which the mission cases address planets by.
    pub planet_id: i64,
    pub position: f64,
    pub temperature: f64,
    pub field_max: u32,
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
    pub planets: Vec<PlanetConfig>,
    /// Host ids of the units that move production; `Planet::units` and the planet tables follow this order.
    pub units: Vec<i64>,
    /// The host's own answers at levels and unit counts its tables are not tabulated at (`ai:rl-catalogue`).
    pub probes: Vec<Probe>,
    /// Seconds between one login of an account and the next, measured on the planner's own accounts; empty means the account
    /// is always there and refills its queue the instant it frees.
    pub login_gaps: Vec<f64>,
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
    /// Cargo destroyed with a fleet in flight (row 23s).
    pub cargo_lost: f64,
}

impl Books {
    /// The stock this books says the account should have gained.
    pub fn net(&self) -> f64 {
        self.produced - self.wasted - self.spent_build - self.spent_research - self.spent_units - self.fuel
            + self.loot_in - self.loot_out + self.expedition_in - self.cargo_lost
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
    pub sleep_hours: f64,
    /// (end second, object index) of the builds under way, earliest first; the level lands when the clock reaches it.
    pub pending: Vec<(f64, usize)>,
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
        }).collect();
        let modifiers_of = |rows: &Vec<RawModifier>| -> Vec<(usize, Vec<f64>)> { rows.iter().filter_map(|m| index.get(&m.id).map(|&i| (i, m.divisor.clone()))).collect() };
        let modifiers = [modifiers_of(&raw.time_modifiers), modifiers_of(&raw.time_modifiers_research)];
        let planets = raw.planets.iter().map(|p| PlanetConfig {
            planet_id: p.planet_id,
            position: p.position,
            temperature: p.temperature,
            field_max: p.field_max,
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
        }).collect();

        let unit_ids = raw.units.clone();

        Ok(Catalogue { speed: raw.economy_speed, time_grid_level: raw.time_grid_level, research_grid_level: raw.research_grid_level, objects, modifiers, planets, units: raw.units, probes: raw.unit_probes.iter().map(|p| Probe {
            config: p.planet,
            levels: per_object(&p.levels, &index, count).into_iter().map(|l| l.unwrap_or(0)).collect(),
            units: unit_ids.iter().map(|id| p.units.get(&id.to_string()).copied().unwrap_or(0)).collect(),
            answer: p.answer,
        }).collect(), login_gaps: Vec::new() })
    }

    pub fn new_game(&self, config: usize, horizon_hours: f64, bonus_hours: f64, login_seed: u64) -> Game {
        let mut game = self.new_paused_game(config, horizon_hours, bonus_hours, login_seed);
        self.next_decision(&mut game);
        game
    }

    /// A game at second zero whose clock has not moved yet, for a world that moves it with `tick`.
    pub fn new_paused_game(&self, config: usize, horizon_hours: f64, bonus_hours: f64, login_seed: u64) -> Game {
        let c = &self.planets[config];
        let mut planet = Planet { config, levels: c.levels.clone(), res: c.resources, rate: [0.0; 3], cap: [0.0; 3], energy_max: 0.0, energy_used: 0.0, factor: 1.0, units: c.units.clone(), books: Books::default() };
        self.refresh(&mut planet);
        // A night between six and eight hours (the host flags a dark period under six), starting at a drawn hour.
        let mut seed = login_seed | 1;
        let sleep_start = (next_random(&mut seed) % 24) as f64;
        let sleep_hours = 6.0 + (next_random(&mut seed) % 3) as f64;
        Game { planet, t: 0.0, horizon: horizon_hours * 3600.0, bonus_hours, invested: 0.0, done: false, in_world: false, invalid: 0, queue: 0, free: [0.0; 2], commit: [None; 2], login_times: Vec::new(), login_rng: login_seed, session_end: 0.0, last_action: f64::NEG_INFINITY, sleep_start, sleep_hours, pending: Vec::new(), history: Vec::new(), trace: None }
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
        let table = &self.planets[planet.config].unit_production[u];
        if held == 0 || (table.energy.is_empty() && table.own.is_none()) { return 0 }
        let bound = self.planets[planet.config].unit_production[u].usable.as_ref()
            .map_or(u64::MAX, |by| by.iter().map(|&(k, per)| per * planet.levels[k] as u64).sum());
        held.min(bound) as usize
    }

    /// Rates, energy and factor from the levels (PlanetService::updateResourceProductionStatsInner); with
    /// `full_factor` the factor is forced to 100%, the nominal output a player reads off the resource page.
    pub(crate) fn production(&self, planet: &Planet, full_factor: bool) -> ([f64; 3], f64, f64, f64) {
        let c = &self.planets[planet.config];
        let mut made = [0.0; 3];
        let (mut energy_max, mut energy_used) = (c.basic[3].max(0.0), (-c.basic[3]).max(0.0));
        let side = self.research_grid_level + 1;
        let row = |k: usize, rows: &Vec<[f64; 4]>| {
            let mut row = match &c.production_research[k] {
                Some((by, grid)) => {
                    let combo = by.iter().fold(0usize, |at, &r| at * side + (planet.levels[r] as usize).min(side - 1));
                    let level = (planet.levels[k] as usize).min(grid.len() - 1);
                    grid[level][combo.min(grid[level].len() - 1)]
                }
                None => rows[(planet.levels[k] as usize).min(rows.len() - 1)],
            };
            self.add_unit_change(planet, k, planet.levels[k] as usize, &mut row);
            row
        };
        let none: Vec<[f64; 4]> = Vec::new();
        let producers = || (0..c.production.len()).filter_map(|k| match (&c.production[k], &c.production_research[k]) {
            (Some(rows), _) => Some((k, rows)),
            (None, Some(_)) => Some((k, &none)),
            _ => None,
        });
        // Units that produce are producers like any building, counted at the number the host puts to use.
        let units = || (0..c.unit_production.len()).filter_map(|u| {
            let n = self.units_in_use(planet, u);
            (n > 0).then_some((u, n))
        });
        let own = || units().filter_map(|(u, n)| c.unit_production[u].own.as_ref().map(|rows| rows[n.min(rows.len() - 1)]));
        let rows: Vec<[f64; 4]> = producers().map(|(k, rows)| row(k, rows)).chain(own()).collect();
        for &row in &rows {
            if row[3] > 0.0 { energy_max += row[3] } else { energy_used -= row[3] }
            for r in 0..3 { made[r] += row[r] }
        }
        for (u, n) in units() {
            let energy = &c.unit_production[u].energy;
            if energy.is_empty() { continue }
            let [produced, used] = energy[n.min(energy.len() - 1)];
            energy_max += produced;
            energy_used += used;
        }
        for &row in &rows {
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

    pub fn refresh(&self, planet: &mut Planet) {
        let (rate, energy_max, energy_used, factor) = self.production(planet, false);
        let c = &self.planets[planet.config];
        let mut cap = [0.0; 3];
        for (k, rows) in c.storage.iter().enumerate() {
            let Some(rows) = rows else { continue };
            let row = rows[(planet.levels[k] as usize).min(rows.len() - 1)];
            for r in 0..3 { cap[r] += row[r] }
        }
        *planet = Planet { rate, cap, energy_max, energy_used, factor, ..planet.clone() };
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
        let base = self.planets[planet.config].field_max;
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

    /// Row 29: why object k cannot start on this planet, in `legal`'s own terms - the diagnosis reads this instead of
    /// asking the same four questions twice.
    pub fn stall_of(&self, planet: &Planet, k: usize) -> &'static str {
        let o = &self.objects[k];
        if planet.levels[k] as usize >= o.price.len() { return "max level" }
        if !self.requirements_met(&planet.levels, k) { return "needs" }
        if o.consumes_field && self.fields_used(planet) >= self.field_cap(planet) { return "no fields" }
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
        let level = planet.levels[k] as usize;
        let side = self.time_grid_level + 1;
        let cell = self.modifiers[self.objects[k].queue].iter().try_fold(0usize, |at, (m, _)| {
            let l = planet.levels[*m] as usize;
            (l < side).then(|| at * side + l)
        });
        if let Some(cell) = cell {
            return self.objects[k].build_seconds_grid[level][cell];
        }
        let mut seconds = self.objects[k].build_seconds[planet.levels[k] as usize];
        for (m, divisor) in &self.modifiers[self.objects[k].queue] {
            if *m != k {
                seconds /= divisor[(planet.levels[*m] as usize).min(divisor.len() - 1)];
            }
        }
        seconds.floor().max(1.0)
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
            game.login_times.push(awake(game, last + gap.max(MIN_GAP)));
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
        self.advance_to(game, game.horizon, false);
        game.done = true;
    }

    pub fn queue_legal(&self, game: &Game, k: usize) -> bool {
        self.objects[k].queue == game.queue && self.legal(&game.planet, k)
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
                Some(k) => game.t + self.wait_seconds(&game.planet, k),
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
            return (self.login(game, game.t + MIN_GAP, false).min(game.horizon), None);
        }
        // A landing build changes rates and stores, so a plan made before it is re-timed after it.
        match game.pending.first() {
            Some(&(end, _)) if end < best.0.min(game.horizon) => (end, None),
            _ if best.0 >= game.horizon => (game.horizon, None),
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
        game.free[q] = game.pending.first().map_or(NEVER, |p| p.0);
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
                let pick = self.baseline(p, queue, Baseline::Payback, rng)?;
                let busy_hours = (self.wait_seconds(p, pick) + self.build_seconds(p, pick)) / 3600.0;
                let overflow = (0..3).filter(|&r| p.res[r] + p.rate[r].max(0.0) * busy_hours >= p.cap[r]).collect::<Vec<_>>();
                let store = legal.iter().filter_map(|&k| {
                    let mut n = p.clone();
                    n.levels[k] += 1;
                    self.refresh(&mut n);
                    let relief: f64 = overflow.iter().map(|&r| n.cap[r] - p.cap[r]).sum();
                    (relief > 0.0).then(|| (k, -relief / cost(k)))
                }).collect::<Vec<_>>();
                min_by(store).or(Some(pick))
            }
            Baseline::Payback => {
                let after = |k: usize| { let mut n = p.clone(); n.levels[k] += 1; self.refresh(&mut n); n };
                let nominal = |q: &Planet| weighted(self.production(q, true).0);
                let power: Vec<(usize, f64)> = legal.iter().filter_map(|&k| {
                    let n = after(k);
                    let delta = (n.energy_max - n.energy_used) - (p.energy_max - p.energy_used);
                    (delta > 0.0).then(|| (k, cost(k) / delta))
                }).collect();
                if p.factor < 1.0 && !power.is_empty() {
                    return min_by(power);
                }
                let producers: Vec<(usize, f64)> = legal.iter().filter_map(|&k| {
                    let gain = nominal(&after(k)) - nominal(p);
                    (gain > 0.0).then(|| (k, cost(k) / gain))
                }).collect();
                if !producers.is_empty() {
                    return min_by(producers);
                }
                let stores: Vec<(usize, f64)> = legal.iter().filter_map(|&k| {
                    let gain = weighted(after(k).cap) - weighted(p.cap);
                    (gain > 0.0).then(|| (k, -gain / cost(k)))
                }).collect();
                if !stores.is_empty() {
                    return min_by(stores);
                }
                min_by(legal.iter().map(|&k| (k, cost(k))).collect())
            }
        }
    }
}

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
