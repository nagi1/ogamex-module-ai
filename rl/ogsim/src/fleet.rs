//! Fleet movement as the host computes it (`FleetMissionService`, `CoordinateDistanceCalculator`): distance between
//! two coordinates, the slowest ship's speed with the owner's drives, flight time, deuterium and holding time.
//! Pure functions; every number comes from two host exports, so no ship or research is named here (gate 1):
//!
//! * `FleetSettings::load(path)`: `php artisan ai:rl-fleet-settings {universe} {out}`, the universe settings.
//! * `FleetCatalogue::load(path)`: `php artisan ai:rl-combat-catalogue {universe} {out}`, ship speed tables over the
//!   drive researches and raw fuel, valued for one account (its class bonuses are in the tables).
//!
//! API for `world`:
//!
//! * `distance(&settings, from, to, &state) -> i64`: `state(galaxy, system)` says whether a system is empty, active or
//!   inactive (all owners away 7 days); it is asked only when the universe skips empty or inactive systems.
//! * `FleetCatalogue::slowest_speed(fleet, &research) -> i64`, `FleetCatalogue::speed(unit, &research) -> i64`; a
//!   fleet is `[(unit id, amount)]`, research is levels by research id (missing is 0, at most the table's max level).
//! * `flight_seconds(&settings, mission, distance, slowest, speed_percent) -> i64`: one way, at the mission's fleet
//!   speed; `speed_percent` is the dispatch form's value, 10 for 100%, 0.5 steps. The return flight takes as long.
//! * `consumption(&settings, &catalogue, fleet, &research, distance, holding_hours, speed_percent) -> i64`: the
//!   deuterium taken at dispatch, holding fuel and the class and universe multipliers included.
//! * `holding_seconds(hours) -> i64`: expeditions and ACS defend hold for real time, untouched by any fleet speed.
use serde::Deserialize;
use crate::HashMap;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Coord {
    pub galaxy: i64,
    pub system: i64,
    pub position: i64,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum SystemState {
    Empty,
    Active,
    Inactive,
}

#[derive(Deserialize, Clone, Debug)]
pub struct FleetSettings {
    pub number_of_galaxies: i64,
    pub number_of_systems: i64,
    pub ignore_empty_systems: bool,
    pub ignore_inactive_systems: bool,
    /// The speed fuel is priced on, whatever the mission (FleetMissionService::calculateConsumption).
    pub fleet_speed: i64,
    /// Fleet speed by mission type id (war, holding or peaceful speed as the mission declares it).
    pub mission_fleet_speed: HashMap<String, i64>,
    pub deuterium_consumption: f64,
    pub class_consumption_multiplier: f64,
    /// W15: the dark matter a new account starts with, and the officers the host sells by key (fleet settings v2).
    #[serde(default)]
    pub dark_matter_initial: i64,
    #[serde(default)]
    pub officers: std::collections::BTreeMap<String, Officer>,
    /// Late game: the sensor phalanx and jump gate as the host's services answer (fleet settings v3).
    #[serde(default)]
    pub moon_gear: Option<MoonGear>,
    /// L6: halving a queue with dark matter (fleet settings v4).
    #[serde(default)]
    pub halving: Option<Halving>,
    /// L5: the universe's alliance combat system switch (`SettingsService::allianceCombatSystemOn`, fleet settings v5);
    /// an older export reads as off, so no ACS mission flies on tables that do not say it may.
    #[serde(default)]
    pub alliance_combat_system: bool,
}

/// `HalvingService`: dark matter per half hour left (at least `min_cost`, at most the queue's cap), and per queue the most a
/// halving takes off the build's original time. Queues are keyed "building", "research", "unit".
#[derive(Deserialize, Clone, Debug)]
pub struct Halving {
    pub per_half_hour: i64,
    pub min_cost: i64,
    pub max_cost: std::collections::BTreeMap<String, i64>,
    pub max_reduction: std::collections::BTreeMap<String, f64>,
}

/// What a moon's sensor phalanx and jump gate do (`PhalanxService`, `JumpGateService`), per level from 0.
#[derive(Deserialize, Clone, Debug)]
pub struct MoonGear {
    pub phalanx_object: i64,
    pub gate_object: i64,
    /// Systems either way the phalanx reads in its own galaxy (-1 at level 0: none).
    pub phalanx_range: Vec<i64>,
    /// Deuterium a scan takes from the moon.
    pub phalanx_cost: f64,
    /// Seconds both gates rest after a jump, by the lower of their levels.
    pub gate_cooldown: Vec<f64>,
    /// The ships a gate carries, by host id.
    pub gate_ships: Vec<u32>,
}

/// One officer as the host's `OfficerService` answers while it alone is active (the bundle with the staff's extras).
#[derive(Deserialize, Clone, Debug)]
pub struct Officer {
    /// Dark matter by duration in days.
    pub costs: std::collections::BTreeMap<String, i64>,
    pub fleet_slots: usize,
    pub expedition_slots: usize,
    pub research_multiplier: f64,
    pub espionage: i64,
    /// Hiring it makes every other officer active (the host's bundle).
    #[serde(default)]
    pub bundle: bool,
}

impl FleetSettings {
    pub fn load(path: &str) -> Result<FleetSettings, String> {
        let text = std::fs::read_to_string(path).map_err(|e| format!("{path}: {e}"))?;
        serde_json::from_str(&text).map_err(|e| format!("{path}: {e}"))
    }

    pub fn mission_speed(&self, mission: u32) -> i64 {
        *self.mission_fleet_speed.get(&mission.to_string()).unwrap_or_else(|| panic!("mission {mission} has no fleet speed"))
    }
}

#[derive(Deserialize)]
struct RawTable {
    by: Vec<u32>,
    values: Vec<i64>,
}

#[derive(Deserialize)]
struct RawProperties {
    speed: RawTable,
}

#[derive(Deserialize)]
struct RawUnit {
    id: u32,
    fuel: i64,
    properties: RawProperties,
}

#[derive(Deserialize)]
struct RawCatalogue {
    max_level: u32,
    units: Vec<RawUnit>,
}

struct Unit {
    fuel: i64,
    speed: RawTable,
}

pub struct FleetCatalogue {
    max_level: u32,
    units: HashMap<u32, Unit>,
}

impl FleetCatalogue {
    pub fn load(path: &str) -> Result<FleetCatalogue, String> {
        let text = std::fs::read_to_string(path).map_err(|e| format!("{path}: {e}"))?;
        let raw: RawCatalogue = serde_json::from_str(&text).map_err(|e| format!("{path}: {e}"))?;
        let units = raw.units.into_iter().map(|u| (u.id, Unit { fuel: u.fuel, speed: u.properties.speed })).collect();
        Ok(FleetCatalogue { max_level: raw.max_level, units })
    }

    fn unit(&self, id: u32) -> &Unit {
        self.units.get(&id).unwrap_or_else(|| panic!("unit {id} is not in the catalogue"))
    }

    /// The table cell for these levels, the first `by` research varying slowest (ExportAiCombatCatalogueAction).
    pub fn speed(&self, id: u32, research: &HashMap<u32, u32>) -> i64 {
        let table = &self.unit(id).speed;
        let index = table.by.iter().fold(0usize, |index, r| {
            // Past the table reads its last level, as `CombatCatalogue::cell`: a year's play must not die on a rare top level.
            let level = research.get(r).copied().unwrap_or(0).min(self.max_level);
            index * (self.max_level as usize + 1) + level as usize
        });
        table.values[index]
    }

    pub fn fuel(&self, id: u32) -> i64 {
        self.unit(id).fuel
    }

    /// UnitCollection::getSlowestUnitSpeed.
    pub fn slowest_speed(&self, fleet: &[(u32, i64)], research: &HashMap<u32, u32>) -> i64 {
        fleet.iter().map(|&(id, _)| self.speed(id, research)).min().unwrap_or(0)
    }
}

/// FleetMissionService::calculateFleetMissionDistance with the donut wrap and the skipped systems.
pub fn distance(settings: &FleetSettings, from: Coord, to: Coord, state: &dyn Fn(i64, i64) -> SystemState) -> i64 {
    let galaxies = (from.galaxy - to.galaxy).abs();
    if galaxies != 0 {
        return galaxies.min((galaxies - settings.number_of_galaxies).abs()) * 20_000;
    }
    let systems = (from.system - to.system).abs();
    if systems != 0 {
        let wrapped = (systems - settings.number_of_systems).abs();
        let delta = systems.min(wrapped);
        let skipped = skipped_systems(settings, from, to, wrapped < systems, state);
        return (delta - skipped).max(1) * 5 * 19 + 2700;
    }
    let positions = (from.position - to.position).abs();
    if positions != 0 {
        return positions * 5 + 1000;
    }
    5
}

/// CoordinateDistanceCalculator::getNumEmptySystems plus getNumInactiveSystems. A wrapped path counts the ranges
/// [1, to] and [from, last] exactly as the host splits it, whichever side `from` is on.
fn skipped_systems(settings: &FleetSettings, from: Coord, to: Coord, wraps: bool, state: &dyn Fn(i64, i64) -> SystemState) -> i64 {
    let ranges = match wraps {
        true => vec![(1.min(to.system), 1.max(to.system)), (settings.number_of_systems.min(from.system), settings.number_of_systems.max(from.system))],
        false => vec![(from.system.min(to.system), from.system.max(to.system))],
    };
    let count = |wanted: SystemState, on: bool| -> i64 {
        match on {
            true => ranges.iter().map(|&(start, end)| (start..=end).filter(|&s| state(from.galaxy, s) == wanted).count() as i64).sum(),
            false => 0,
        }
    };
    count(SystemState::Empty, settings.ignore_empty_systems) + count(SystemState::Inactive, settings.ignore_inactive_systems)
}

/// FleetMissionService::calculateFleetMissionDuration at a given fleet speed.
pub fn duration_at(fleet_speed: i64, distance: i64, slowest: i64, speed_percent: f64) -> i64 {
    let seconds = (35_000.0 / speed_percent * (ratio(distance * 10, slowest)).sqrt() + 10.0) / fleet_speed as f64;
    (seconds.round() as i64).max(1)
}

/// One-way flight time of a mission at its war, holding or peaceful fleet speed.
pub fn flight_seconds(settings: &FleetSettings, mission: u32, distance: i64, slowest: i64, speed_percent: f64) -> i64 {
    duration_at(settings.mission_speed(mission), distance, slowest, speed_percent)
}

/// FleetMissionService::calculateConsumption.
pub fn consumption(settings: &FleetSettings, catalogue: &FleetCatalogue, fleet: &[(u32, i64)], research: &HashMap<u32, u32>, distance: i64, holding_hours: i64, speed_percent: f64) -> i64 {
    let duration = duration_at(settings.fleet_speed, distance, catalogue.slowest_speed(fleet, research), speed_percent);
    // The host keeps an int here and falls back to 0.5 only when the flight is not longer than 10 speed-seconds.
    let speed_value = ((duration * settings.fleet_speed - 10) as f64).max(0.5);
    let mut fuel = 0.0f64;
    let mut holding = 0i64;
    for &(id, amount) in fleet.iter().filter(|&&(_, amount)| amount != 0) {
        let raw = catalogue.fuel(id);
        let ship_speed_value = 35_000.0 / speed_value * ratio(distance * 10, catalogue.speed(id, research)).sqrt();
        holding += raw * amount * holding_hours;
        fuel += (ratio(raw * amount * distance, 35_000) * (ship_speed_value / 10.0 + 1.0).powf(2.0)).max(1.0);
    }
    let mut total = fuel.round();
    if holding_hours > 0 {
        total += ratio(holding, 10).floor().max(1.0);
    }
    let total = (total * settings.class_consumption_multiplier) as i64;
    match settings.deuterium_consumption == 1.0 {
        true => total,
        false => (total as f64 * settings.deuterium_consumption).round() as i64,
    }
}

pub fn holding_seconds(hours: i64) -> i64 {
    hours * 3600
}

/// PHP's `int / int`: exact quotients stay integers, the rest divide as doubles; both read the same as f64.
fn ratio(numerator: i64, denominator: i64) -> f64 {
    numerator as f64 / denominator as f64
}

#[cfg(test)]
mod tests {
    use super::*;

    #[derive(Deserialize)]
    struct Case {
        from: [i64; 3],
        to: [i64; 3],
        research: HashMap<String, u32>,
        fleet: Vec<(u32, i64)>,
        speed_percent: f64,
        holding_hours: i64,
        mission: u32,
        distance: i64,
        duration: i64,
        consumption: i64,
    }

    #[derive(Deserialize)]
    struct Group {
        settings: FleetSettings,
        occupied: Vec<(i64, i64, bool)>,
        cases: Vec<Case>,
    }

    #[derive(Deserialize)]
    struct Cases {
        player_id: i64,
        groups: Vec<Group>,
    }

    fn storage(name: &str, default: &str) -> String {
        std::env::var(name).unwrap_or_else(|_| format!("{}/../../../../storage/rl/ogsim/{default}", env!("CARGO_MANIFEST_DIR")))
    }

    fn coord(c: [i64; 3]) -> Coord {
        Coord { galaxy: c[0], system: c[1], position: c[2] }
    }

    /// The host's answers from `ai:rl-fleet-cases`, against the same account's `ai:rl-combat-catalogue`.
    #[test]
    fn matches_every_host_case() {
        let cases_path = storage("OGSIM_FLEET_CASES", "fleet-cases-u1.json");
        let catalogue_path = storage("OGSIM_COMBAT_CATALOGUE", "combat-u1.json");
        let cases: Cases = serde_json::from_str(&std::fs::read_to_string(&cases_path).expect(&cases_path)).expect("cases json");
        let catalogue = FleetCatalogue::load(&catalogue_path).unwrap();
        let catalogue_player: serde_json::Value = serde_json::from_str(&std::fs::read_to_string(&catalogue_path).unwrap()).unwrap();
        assert_eq!(catalogue_player["player_id"].as_i64(), Some(cases.player_id), "cases and catalogue are for different accounts");

        let (mut total, mut mismatched, mut failures) = (0, 0, Vec::new());
        for group in &cases.groups {
            let occupied: HashMap<(i64, i64), bool> = group.occupied.iter().map(|&(g, s, inactive)| ((g, s), inactive)).collect();
            let state = |g: i64, s: i64| match occupied.get(&(g, s)) {
                None => SystemState::Empty,
                Some(true) => SystemState::Inactive,
                Some(false) => SystemState::Active,
            };
            for case in &group.cases {
                total += 1;
                let research: HashMap<u32, u32> = case.research.iter().map(|(k, &v)| (k.parse().unwrap(), v)).collect();
                let distance = distance(&group.settings, coord(case.from), coord(case.to), &state);
                let slowest = catalogue.slowest_speed(&case.fleet, &research);
                let duration = flight_seconds(&group.settings, case.mission, distance, slowest, case.speed_percent);
                let fuel = consumption(&group.settings, &catalogue, &case.fleet, &research, distance, case.holding_hours, case.speed_percent);
                let got = (distance, duration, fuel);
                let want = (case.distance, case.duration, case.consumption);
                if got == want {
                    continue;
                }
                mismatched += 1;
                if failures.len() < 10 {
                    failures.push(format!("{:?} -> {:?} fleet {:?} sp {}: got {got:?}, host {want:?}", case.from, case.to, case.fleet, case.speed_percent));
                }
            }
        }
        println!("fleet parity: {} / {total} cases match the host", total - mismatched);
        assert_eq!(mismatched, 0, "first mismatches: {failures:#?}");
        assert!(total > 0, "no cases in {cases_path}");
    }

    #[test]
    fn holding_is_real_time() {
        assert_eq!(holding_seconds(3), 10_800);
    }
}
