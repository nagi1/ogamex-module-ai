//! Battles through the host's own Rust battle engine (`/rust/battle_engine_ffi`, linked as a library: its rounds are
//! not ported), with what the host does around it (`BattleEngine::simulateBattle`, `LootService`,
//! `DefenseRepairService`): defence repair, loot under the cargo limits, debris and the moon chance. Every number comes
//! from `php artisan ai:rl-combat-catalogue {universe} {out}`, so no unit or research is named here (gate 1).
//!
//! API for `world`:
//!
//! * `CombatCatalogue::load(path)` / `from_value(json)`: the combat catalogue, valued for one account (its class
//!   bonuses, loot percentage and universe rules live in the file, and `ai:rl-combat-catalogue --player=<id>` writes
//!   one file per class). An optional `non_combatants` list (unit ids the host leaves out of a planet's defence;
//!   `ai:rl-battle-cases` writes it by asking the host) is dropped from defenders.
//! * `Battle { attacker, attacker_research, attacker_cargo, defender, defender_research, defender_stock,
//!   defender_engineer, moon_exists, seed }`: fleets are `[(unit id, amount)]` (the defender is the planet's ships
//!   and defences), research is levels by research id (missing is 0, at most the catalogue's max level),
//!   `attacker_cargo` what the fleet already carries, `defender_stock` the planet's metal, crystal and deuterium.
//! * `fight(&attacker, &defender, &battle) -> Outcome`: the whole battle, reproducible from `seed`. A fight takes both
//!   players' catalogues, because the host values each side with its own player's class; they must be of one universe.
//!   `Outcome` holds `rounds`, `winner` (`BattleReport`'s rule: no round is an attacker win, both standing a draw, both
//!   gone a defender win), survivors, losses, `repaired` defences (back on the planet, not lost), `loot` (taken from
//!   the planet), `cargo` (what the surviving fleet flies home with), `debris` (into the field), `moon_chance` in
//!   percent, `moon_created`, and both sides' losses at build price. The planet keeps `defender_left + repaired`.
//! * Pieces, for parity and for callers that bring their own result: `engine_input` (the JSON
//!   `RustBattleEngine::prepareBattleInput` builds), `run_engine`, `roll_repairs`, `settle` (the arithmetic after the
//!   engine, exact against the host given the same survivors, repairs and moon roll).
//!
//! Out of scope (v1, raid-sim-spec.md): ACS, tactical retreat, the General's Hamill manoeuvre and Reaper debris
//! pickup, wreck fields. A fight costs one engine call (JSON in and out, ~10 µs for tens of units) plus the settling.
use serde::Deserialize;
use serde_json::{json, Map, Value};
use std::collections::{BTreeMap, HashMap, HashSet};
use std::ffi::{CStr, CString};

#[derive(Deserialize)]
struct RawTable {
    by: Vec<u32>,
    values: Vec<i64>,
}

#[derive(Deserialize)]
struct RawProperties {
    structural_integrity: RawTable,
    shield: RawTable,
    attack: RawTable,
    capacity: RawTable,
}

#[derive(Deserialize)]
struct RawUnit {
    id: u32,
    #[serde(rename = "type")]
    kind: String,
    price: [f64; 3],
    /// PHP writes an empty map as `[]`.
    rapidfire: Value,
    properties: RawProperties,
}

#[derive(Deserialize, Clone, Debug)]
pub struct CombatRules {
    pub loot_percentage: f64,
    pub debris_ships_percentage: f64,
    pub debris_defense_percentage: f64,
    pub debris_deuterium: bool,
    pub defense_repair_rate: f64,
    pub maximum_moon_chance: f64,
    /// `CharacterClassService::getReaperDebrisCollectionPercentage`: what a surviving debris collector hauls in, in
    /// percent; catalogues written before this rule existed collect nothing.
    #[serde(default)]
    pub reaper_debris_percentage: f64,
    /// The unit the host lets haul debris in after a fight, by host id (`AttackMission::processArrival`).
    #[serde(default)]
    pub debris_collector: Option<u32>,
}

#[derive(Deserialize)]
struct RawCatalogue {
    max_level: u32,
    rules: CombatRules,
    units: Vec<RawUnit>,
    #[serde(default)]
    non_combatants: Vec<u32>,
    #[serde(default)]
    missions: Missions,
}

/// The units and research the mission classes read, by host id (ExportAiCombatCatalogueAction::missions).
#[derive(Deserialize, Default, Clone, Debug)]
pub struct Missions {
    /// Ships an espionage fleet must hold; their count sets the report's depth.
    pub espionage_units: Vec<u32>,
    /// The research whose levels decide counter-espionage and the report's depth.
    pub espionage_research: Option<u32>,
    /// The ship that harvests debris at each slot, by position.
    pub harvesters: HashMap<String, u32>,
}

struct Unit {
    defense: bool,
    price: [f64; 3],
    rapidfire: Map<String, Value>,
    properties: RawProperties,
}

pub struct CombatCatalogue {
    max_level: u32,
    pub rules: CombatRules,
    units: HashMap<u32, Unit>,
    non_combatants: HashSet<u32>,
    debris_collector: Option<u32>,
    pub missions: Missions,
}

pub type Units = BTreeMap<u32, i64>;

pub struct Battle<'a> {
    pub attacker: &'a [(u32, i64)],
    pub attacker_research: &'a HashMap<u32, u32>,
    /// What the fleet carries into the fight, half fuel included; a fractional deuterium survives to the same decimals
    /// the host's `Resources` does.
    pub attacker_cargo: [f64; 3],
    pub defender: &'a [(u32, i64)],
    pub defender_research: &'a HashMap<u32, u32>,
    pub defender_stock: [f64; 3],
    pub defender_engineer: bool,
    pub moon_exists: bool,
    pub seed: u64,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Winner {
    Attacker,
    Defender,
    Draw,
}

/// What the engine says: rounds fought and who stands after the last one.
#[derive(Clone, Debug, PartialEq)]
pub struct EngineResult {
    pub rounds: u32,
    pub attacker_left: Units,
    pub defender_left: Units,
}

#[derive(Clone, Debug, PartialEq)]
pub struct Outcome {
    pub rounds: u32,
    pub winner: Winner,
    pub attacker_left: Units,
    pub defender_left: Units,
    pub attacker_lost: Units,
    pub defender_lost: Units,
    pub repaired: Units,
    pub loot: [i64; 3],
    /// What the surviving fleet flies home with: the cargo it held, plus the loot and the debris it hauled in, capped
    /// by the surviving capacity.
    pub cargo: [i64; 3],
    /// What goes into the debris field (the fight's debris less what both sides' collectors hauled out).
    pub debris: [i64; 3],
    /// What the defender's own collectors put back on the planet (fractional, as the host adds it).
    pub salvage: [f64; 3],
    pub moon_chance: i64,
    pub moon_created: bool,
    pub attacker_loss: [i64; 3],
    pub defender_loss: [i64; 3],
}

impl CombatCatalogue {
    pub fn load(path: &str) -> Result<CombatCatalogue, String> {
        let text = std::fs::read_to_string(path).map_err(|e| format!("{path}: {e}"))?;
        Self::from_value(serde_json::from_str(&text).map_err(|e| format!("{path}: {e}"))?)
    }

    pub fn from_value(value: Value) -> Result<CombatCatalogue, String> {
        let raw: RawCatalogue = serde_json::from_value(value).map_err(|e| e.to_string())?;
        let units = raw
            .units
            .into_iter()
            .map(|u| {
                let rapidfire = u.rapidfire.as_object().cloned().unwrap_or_default();
                (u.id, Unit { defense: u.kind == "defense", price: u.price, rapidfire, properties: u.properties })
            })
            .collect();
        Ok(CombatCatalogue { max_level: raw.max_level, debris_collector: raw.rules.debris_collector, rules: raw.rules, units, non_combatants: raw.non_combatants.into_iter().collect(), missions: raw.missions })
    }

    fn unit(&self, id: u32) -> &Unit {
        self.units.get(&id).unwrap_or_else(|| panic!("unit {id} is not in the combat catalogue"))
    }

    /// The table cell for these levels, the first `by` research varying slowest (ExportAiCombatCatalogueAction).
    fn cell(&self, table: &RawTable, research: &HashMap<u32, u32>) -> i64 {
        let index = table.by.iter().fold(0usize, |index, r| {
            let level = research.get(r).copied().unwrap_or(0);
            assert!(level <= self.max_level, "research {r} at {level} is past the combat catalogue's max level {}", self.max_level);
            index * (self.max_level as usize + 1) + level as usize
        });
        table.values[index]
    }

    /// A defence (the host's `defense` type), as against a ship.
    pub fn is_defense(&self, id: u32) -> bool {
        self.unit(id).defense
    }

    /// UnitCollection::getTotalCargoCapacity.
    pub fn capacity(&self, units: &Units, research: &HashMap<u32, u32>) -> i64 {
        units.iter().map(|(&id, &n)| self.cell(&self.unit(id).properties.capacity, research) * n).sum()
    }

    /// UnitCollection::toResources.
    pub fn price_of(&self, units: &Units) -> [i64; 3] {
        let mut total = [0i64; 3];
        for (&id, &n) in units {
            for (r, p) in self.unit(id).price.iter().enumerate() {
                total[r] += *p as i64 * n;
            }
        }
        total
    }

    fn fleet_json(&self, fleet_mission_id: u32, owner_id: u32, units: &Units, research: &HashMap<u32, u32>) -> Value {
        let units: Map<String, Value> = units
            .iter()
            .map(|(&id, &amount)| {
                let p = &self.unit(id).properties;
                let entry = json!({
                    "unit_id": id,
                    "amount": amount,
                    "shield_points": self.cell(&p.shield, research),
                    "attack_power": self.cell(&p.attack, research),
                    "hull_plating": self.cell(&p.structural_integrity, research).div_euclid(10),
                    "rapidfire": self.unit(id).rapidfire,
                });
                (id.to_string(), entry)
            })
            .collect();
        json!({ "fleet_mission_id": fleet_mission_id, "owner_id": owner_id, "units": units })
    }
}

fn units_of(fleet: &[(u32, i64)], skip: &HashSet<u32>) -> Units {
    let mut units = Units::new();
    for &(id, n) in fleet {
        if n > 0 && !skip.contains(&id) {
            *units.entry(id).or_insert(0) += n;
        }
    }
    units
}

fn total(units: &Units) -> i64 {
    units.values().sum()
}

fn minus(start: &Units, left: &Units) -> Units {
    start.iter().map(|(&id, &n)| (id, n - left.get(&id).copied().unwrap_or(0))).filter(|&(_, n)| n > 0).collect()
}

/// The units the defender fights with: the planet's units without the host's non-combatants.
pub fn defender_units(catalogue: &CombatCatalogue, battle: &Battle) -> Units {
    units_of(battle.defender, &catalogue.non_combatants)
}

/// RustBattleEngine::prepareBattleInput: one fleet a side, each valued at its owner's research and class, hull =
/// floor(SI / 10). Each side reads its own catalogue: the class changes weapon, shield and hull levels and speeds.
pub fn engine_input(attacker: &CombatCatalogue, defender: &CombatCatalogue, battle: &Battle) -> Value {
    let fleet = units_of(battle.attacker, &HashSet::new());
    let planet = defender_units(defender, battle);
    json!({
        "attacker_fleets": [attacker.fleet_json(1, 1, &fleet, battle.attacker_research)],
        "defender_fleets": [defender.fleet_json(0, 2, &planet, battle.defender_research)],
        "seed": battle.seed,
    })
}

#[derive(Deserialize)]
struct EngineCount {
    unit_id: u32,
    amount: i64,
}

#[derive(Deserialize)]
struct EngineRound {
    attacker_ships: HashMap<String, EngineCount>,
    defender_ships: HashMap<String, EngineCount>,
}

#[derive(Deserialize)]
struct EngineOutput {
    rounds: Vec<EngineRound>,
}

fn counts(ships: &HashMap<String, EngineCount>) -> Units {
    ships.values().filter(|c| c.amount > 0).map(|c| (c.unit_id, c.amount)).collect()
}

/// One call of the host engine (`fight_battle_rounds`, the function RustBattleEngine calls through FFI).
pub fn run_engine(input: &Value) -> EngineResult {
    let text = CString::new(input.to_string()).expect("engine input has no NUL");
    let pointer = battle_engine_ffi::fight_battle_rounds(text.as_ptr());
    let output: EngineOutput = serde_json::from_str(unsafe { CStr::from_ptr(pointer) }.to_str().expect("engine output is UTF-8")).expect("engine output parses");
    battle_engine_ffi::free_battle_result(pointer);

    let side = |key: &str| -> Units {
        input[key][0]["units"].as_object().map_or_else(Units::new, |units| units.values().map(|u| (u["unit_id"].as_u64().unwrap() as u32, u["amount"].as_i64().unwrap())).filter(|&(_, n)| n > 0).collect())
    };
    match output.rounds.last() {
        Some(last) => EngineResult { rounds: output.rounds.len() as u32, attacker_left: counts(&last.attacker_ships), defender_left: counts(&last.defender_ships) },
        None => EngineResult { rounds: 0, attacker_left: side("attacker_fleets"), defender_left: side("defender_fleets") },
    }
}

/// A fight the host already settled: the engine's survivors, the defences it repaired and the roll it made for a moon
/// (1..=100). `ai:rl-mission-cases` records these, so a replay needs none of the host's dice; the arithmetic around the
/// fight (loot, debris, cargo home) is still the world's own.
#[derive(Clone, Debug)]
pub struct Recorded {
    pub engine: EngineResult,
    pub repaired: Units,
    pub moon_roll: i64,
}

/// splitmix64: the simulator's own stream for the host's post-battle dice (repairs, moon), seeded from the fight.
pub struct Dice(u64);

impl Dice {
    pub fn new(seed: u64) -> Dice {
        Dice(seed ^ 0x9E37_79B9)
    }

    fn next(&mut self) -> u64 {
        self.0 = self.0.wrapping_add(0x9E37_79B9_7F4A_7C15);
        let mut z = self.0;
        z = (z ^ (z >> 30)).wrapping_mul(0xBF58_476D_1CE4_E5B9);
        z = (z ^ (z >> 27)).wrapping_mul(0x94D0_49BB_1331_11EB);
        z ^ (z >> 31)
    }

    /// A whole number in 1..=100, as the host's `mt_rand(1, 100)`.
    pub fn percent(&mut self) -> i64 {
        ((self.next() >> 11) as f64 / (1u64 << 53) as f64 * 100.0) as i64 + 1
    }
}

/// The repair rate after the Engineer halves the losses (BattleEngine::simulateBattle).
pub fn repair_rate(catalogue: &CombatCatalogue, engineer: bool) -> i64 {
    let rate = catalogue.rules.defense_repair_rate;
    let rate = if engineer { (rate + (100.0 - rate) / 2.0).round() } else { rate };
    rate.clamp(0.0, 100.0) as i64
}

/// DefenseRepairService::calculateRepairedDefenses: each destroyed defence comes back on a roll at the repair rate.
/// The host's one-per-planet cap on a few defences needs no rule here: a planet never holds more than one of them.
pub fn roll_repairs(catalogue: &CombatCatalogue, defender_lost: &Units, engineer: bool, dice: &mut Dice) -> Units {
    let rate = repair_rate(catalogue, engineer);
    let mut repaired = Units::new();
    for (&id, &lost) in defender_lost {
        if !catalogue.unit(id).defense || rate == 0 {
            continue;
        }
        let back = if rate == 100 { lost } else { (0..lost).filter(|_| dice.percent() <= rate).count() as i64 };
        if back > 0 {
            repaired.insert(id, back);
        }
    }
    repaired
}

/// LootService::distributeLoot: loot over the capacity is cut to even thirds, then the free space refilled evenly.
pub fn distribute_loot(loot: [f64; 3], capacity: i64) -> [f64; 3] {
    let capacity = capacity as f64;
    if capacity >= loot[0] + loot[1] + loot[2] + 0.0 {
        return loot;
    }
    let even = (capacity / 3.0).floor();
    let mut taken = loot.map(|r| r.min(even));
    let mut remaining = capacity - (taken[0] + taken[1] + taken[2] + 0.0);
    let mut passes = 0;
    while remaining > 0.0 && passes < 1000 {
        passes += 1;
        let unfilled = (0..3).filter(|&r| loot[r] > taken[r]).count();
        if unfilled == 0 {
            break;
        }
        for r in 0..3 {
            if loot[r] > taken[r] {
                taken[r] = loot[r].min(taken[r] + remaining / unfilled as f64);
            }
        }
        remaining = capacity - (taken[0] + taken[1] + taken[2] + 0.0);
    }
    taken
}

/// Everything after the engine, as `BattleEngine::simulateBattle` and `AttackMission::processArrival` apply it for one
/// attacking fleet. `attacker` values that fleet's side, `defender` the planet's; the universe rules in both are equal.
pub fn settle(attacker: &CombatCatalogue, defender: &CombatCatalogue, battle: &Battle, engine: EngineResult, repaired: Units, moon_roll: i64) -> Outcome {
    settle_in(attacker, defender, battle, engine, repaired, moon_roll, true)
}

/// `settle`, where `plunder` says whether this is an attack: only `AttackMission` loots and lets collectors haul the
/// debris, so a counter-espionage fight (`plunder` false) leaves all of its debris in the field and takes no loot.
pub fn settle_in(attacker: &CombatCatalogue, defender: &CombatCatalogue, battle: &Battle, engine: EngineResult, repaired: Units, moon_roll: i64, plunder: bool) -> Outcome {
    let rules = &attacker.rules;
    let attacker_start = units_of(battle.attacker, &HashSet::new());
    let defender_start = defender_units(defender, battle);
    let attacker_lost = minus(&attacker_start, &engine.attacker_left);
    let defender_lost = minus(&defender_start, &engine.defender_left);

    let winner = match (engine.rounds, total(&engine.attacker_left) > 0, total(&engine.defender_left) > 0) {
        (0, _, _) => Winner::Attacker,
        (_, true, true) => Winner::Draw,
        (_, true, false) => Winner::Attacker,
        _ => Winner::Defender,
    };

    // The host loots only a defender wiped out to the last unit, and the cargo a fleet held survives at the same rate
    // as its cargo capacity (BattleEngine::distributeResources).
    let (capacity, carried) = surviving(attacker, battle, &attacker_start, &engine.attacker_left);
    let loot = if plunder && total(&engine.defender_left) == 0 { loot(attacker, battle, &attacker_start, capacity, carried) } else { [0; 3] };

    // Debris: the build price of what is gone for good, ships and defences at their own share (calculateDebris).
    let mut gone = attacker_lost.clone();
    for (&id, &n) in &minus(&defender_lost, &repaired) {
        *gone.entry(id).or_insert(0) += n;
    }
    let mut gross = [0i64; 3];
    for (&id, &n) in &gone {
        let unit = attacker.unit(id);
        let share = if unit.defense { rules.debris_defense_percentage } else { rules.debris_ships_percentage };
        if share <= 0.0 {
            continue;
        }
        for r in 0..if rules.debris_deuterium { 3 } else { 2 } {
            gross[r] += ((unit.price[r] * n as f64) * (share / 100.0)).floor() as i64;
        }
    }
    // The moon is rolled on the fight's debris, before anyone hauls any of it away (BattleEngine::simulateBattle).
    let moon_chance = if battle.moon_exists { 0 } else { (((gross[0] + gross[1] + gross[2]) as f64 / 100_000.0).floor()).min(rules.maximum_moon_chance) as i64 };

    // AttackMission::processArrival: surviving collectors haul a share of the field in, first the attacker's (capped by
    // the room its fleet has left), then the defender's, whose share lands back on the planet. The shares stay
    // fractional like the host's, and are truncated only where the host stores a whole number.
    let gross = gross.map(|r| r as f64);
    let free = (capacity - carried[0] - carried[1] - carried[2] - loot[0] - loot[1] - loot[2]).max(0);
    let hauled = if plunder { capped(collected(attacker, battle, &engine.attacker_left, battle.attacker_research, gross), free) } else { [0.0; 3] };
    let left = [gross[0] - hauled[0], gross[1] - hauled[1], gross[2] - hauled[2]];
    let salvage = if plunder { collected(defender, battle, &engine.defender_left, battle.defender_research, left) } else { [0.0; 3] };
    let debris = [left[0] - salvage[0], left[1] - salvage[1], left[2] - salvage[2]].map(|r| r as i64);

    // The cargo the fleet held, the loot and what its collectors hauled fly home together (startReturn).
    let cargo = capped([carried[0] as f64 + loot[0] as f64 + hauled[0], carried[1] as f64 + loot[1] as f64 + hauled[1], carried[2] as f64 + loot[2] as f64 + hauled[2]], capacity).map(|r| r as i64);

    Outcome {
        rounds: engine.rounds,
        winner,
        attacker_loss: attacker.price_of(&attacker_lost),
        defender_loss: attacker.price_of(&defender_lost),
        attacker_left: engine.attacker_left,
        defender_left: engine.defender_left,
        attacker_lost,
        defender_lost,
        repaired,
        loot,
        cargo,
        debris,
        salvage,
        moon_chance,
        moon_created: !battle.moon_exists && moon_roll <= moon_chance,
    }
}

/// What one side's surviving collectors haul out of a debris field: the host's share of each resource (a whole number),
/// cut to what the collectors can carry (AttackMission::processArrival).
fn collected(catalogue: &CombatCatalogue, battle: &Battle, survivors: &Units, research: &HashMap<u32, u32>, debris: [f64; 3]) -> [f64; 3] {
    let Some(id) = catalogue.debris_collector else { return [0.0; 3] };
    let count = survivors.get(&id).copied().unwrap_or(0);
    let share = catalogue.rules.reaper_debris_percentage;
    if count <= 0 || share <= 0.0 {
        return [0.0; 3];
    }
    let wanted = debris.map(|r| ((r * (share / 100.0)) as i64) as f64);
    capped(wanted, catalogue.cell(&catalogue.unit(id).properties.capacity, research) * count)
}

/// Cut what is meant for a hold to what the hold holds (LootService::distributeLoot).
fn capped(values: [f64; 3], capacity: i64) -> [f64; 3] {
    if values[0] + values[1] + values[2] <= capacity as f64 {
        return values;
    }
    distribute_loot(values, capacity)
}

/// The cargo capacity left after a fight, and the cargo the fleet held that came back with it
/// (BattleEngine::distributeResources: carried resources survive at the same rate as cargo capacity).
fn surviving(catalogue: &CombatCatalogue, battle: &Battle, start: &Units, left: &Units) -> (i64, [i64; 3]) {
    let original = catalogue.capacity(start, battle.attacker_research);
    let capacity = catalogue.capacity(left, battle.attacker_research);
    let rate = if original > 0 { capacity as f64 / original as f64 } else { 0.0 };
    (capacity, battle.attacker_cargo.map(|c| (c * rate) as i64))
}

/// The plunder of a won fight: `loot%` of the stock, cut to the starting capacity (calculateLootCapacityConstrained),
/// then each resource, in the host's order, to the room the surviving cargo left (distributeLootResource, one fleet).
fn loot(catalogue: &CombatCatalogue, battle: &Battle, start: &Units, capacity: i64, carried: [i64; 3]) -> [i64; 3] {
    let share = catalogue.rules.loot_percentage / 100.0;
    let wanted = distribute_loot(battle.defender_stock.map(|r| r.max(0.0) * share), catalogue.capacity(start, battle.attacker_research));
    let mut room = (capacity - carried[0] - carried[1] - carried[2]).max(0);
    wanted.map(|r| {
        let take = (r as i64).clamp(0, room);
        room -= take;
        take
    })
}

/// A whole battle: the host engine, the repair dice, the settling and the moon roll, all from `battle.seed`.
pub fn fight(attacker: &CombatCatalogue, defender: &CombatCatalogue, battle: &Battle) -> Outcome {
    fight_in(attacker, defender, battle, true)
}

/// `fight`, settled as `settle_in` does.
pub fn fight_in(attacker: &CombatCatalogue, defender: &CombatCatalogue, battle: &Battle, plunder: bool) -> Outcome {
    let engine = run_engine(&engine_input(attacker, defender, battle));
    let mut dice = Dice::new(battle.seed);
    let defender_lost = minus(&defender_units(defender, battle), &engine.defender_left);
    let repaired = roll_repairs(defender, &defender_lost, battle.defender_engineer, &mut dice);
    let moon_roll = dice.percent();
    settle_in(attacker, defender, battle, engine, repaired, moon_roll, plunder)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[derive(Deserialize)]
    struct Side {
        research: HashMap<String, u32>,
        units: Value,
        #[serde(default)]
        cargo: [f64; 3],
        #[serde(default)]
        resources: [f64; 3],
        #[serde(default)]
        engineer: bool,
        #[serde(default)]
        moon_exists: bool,
    }

    #[derive(Deserialize)]
    struct HostResult {
        rounds: u32,
        winner: String,
        attacker_left: Value,
        defender_left: Value,
        attacker_lost: Value,
        defender_lost: Value,
        repaired: Value,
        loot: [f64; 3],
        debris: [f64; 3],
        moon_chance: i64,
        attacker_resource_loss: [f64; 3],
        defender_resource_loss: [f64; 3],
    }

    #[derive(Deserialize)]
    struct Case {
        seed: u64,
        attacker: Side,
        defender: Side,
        host_input: Value,
        result: HostResult,
    }

    #[derive(Deserialize)]
    struct Cases {
        catalogue: Value,
        cases: Vec<Case>,
    }

    /// `{}` and `[]` (PHP's empty map) both read as no units.
    fn units(value: &Value) -> Units {
        value.as_object().map_or_else(Units::new, |m| m.iter().map(|(k, v)| (k.parse().unwrap(), v.as_i64().unwrap())).collect())
    }

    fn research(levels: &HashMap<String, u32>) -> HashMap<u32, u32> {
        levels.iter().map(|(k, &v)| (k.parse().unwrap(), v)).collect()
    }

    fn triple(values: [f64; 3]) -> [i64; 3] {
        values.map(|v| v as i64)
    }

    /// The units of one side of an engine input, as numbers, whatever the JSON spelling (10 or 10.0, {} or []).
    fn engine_side(input: &Value, key: &str) -> BTreeMap<u32, (i64, f64, f64, f64, BTreeMap<String, i64>)> {
        input[key][0]["units"].as_object().map_or_else(BTreeMap::new, |m| {
            m.values()
                .map(|u| {
                    let rf = u["rapidfire"].as_object().map_or_else(BTreeMap::new, |r| r.iter().map(|(k, v)| (k.clone(), v.as_i64().unwrap())).collect());
                    (u["unit_id"].as_u64().unwrap() as u32, (u["amount"].as_i64().unwrap(), u["attack_power"].as_f64().unwrap(), u["shield_points"].as_f64().unwrap(), u["hull_plating"].as_f64().unwrap(), rf))
                })
                .collect()
        })
    }

    fn load() -> (CombatCatalogue, Vec<Case>) {
        let path = std::env::var("OGSIM_BATTLE_CASES").unwrap_or_else(|_| format!("{}/../../../../storage/rl/ogsim/battle-cases-u1.json", env!("CARGO_MANIFEST_DIR")));
        let text = std::fs::read_to_string(&path).unwrap_or_else(|e| panic!("{path}: {e} (write it with php artisan ai:rl-battle-cases)"));
        let cases: Cases = serde_json::from_str(&text).unwrap();
        (CombatCatalogue::from_value(cases.catalogue).unwrap(), cases.cases)
    }

    struct Owned {
        attacker: Vec<(u32, i64)>,
        defender: Vec<(u32, i64)>,
        attacker_research: HashMap<u32, u32>,
        defender_research: HashMap<u32, u32>,
    }

    fn owned(case: &Case) -> Owned {
        Owned {
            attacker: units(&case.attacker.units).into_iter().collect(),
            defender: units(&case.defender.units).into_iter().collect(),
            attacker_research: research(&case.attacker.research),
            defender_research: research(&case.defender.research),
        }
    }

    fn battle<'a>(case: &Case, o: &'a Owned, seed: u64) -> Battle<'a> {
        Battle {
            attacker: &o.attacker,
            attacker_research: &o.attacker_research,
            attacker_cargo: case.attacker.cargo,
            defender: &o.defender,
            defender_research: &o.defender_research,
            defender_stock: case.defender.resources,
            defender_engineer: case.defender.engineer,
            moon_exists: case.defender.moon_exists,
            seed,
        }
    }

    /// The input the simulator builds is the one the host builds, unit for unit, value for value, with the same seed;
    /// so the host engine fed either gives the same rounds.
    #[test]
    fn builds_the_hosts_engine_input() {
        let (catalogue, cases) = load();
        for (i, case) in cases.iter().enumerate() {
            let o = owned(case);
            let ours = engine_input(&catalogue, &catalogue, &battle(case, &o, case.seed));
            for key in ["attacker_fleets", "defender_fleets"] {
                assert_eq!(engine_side(&ours, key), engine_side(&case.host_input, key), "case {i} {key}");
            }
            assert_eq!(ours["seed"].as_u64(), case.host_input["seed"].as_u64(), "case {i} seed");
            assert_eq!(run_engine(&ours), run_engine(&case.host_input), "case {i}: same input, same seed, same rounds");
        }
    }

    /// Given the host's own survivors, repairs and rounds, every number after the engine is the host's, exactly.
    #[test]
    fn settles_exactly_as_the_host() {
        let (catalogue, cases) = load();
        for (i, case) in cases.iter().enumerate() {
            let o = owned(case);
            let b = battle(case, &o, case.seed);
            let r = &case.result;
            let engine = EngineResult { rounds: r.rounds, attacker_left: units(&r.attacker_left), defender_left: units(&r.defender_left) };
            let out = settle(&catalogue, &catalogue, &b, engine, units(&r.repaired), 101);
            let winner = match out.winner {
                Winner::Attacker => "attacker",
                Winner::Defender => "defender",
                Winner::Draw => "draw",
            };
            assert_eq!(winner, r.winner, "case {i} winner");
            assert_eq!(out.attacker_lost, units(&r.attacker_lost), "case {i} attacker lost");
            assert_eq!(out.defender_lost, units(&r.defender_lost), "case {i} defender lost");
            assert_eq!(out.loot, triple(r.loot), "case {i} loot");
            assert_eq!(out.debris, triple(r.debris), "case {i} debris");
            assert_eq!(out.moon_chance, r.moon_chance, "case {i} moon chance");
            assert_eq!(out.attacker_loss, triple(r.attacker_resource_loss), "case {i} attacker loss");
            assert_eq!(out.defender_loss, triple(r.defender_resource_loss), "case {i} defender loss");
        }
    }

    /// The host fights with PhpBattleEngine (no FFI in host PHP), which draws from mt_rand, so it cannot agree with the
    /// Rust engine seed for seed. Each case is one PHP draw; the simulator plays it SEEDS times. The mean of
    /// (PHP - simulator mean) over the cases must hold 0 inside a 99.9% band (z 3.29, one band per measure), on
    /// measures scaled to the case: loss shares at price, loot and debris shares of their most, the outcome counts.
    #[test]
    fn agrees_with_the_host_in_distribution() {
        const SEEDS: u64 = 32;
        let (catalogue, cases) = load();
        let names = ["attacker win", "defender win", "draw", "rounds/6", "attacker loss share", "defender loss share", "loot share", "debris share", "moon chance/max", "repaired share"];
        let mut diffs: Vec<Vec<f64>> = vec![Vec::new(); names.len()];
        let mut fights = 0u64;
        let started = std::time::Instant::now();
        for case in &cases {
            let o = owned(case);
            let b = battle(case, &o, 0);
            let value = |p: [i64; 3]| (p[0] + p[1] + p[2]) as f64;
            let attacker_value = value(catalogue.price_of(&units_of(b.attacker, &HashSet::new()))).max(1.0);
            let defender_value = value(catalogue.price_of(&defender_units(&catalogue, &b))).max(1.0);
            let lootable = (b.defender_stock.iter().map(|r| r.max(0.0)).sum::<f64>() * catalogue.rules.loot_percentage / 100.0).max(1.0);
            let defence_lost = |lost: &Units| lost.iter().filter(|(id, _)| catalogue.unit(**id).defense).map(|(_, n)| *n).sum::<i64>() as f64;
            let measures = |rounds: u32, winner: &str, a_loss: [i64; 3], d_loss: [i64; 3], loot: [i64; 3], debris: [i64; 3], moon: i64, repaired: f64, d_lost: f64| {
                let debris_most = (attacker_value + defender_value) * catalogue.rules.debris_ships_percentage.max(catalogue.rules.debris_defense_percentage).max(1.0) / 100.0;
                vec![
                    (winner == "attacker") as u8 as f64,
                    (winner == "defender") as u8 as f64,
                    (winner == "draw") as u8 as f64,
                    rounds as f64 / 6.0,
                    value(a_loss) / attacker_value,
                    value(d_loss) / defender_value,
                    value(loot) / lootable,
                    value(debris) / debris_most,
                    moon as f64 / catalogue.rules.maximum_moon_chance.max(1.0),
                    if d_lost > 0.0 { repaired / d_lost } else { 0.0 },
                ]
            };
            let r = &case.result;
            let host = measures(r.rounds, &r.winner, triple(r.attacker_resource_loss), triple(r.defender_resource_loss), triple(r.loot), triple(r.debris), r.moon_chance, units(&r.repaired).values().sum::<i64>() as f64, defence_lost(&units(&r.defender_lost)));
            let mut mean = vec![0.0; names.len()];
            for k in 0..SEEDS {
                let out = fight(&catalogue, &catalogue, &battle(case, &o, case.seed.wrapping_mul(0x9E37_79B9).wrapping_add(k)));
                let winner = match out.winner {
                    Winner::Attacker => "attacker",
                    Winner::Defender => "defender",
                    Winner::Draw => "draw",
                };
                let sim = measures(out.rounds, winner, out.attacker_loss, out.defender_loss, out.loot, out.debris, out.moon_chance, out.repaired.values().sum::<i64>() as f64, defence_lost(&out.defender_lost));
                for (m, v) in mean.iter_mut().zip(sim) {
                    *m += v / SEEDS as f64;
                }
                fights += 1;
            }
            for (j, d) in diffs.iter_mut().enumerate() {
                d.push(host[j] - mean[j]);
            }
        }
        let per_fight = started.elapsed().as_secs_f64() / fights as f64;
        println!("{} cases x {SEEDS} seeds, {:.1} us per simulated fight", cases.len(), per_fight * 1e6);
        let mut failures = Vec::new();
        for (name, d) in names.iter().zip(&diffs) {
            let n = d.len() as f64;
            let mean = d.iter().sum::<f64>() / n;
            let sd = (d.iter().map(|x| (x - mean).powi(2)).sum::<f64>() / (n - 1.0)).sqrt();
            let se = sd / n.sqrt();
            println!("{name:>20}: PHP - sim = {mean:+.4}  95% CI [{:+.4}, {:+.4}]", mean - 1.96 * se, mean + 1.96 * se);
            if mean.abs() > 3.29 * se + 1e-9 {
                failures.push(*name);
            }
        }
        assert!(failures.is_empty(), "engines disagree on {failures:?}");
    }

    #[test]
    fn loot_is_cut_to_even_thirds_then_refilled() {
        assert_eq!(distribute_loot([100.0, 10.0, 100.0], 120), [55.0, 10.0, 55.0]);
        assert_eq!(distribute_loot([10.0, 20.0, 30.0], 100), [10.0, 20.0, 30.0]);
    }
}
